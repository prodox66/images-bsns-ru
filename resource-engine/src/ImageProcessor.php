<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use RuntimeException;

/** Creates reusable WebP derivatives while preserving every uploaded source file. */
final class ImageProcessor
{
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
        $destination = $collection->derivedPath($resourceId, 'thumbnail');
        if ($this->isCurrent($sourceFile, $destination)) {
            return $destination;
        }

        $sourceImage = $this->decode($sourceFile);
        $thumbnailImage = null;
        $thumbnailOwnsPixels = false;
        try {
            $maximumWidth = $collection->thumbnailMaximumWidth();
            $maximumHeight = $collection->thumbnailMaximumHeight();
            [$thumbnailImage, $thumbnailOwnsPixels] = $this->boundedImage($sourceImage, $maximumWidth, $maximumHeight);
            $this->writeWebp($thumbnailImage, $destination, $collection->thumbnailQuality());
        } finally {
            if ($thumbnailImage !== null && $thumbnailOwnsPixels) {
                imagedestroy($thumbnailImage);
            }
            imagedestroy($sourceImage);
        }
        return $destination;
    }

    /** Creates a non-destructive bounded WebP used by the resource response while the original remains available. */
    public function optimize(ResourceCollection $collection, string $sourceFile, string $resourceId): string
    {
        $destination = $collection->derivedPath($resourceId, 'optimized');
        if ($this->isCurrent($sourceFile, $destination)) {
            return $destination;
        }

        $sourceImage = $this->decode($sourceFile);
        $optimizedImage = null;
        $optimizedOwnsPixels = false;
        try {
            $maximumDimension = $collection->optimizationMaximumDimension();
            [$optimizedImage, $optimizedOwnsPixels] = $this->boundedImage(
                $sourceImage,
                $maximumDimension,
                $maximumDimension
            );
            $this->writeWebp($optimizedImage, $destination, $collection->optimizationQuality());
        } finally {
            if ($optimizedImage !== null && $optimizedOwnsPixels) {
                imagedestroy($optimizedImage);
            }
            imagedestroy($sourceImage);
        }
        return $destination;
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
    private function decode(string $sourceFile)
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            throw new RuntimeException('GD with WebP support is required.');
        }
        $bytes = @file_get_contents($sourceFile);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('This image format cannot be converted by the server.');
        }
        return $image;
    }

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
    private function writeWebp($image, string $destination, int $quality): void
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

    /** Creates one controlled runtime directory if it is still absent. */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create image runtime directory.');
        }
    }
}
