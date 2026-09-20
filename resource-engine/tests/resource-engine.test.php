<?php
declare(strict_types=1);

use Bzn\ResourceEngine\EngineConfiguration;
use Bzn\ResourceEngine\ImageProcessor;
use Bzn\ResourceEngine\ResourceCollection;
use Bzn\ResourceEngine\ResourceEngine;

require_once __DIR__ . '/../src/ResourceCollection.php';
require_once __DIR__ . '/../src/EngineConfiguration.php';
require_once __DIR__ . '/../src/ImageProcessor.php';
require_once __DIR__ . '/../src/ResourceEngine.php';

$temporaryPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bzn-resource-engine-test-';
$temporaryRoot = $temporaryPrefix . bin2hex(random_bytes(8));
$sourceDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'images';
$runtimeDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'runtime';
$sourceFile = $sourceDirectory . DIRECTORY_SEPARATOR . 'fixture.png';

/** Fails one contract assertion with its exact purpose. */
function assertContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Creates one tiny isolated PNG without depending on repository fixtures. */
function createTestImage(string $file, int $red, int $green, int $blue): void
{
    $image = imagecreatetruecolor(24, 12);
    assertContract($image !== false, 'Unable to allocate test image.');
    $color = imagecolorallocate($image, $red, $green, $blue);
    imagefill($image, 0, 0, $color);
    assertContract(imagepng($image, $file), 'Unable to save test image.');
    imagedestroy($image);
}

/** Confirms that unsafe upload names are rejected without emitting PHP warnings. */
function assertInvalidUploadName(ResourceCollection $collection, string $name): void
{
    try {
        $collection->validatedUploadName($name);
    } catch (InvalidArgumentException $error) {
        return;
    }
    throw new RuntimeException('Unsafe upload filename must be rejected: ' . json_encode($name));
}

/** Removes only the unique test root created below. */
function removeTestTree(string $directory, string $allowedPrefix): void
{
    if (!str_starts_with($directory, $allowedPrefix) || !is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        // Loop: every generated child is removed before its exact isolated parent.
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

try {
    assertContract(mkdir($sourceDirectory, 0755, true), 'Unable to create test source directory.');
    assertContract(function_exists('imagecreatetruecolor'), 'GD is required for the resource-engine test.');
    createTestImage($sourceFile, 40, 120, 200);

    $settings = [
        'runtime_directory' => $runtimeDirectory,
        'default_page_size' => 30,
        'maximum_page_size' => 100,
        'operation_batch_size' => 2,
        'operation_time_budget_seconds' => 5,
        'maximum_upload_files' => 10,
        'maximum_upload_bytes' => 1024 * 1024,
        'collections' => [
            'images' => [
                'label' => 'Images',
                'source_directory' => $sourceDirectory,
                'tags' => ['image'],
                'allowed_mime_by_extension' => ['png' => 'image/png'],
                'thumbnail' => ['maximum_width' => 8, 'maximum_height' => 8, 'quality' => 76],
                'optimization' => ['quality' => 84],
            ],
        ],
    ];
    $configuration = new EngineConfiguration($settings);
    $collection = $configuration->collection('images');
    assertContract($collection->validatedUploadName('valid-name.png') === 'valid-name.png', 'Valid upload filename must remain unchanged.');
    assertInvalidUploadName($collection, '../unsafe.png');
    assertInvalidUploadName($collection, 'unsafe\\name.png');
    assertInvalidUploadName($collection, "unsafe\0name.png");

    $engine = new ResourceEngine($configuration, new ImageProcessor());

    $catalog = $engine->rebuild('images');
    assertContract($catalog['total'] === 1, 'Catalog must contain the physical fixture.');
    $resourceId = (string) $catalog['resources'][0]['id'];
    assertContract(strlen($resourceId) === 32, 'Public id must be opaque and stable.');

    $engine->setTags('images', $resourceId, ['Blue', 'Demo']);
    $filtered = $engine->list('images', ['blue']);
    assertContract($filtered['total'] === 1, 'Normalized tag filter must find the resource.');

    $original = $engine->resource('images', $resourceId, 'original');
    assertContract($original['path'] === $sourceFile, 'Original variant must preserve its source.');
    $thumbnail = $engine->resource('images', $resourceId, 'thumbnail');
    assertContract(is_file($thumbnail['path']) && $thumbnail['mime'] === 'image/webp', 'Thumbnail must be stored as WebP.');
    $thumbnailSize = getimagesize($thumbnail['path']);
    assertContract(is_array($thumbnailSize) && $thumbnailSize[0] <= 8 && $thumbnailSize[1] <= 8, 'Thumbnail bounds must be enforced.');

    $optimized = $engine->optimize('images', $resourceId);
    assertContract($optimized['output_bytes'] > 0, 'Optimized WebP must contain data.');
    assertContract(is_file($sourceFile), 'WebP conversion must not delete the source.');

    $deleted = $engine->delete('images', $resourceId);
    assertContract($deleted['deleted'] === 'fixture.png', 'Deletion must report the catalog-owned filename.');
    assertContract(!is_file($sourceFile), 'Deleted test source must leave the collection.');
    assertContract($engine->list('images')['total'] === 0, 'Catalog must be rebuilt after deletion.');

    // Operation: a batch may attempt only its configured slice and reports remaining work explicitly.
    $batchNames = ['batch-a.png', 'batch-b.png', 'batch-c.png'];
    $batchBaseModified = time() - 300;
    $batchModifiedStep = 60;
    foreach ($batchNames as $index => $batchName) {
        $batchFile = $sourceDirectory . DIRECTORY_SEPARATOR . $batchName;
        createTestImage($batchFile, 40 + $index, 100, 180);
        assertContract(touch($batchFile, $batchBaseModified + ($index * $batchModifiedStep)), 'Unable to set deterministic test modification time.');
    }
    $batchCatalog = $engine->rebuild('images');
    $expectedNewestFirst = array_reverse($batchNames);
    assertContract(array_column($batchCatalog['resources'], 'name') === $expectedNewestFirst, 'Catalog must return newest resources first.');
    assertContract(array_column($engine->list('images')['items'], 'name') === $expectedNewestFirst, 'Paginated lists must preserve newest-first catalog order.');
    $firstBatch = $engine->optimizeBatch('images');
    assertContract($firstBatch['attempted'] === 2, 'First batch must respect its attempt limit.');
    assertContract(count($firstBatch['processed']) === 2, 'First batch must process only its bounded slice.');
    assertContract($firstBatch['remaining'] === 1, 'First batch must report one remaining derivative.');
    $secondBatch = $engine->optimizeBatch('images');
    assertContract(count($secondBatch['processed']) === 1 && $secondBatch['remaining'] === 0, 'Second batch must finish the remaining derivative.');

    echo "Resource engine contract: OK\n";
} finally {
    removeTestTree($temporaryRoot, $temporaryPrefix);
}
