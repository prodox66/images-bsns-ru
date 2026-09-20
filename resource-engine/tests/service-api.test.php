<?php
// Contract test: a protected logical service resolves static/demo and opaque user templates through Resource Engine.
declare(strict_types=1);

use Bzn\ResourceEngine\ServiceAuthenticator;
use Bzn\ResourceEngine\ServiceResourceConfiguration;
use Bzn\ResourceEngine\ServiceResourceGateway;

require_once __DIR__ . '/../src/ResourceCollection.php';
require_once __DIR__ . '/../src/EngineConfiguration.php';
require_once __DIR__ . '/../src/ImageProcessor.php';
require_once __DIR__ . '/../src/ResourceEngine.php';
require_once __DIR__ . '/../src/ServiceApi.php';

const TEST_SERVICE_API_CYCLES = 3;
const TEST_SERVICE_KEY = 'service-resource-test-key';
const TEST_SERVICE_OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const TEST_DEMO_FILE = 'demo_prj.png';
const TEST_IGNORED_FILE = 'ordinary.png';
const TEST_USER_FILE = 'personal_prj.png';

// Test helper: every failed service condition identifies its contract boundary.
function assertServiceContract(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// Function fixture: template thumbnails use actual PNG pixels and exercise the production image processor.
function createServiceTestImage(string $file, int $red, int $green, int $blue): void
{
    $image = imagecreatetruecolor(24, 16);
    assertServiceContract($image !== false, 'Unable to allocate service test image.');
    $color = imagecolorallocate($image, $red, $green, $blue);
    imagefill($image, 0, 0, $color);
    assertServiceContract(imagepng($image, $file), 'Unable to write service test image.');
    imagedestroy($image);
}

// Function fixture: only a unique temporary root created by this test may be removed.
function removeServiceTestTree(string $directory, string $allowedPrefix): void
{
    if (!str_starts_with($directory, $allowedPrefix) || !is_dir($directory)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        // Loop: generated descendants are removed before their verified test root.
        if ($entry->isDir()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($directory);
}

// Function fixture: physical roots remain deployment data while logical callers keep one fixed contract.
function serviceTestSettings(string $root): array
{
    return [
        'service_key' => TEST_SERVICE_KEY,
        'service_key_header' => 'HTTP_X_BZN_SERVICE_KEY',
        'allowed_methods' => ['GET'],
        'runtime_directory' => $root . DIRECTORY_SEPARATOR . 'runtime',
        'default_page_size' => 30,
        'maximum_page_size' => 100,
        'owner_pattern' => '/^[a-f0-9]{64}$/D',
        'scopes' => ['demo' => 'demo', 'user' => 'user'],
        'resources' => ['templates' => [
            'demo_source_directory' => $root . DIRECTORY_SEPARATOR . 'demo',
            'user_root_directory' => $root . DIRECTORY_SEPARATOR . 'users',
            'user_subdirectory' => 'templates',
            'create_user_directory' => true,
            'user_directory_mode' => 0750,
            'collection' => [
                'label' => 'Templates',
                'name_pattern' => '/^[\p{L}\p{N}][\p{L}\p{N}._-]{0,159}_prj\.png$/iuD',
                'allowed_mime_by_extension' => ['png' => 'image/png'],
                'tags' => ['template'],
                'thumbnail' => ['maximum_width' => 12, 'maximum_height' => 12, 'quality' => 76],
                'optimization' => ['quality' => 84],
            ],
            'allowed_kinds' => ['original', 'thumbnail'],
            'format' => 'png',
            'rebuild_on_list' => true,
            'demo_cache_control' => 'public, max-age=3600',
            'user_cache_control' => 'private, no-store, max-age=0',
        ]],
    ];
}

$temporaryPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bzn-service-api-test-';
assertServiceContract(function_exists('imagecreatetruecolor'), 'GD is required for the service API test.');

// Cycle: auth, filtering, opaque user namespace and thumbnail derivation remain isolated across fresh roots.
for ($cycleIndex = 0; $cycleIndex < TEST_SERVICE_API_CYCLES; $cycleIndex++) {
    $temporaryRoot = $temporaryPrefix . bin2hex(random_bytes(8));
    try {
        $demoDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'demo';
        assertServiceContract(mkdir($demoDirectory, 0755, true), 'Unable to create demo template directory.');
        createServiceTestImage($demoDirectory . DIRECTORY_SEPARATOR . TEST_DEMO_FILE, 40, 120, 200);
        createServiceTestImage($demoDirectory . DIRECTORY_SEPARATOR . TEST_IGNORED_FILE, 200, 120, 40);

        $configuration = new ServiceResourceConfiguration(serviceTestSettings($temporaryRoot));
        $gateway = new ServiceResourceGateway();
        assertServiceContract((new ServiceAuthenticator($configuration->serviceKey()))->authorized(TEST_SERVICE_KEY), 'Valid service key was rejected.');
        assertServiceContract(!(new ServiceAuthenticator($configuration->serviceKey()))->authorized('wrong-key'), 'Invalid service key was accepted.');

        $demoContext = $configuration->context('templates', 'demo', '');
        $demoList = $gateway->list($demoContext);
        assertServiceContract($demoList['total'] === 1, 'Filename policy did not isolate portable project PNGs.');
        assertServiceContract($demoList['items'][0]['name'] === TEST_DEMO_FILE, 'Demo template was not returned.');
        assertServiceContract($demoList['items'][0]['type'] === 'templates' && $demoList['items'][0]['scope'] === 'demo', 'Internal collection identity leaked into demo output.');
        assertServiceContract(!str_contains(json_encode($demoList, JSON_THROW_ON_ERROR), $temporaryRoot), 'Demo list exposed a physical path.');
        $demoThumbnail = $gateway->resource($demoContext, (string) $demoList['items'][0]['id'], 'thumbnail');
        assertServiceContract(is_file($demoThumbnail['path']) && $demoThumbnail['mime'] === 'image/webp', 'Demo thumbnail was not generated by Resource Engine.');

        $userContext = $configuration->context('templates', 'user', TEST_SERVICE_OWNER);
        $userDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . TEST_SERVICE_OWNER . DIRECTORY_SEPARATOR . 'templates';
        assertServiceContract(is_dir($userDirectory), 'Opaque user directory was not created.');
        createServiceTestImage($userDirectory . DIRECTORY_SEPARATOR . TEST_USER_FILE, 80, 180, 90);
        $userList = $gateway->list($userContext);
        assertServiceContract($userList['total'] === 1 && $userList['items'][0]['name'] === TEST_USER_FILE, 'User template list is incorrect.');
        assertServiceContract($userList['items'][0]['writable'] === true, 'User resource was not marked writable.');
        assertServiceContract($userContext->cacheControl === 'private, no-store, max-age=0', 'User cache policy is not private.');
        assertServiceContract(!str_contains(json_encode($userList, JSON_THROW_ON_ERROR), TEST_SERVICE_OWNER), 'Opaque owner leaked into the list response.');

        try {
            $configuration->context('templates', 'user', 'member@example.test');
            throw new RuntimeException('Plain email owner was accepted.');
        } catch (InvalidArgumentException $error) {
            assertServiceContract($error->getMessage() === 'Invalid service resource owner.', 'Unexpected invalid-owner failure.');
        }
        try {
            $gateway->resource($userContext, (string) $userList['items'][0]['id'], 'optimized');
            throw new RuntimeException('Disallowed template variant was accepted.');
        } catch (InvalidArgumentException $error) {
            assertServiceContract($error->getMessage() === 'Unknown service resource kind.', 'Unexpected invalid-kind failure.');
        }
    } finally {
        removeServiceTestTree($temporaryRoot, $temporaryPrefix);
    }
}

echo 'Service Resource API contract passed (' . TEST_SERVICE_API_CYCLES . " cycles).\n";
