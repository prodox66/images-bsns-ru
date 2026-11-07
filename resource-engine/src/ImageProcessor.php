<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/ImageTagStore.php';

/** Creates reusable WebP derivatives while preserving every uploaded source file. */
final class ImageProcessor
{
    private const DERIVATIVE = ['thumbnail' => 'thumbnail', 'optimized' => 'optimized',
        'empty' => '', 'changed' => 'Image changed before derivative publication.'];
    private ?ImageTags $tagCodec = null;
    /** Returns lightweight dimensions without decoding pixels when the format supports it. */
    public function inspect(string $sourceFile): array
    {
        $size = @getimagesize($sourceFile);
        return [
            'width' => is_array($size) ? max(0, (int) ($size[0] ?? 0)) : 0,
            'height' => is_array($size) ? max(0, (int) ($size[1] ?? 0)) : 0,
        ];
    }

    /** Builds or refreshes one bounded thumbnail in the collection's private runtime storage. */
    public function thumbnail(ResourceCollection $collection, string $sourceFile, string $resourceId): string
    {
        return $this->derive($collection, $sourceFile, $collection->derivedPath($resourceId, self::DERIVATIVE['thumbnail']),
            $collection->thumbnailMaximumWidth(), $collection->thumbnailMaximumHeight(), $collection->thumbnailQuality());
    }

    /** Creates a non-destructive bounded WebP used by the resource response while the original remains available. */
    public function optimize(ResourceCollection $collection, string $sourceFile, string $resourceId): string
    {
        $side = $collection->optimizationMaximumDimension();
        return $this->derive($collection, $sourceFile, $collection->derivedPath($resourceId, self::DERIVATIVE['optimized']),
            $side, $side, $collection->optimizationQuality());
    }

    /** Cold generation shares the source mutation lock; already-current cache hits stay read-only. */
    private function derive(ResourceCollection $collection, string $sourceFile, string $destination,
        int $maximumWidth, int $maximumHeight, int $quality): string
    {
        if ($this->isCurrent($sourceFile, $destination)) return $destination;
        return $collection->withMutationLock(function () use ($sourceFile, $destination, $maximumWidth, $maximumHeight, $quality): string {
            // Branch: a prior waiting request may have finished the same file before this lock was acquired.
            if ($this->isCurrent($sourceFile, $destination)) return $destination;
            $revision = ImageTagTransaction::revision($sourceFile);
            if ($revision === self::DERIVATIVE['empty']) throw new RuntimeException(self::DERIVATIVE['changed']);
            [$sourceImage, $tags] = $this->decode($sourceFile);
            $derivedImage = null; $ownsPixels = false;
            try {
                [$derivedImage, $ownsPixels] = $this->boundedImage($sourceImage, $maximumWidth, $maximumHeight);
                $this->writeWebp($derivedImage, $destination, $quality, $tags, $sourceFile, $revision);
            } finally {
                // Completed boundary: an unchanged source-sized image still has only one GD owner.
                if ($derivedImage !== null && $ownsPixels) imagedestroy($derivedImage);
                imagedestroy($sourceImage);
            }
            return $destination;
        });
    }

    /** Reports whether a derived file is at least as new as its preserved source. */
    public function isCurrent(string $sourceFile, string $derivedFile): bool
    {
        if (!is_file($sourceFile) || !is_file($derivedFile)) {
            return false;
        }
        return (int) filemtime($derivedFile) >= (int) filemtime($sourceFile);
    }

    /** Decodes only formats supported by the active GD build. */
    private function decode(string $sourceFile): array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            throw new RuntimeException('GD with WebP support is required.');
        }
        $bytes = @file_get_contents($sourceFile);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('This image format cannot be converted by the server.');
        }
        $tags = null;
        try { $tags = $this->tags()->read($bytes); }
        catch (Throwable) { /* Unsupported or malformed optional metadata does not interrupt ordinary pixel conversion. */ }
        return [$image, $tags];
    }

    /** A single codec serves the already loaded source and the temporary derivative. */
    private function tags(): ImageTags { return $this->tagCodec ??= new ImageTags(); }

    /** Allocates one alpha-safe canvas used by both ordinary images and masks. */
    private function transparentCanvas(int $width, int $height)
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('Unable to allocate thumbnail canvas.');
        }
        imagealphablending($image, false);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
        imagesavealpha($image, true);
        return $image;
    }

    /** Returns the source unchanged when it fits, or one alpha-safe proportional resample owned by the caller. */
    private function boundedImage($sourceImage, int $maximumWidth, int $maximumHeight): array
    {
        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        $scale = min($maximumWidth / $sourceWidth, $maximumHeight / $sourceHeight, 1);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        if ($targetWidth === $sourceWidth && $targetHeight === $sourceHeight) {
            return [$sourceImage, false];
        }

        $targetImage = $this->transparentCanvas($targetWidth, $targetHeight);
        // Operation: proportional resampling preserves the full image and its alpha channel without cropping.
        if (!imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        )) {
            imagedestroy($targetImage);
            throw new RuntimeException('Unable to resample image.');
        }
        return [$targetImage, true];
    }

    /** Writes a WebP through a temporary sibling and an atomic rename. */
    private function writeWebp($image, string $destination, int $quality, ?array $tags, string $source, string $revision): void
    {
        $directory = dirname($destination);
        $this->ensureDirectory($directory);
        $temporaryFile = tempnam($directory, '.webp-');
        if ($temporaryFile === false) {
            throw new RuntimeException('Unable to create a temporary WebP file.');
        }

        try {
            if (!imagewebp($image, $temporaryFile, $quality)) {
                throw new RuntimeException('Unable to encode WebP.');
            }
            // Branch: known-empty tags are also embedded before the derivative can be published.
            if ($tags !== null) $this->writeWebpTags($temporaryFile, $tags);
            if (ImageTagTransaction::revision($source) !== $revision) throw new RuntimeException(self::DERIVATIVE['changed']);
            @chmod($temporaryFile, 0644);
            if (!@rename($temporaryFile, $destination)) {
                throw new RuntimeException('Unable to install generated WebP.');
            }
            $temporaryFile = '';
        } finally {
            if ($temporaryFile !== '' && is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    /** Only the unexposed temporary WebP is rewritten; its already encoded pixels are untouched. */
    private function writeWebpTags(string $temporaryFile, array $tags): void
    {
        $bytes = @file_get_contents($temporaryFile);
        if (!is_string($bytes)) throw new RuntimeException('Unable to read staged WebP.');
        $tagged = $this->tags()->embed($bytes, $tags);
        if (file_put_contents($temporaryFile, $tagged, LOCK_EX) !== strlen($tagged)) {
            throw new RuntimeException('Unable to store derivative tags.');
        }
    }

    /** Creates one controlled runtime directory if it is still absent. */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create image runtime directory.');
        }
    }
}
