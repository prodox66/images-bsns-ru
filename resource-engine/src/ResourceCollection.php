<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use InvalidArgumentException;

/** Owns every filesystem and image-processing rule for one logical resource type. */
final class ResourceCollection
{
    private string $id;
    private string $label;
    private string $sourceDirectory;
    private string $runtimeDirectory;
    private array $mimeByExtension;
    private array $defaultTags;
    private ?string $namePattern;
    private int $thumbnailMaximumWidth;
    private int $thumbnailMaximumHeight;
    private int $thumbnailQuality;
    private int $optimizationQuality;

    /** Normalizes one collection configuration before it reaches filesystem code. */
    public function __construct(string $id, array $settings, string $runtimeRoot)
    {
        $normalizedId = trim($id);
        if (!preg_match('/^[a-z0-9_-]+$/i', $normalizedId)) {
            throw new InvalidArgumentException('Invalid resource collection id.');
        }

        $this->id = $normalizedId;
        $this->label = trim((string) ($settings['label'] ?? $normalizedId));
        $this->sourceDirectory = rtrim((string) ($settings['source_directory'] ?? ''), '/\\');
        $this->runtimeDirectory = rtrim($runtimeRoot, '/\\') . DIRECTORY_SEPARATOR . $normalizedId;
        $this->mimeByExtension = (array) ($settings['allowed_mime_by_extension'] ?? []);
        $this->defaultTags = $this->normalizeTags((array) ($settings['tags'] ?? []));
        $configuredNamePattern = trim((string) ($settings['name_pattern'] ?? ''));
        if ($configuredNamePattern !== '' && @preg_match($configuredNamePattern, '') === false) {
            throw new InvalidArgumentException('Invalid resource filename pattern.');
        }
        $this->namePattern = $configuredNamePattern !== '' ? $configuredNamePattern : null;

        $thumbnail = (array) ($settings['thumbnail'] ?? []);
        $optimization = (array) ($settings['optimization'] ?? []);
        $this->thumbnailMaximumWidth = max(1, (int) ($thumbnail['maximum_width'] ?? 360));
        $this->thumbnailMaximumHeight = max(1, (int) ($thumbnail['maximum_height'] ?? 360));
        $this->thumbnailQuality = $this->quality((int) ($thumbnail['quality'] ?? 76));
        $this->optimizationQuality = $this->quality((int) ($optimization['quality'] ?? 84));
    }

    public function id(): string { return $this->id; }
    public function label(): string { return $this->label; }
    public function sourceDirectory(): string { return $this->sourceDirectory; }
    public function runtimeDirectory(): string { return $this->runtimeDirectory; }
    public function catalogFile(): string { return $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'catalog.json'; }
    public function catalogScriptFile(): string { return $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'catalog.js'; }
    public function metadataFile(): string { return $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'metadata.json'; }
    public function lockFile(): string { return $this->runtimeDirectory . DIRECTORY_SEPARATOR . '.engine.lock'; }
    public function thumbnailDirectory(): string { return $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'thumbnails'; }
    public function optimizedDirectory(): string { return $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'optimized'; }
    public function trashDirectory(): string { return $this->runtimeDirectory . DIRECTORY_SEPARATOR . 'trash'; }
    public function defaultTags(): array { return $this->defaultTags; }
    public function thumbnailMaximumWidth(): int { return $this->thumbnailMaximumWidth; }
    public function thumbnailMaximumHeight(): int { return $this->thumbnailMaximumHeight; }
    public function thumbnailQuality(): int { return $this->thumbnailQuality; }
    public function optimizationQuality(): int { return $this->optimizationQuality; }

    /** Returns the configured MIME only for an explicitly supported extension. */
    public function mimeForName(string $name): ?string
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $mime = $this->mimeByExtension[$extension] ?? null;
        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    /** Reports whether a physical source file belongs to this collection. */
    public function supportsName(string $name): bool
    {
        if ($this->mimeForName($name) === null) return false;
        return $this->namePattern === null || preg_match($this->namePattern, $name) === 1;
    }

    /** Validates one browser filename before it can become a collection-owned source. */
    public function validatedUploadName(string $candidate): string
    {
        $name = trim($candidate);
        $controlCharacterState = preg_match('/[\x00-\x1F\x7F]/u', $name);
        $containsPathSeparator = str_contains($name, '/') || str_contains($name, '\\');
        if (
            $name === ''
            || $controlCharacterState !== 0
            || $containsPathSeparator
            || basename($name) !== $name
            || !$this->supportsName($name)
        ) {
            throw new InvalidArgumentException('Unsupported upload filename.');
        }
        return $name;
    }

    /** Produces a stable id without exposing a filename or storage path to callers. */
    public function resourceId(string $name): string
    {
        $separator = "\0";
        $hashLength = 32;
        return substr(hash('sha256', $this->id . $separator . $name), 0, $hashLength);
    }

    /** Maps one stable id to its isolated derived WebP paths. */
    public function derivedPath(string $id, string $variant): string
    {
        $safeIdPattern = '/^[a-f0-9]{32}$/';
        $supportedVariants = ['thumbnail', 'optimized'];
        if (!preg_match($safeIdPattern, $id)) {
            throw new InvalidArgumentException('Invalid resource id.');
        }
        if (!in_array($variant, $supportedVariants, true)) {
            throw new InvalidArgumentException('Invalid derived resource variant.');
        }

        $directory = $variant === 'thumbnail' ? $this->thumbnailDirectory() : $this->optimizedDirectory();
        return $directory . DIRECTORY_SEPARATOR . $id . '.webp';
    }

    /** Normalizes searchable tags into a deterministic lowercase set. */
    public function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            // Loop: one canonical representation keeps filters independent of input spelling and duplicates.
            $rawValue = trim((string) $tag);
            $value = function_exists('mb_strtolower') ? mb_strtolower($rawValue, 'UTF-8') : strtolower($rawValue);
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }
        $result = array_keys($normalized);
        sort($result, SORT_NATURAL | SORT_FLAG_CASE);
        return $result;
    }

    /** Bounds WebP quality before a value reaches GD. */
    private function quality(int $quality): int
    {
        $minimumQuality = 1;
        $maximumQuality = 100;
        return max($minimumQuality, min($maximumQuality, $quality));
    }
}
