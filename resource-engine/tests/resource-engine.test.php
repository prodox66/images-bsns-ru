<?php
declare(strict_types=1);

use Bzn\ResourceEngine\EngineConfiguration;
use Bzn\ResourceEngine\ImageProcessor;
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
    $fixture = imagecreatetruecolor(24, 12);
    assertContract($fixture !== false, 'Unable to allocate test image.');
    $color = imagecolorallocate($fixture, 40, 120, 200);
    imagefill($fixture, 0, 0, $color);
    assertContract(imagepng($fixture, $sourceFile), 'Unable to save test image.');
    imagedestroy($fixture);

    $settings = [
        'runtime_directory' => $runtimeDirectory,
        'default_page_size' => 30,
        'maximum_page_size' => 100,
        'operation_batch_size' => 20,
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
    $engine = new ResourceEngine(new EngineConfiguration($settings), new ImageProcessor());

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

    echo "Resource engine contract: OK\n";
} finally {
    removeTestTree($temporaryRoot, $temporaryPrefix);
}
