<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use DirectoryIterator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Owns catalog, metadata, upload and derivative operations behind one resource contract. */
final class ResourceEngine
{
    private const RENAME_DERIVATIVE_VARIANTS = ['thumbnail', 'optimized'];
    private EngineConfiguration $configuration;
    private ImageProcessor $imageProcessor;

    public function __construct(EngineConfiguration $configuration, ImageProcessor $imageProcessor)
    {
        $this->configuration = $configuration;
        $this->imageProcessor = $imageProcessor;
    }

    public function configuration(): EngineConfiguration { return $this->configuration; }

    /** Rebuilds one complete catalog from physical source files and stored tags. */
    public function rebuild(string $type): array
    {
        $collection = $this->configuration->collection($type);
        return $this->withLock($collection, fn (): array => $this->rebuildUnlocked($collection));
    }

    /** Returns a filtered page without revealing physical paths or storage rules. */
    public function list(string $type, array $tags = [], int $page = 1, int $pageSize = 0): array
    {
        $collection = $this->configuration->collection($type);
        $catalog = $this->loadCatalog($collection);
        $requestedTags = $collection->normalizeTags($tags);
        $records = array_values(array_filter((array) ($catalog['resources'] ?? []), static function (array $record) use ($requestedTags): bool {
            if (!$requestedTags) {
                return true;
            }
            $recordTags = array_fill_keys((array) ($record['tags'] ?? []), true);
            foreach ($requestedTags as $tag) {
                // Branch: the public filter contract requires every requested tag.
                if (!isset($recordTags[$tag])) {
                    return false;
                }
            }
            return true;
        }));

        $effectivePageSize = $pageSize > 0 ? $pageSize : $this->configuration->defaultPageSize();
        $effectivePageSize = min($effectivePageSize, $this->configuration->maximumPageSize());
        $total = count($records);
        $pages = max(1, (int) ceil($total / $effectivePageSize));
        $effectivePage = max(1, min($pages, $page));
        $offset = ($effectivePage - 1) * $effectivePageSize;

        return [
            'ok' => true,
            'type' => $collection->id(),
            'page' => $effectivePage,
            'page_size' => $effectivePageSize,
            'pages' => $pages,
            'total' => $total,
            'items' => array_slice($records, $offset, $effectivePageSize),
        ];
    }

    /** Resolves one opaque id to a stream descriptor for the requested variant. */
    public function resource(string $type, string $resourceId, string $variant): array
    {
        $collection = $this->configuration->collection($type);
        $record = $this->record($collection, $resourceId);
        $sourceFile = $this->sourceFile($collection, (string) $record['name']);
        $selectedFile = $sourceFile;
        $selectedMime = (string) $record['mime'];
        $downloadName = (string) $record['name'];

        if ($variant === 'thumbnail') {
            try {
                $selectedFile = $this->imageProcessor->thumbnail($collection, $sourceFile, $resourceId);
                $selectedMime = 'image/webp';
                $downloadName = $resourceId . '-thumbnail.webp';
            } catch (Throwable $error) {
                // Branch: unsupported SVG/AVIF still returns a valid resource while ordinary formats use stored thumbnails.
                $selectedFile = $sourceFile;
            }
        } elseif ($variant === 'optimized' || $variant === 'resource') {
            $optimizedFile = $collection->derivedPath($resourceId, 'optimized');
            if ($this->imageProcessor->isCurrent($sourceFile, $optimizedFile)) {
                $selectedFile = $optimizedFile;
                $selectedMime = 'image/webp';
                $downloadName = $resourceId . '.webp';
            }
        } elseif ($variant !== 'original') {
            throw new InvalidArgumentException('Unknown resource variant.');
        }

        if (!is_file($selectedFile)) {
            throw new RuntimeException('Resource file is unavailable.');
        }
        return [
            'path' => $selectedFile,
            'mime' => $selectedMime,
            'name' => $downloadName,
            'modified' => (int) filemtime($selectedFile),
            'bytes' => (int) filesize($selectedFile),
        ];
    }

    /** Saves canonical tags and regenerates the public catalog atomically. */
    public function setTags(string $type, string $resourceId, array $tags): array
    {
        $collection = $this->configuration->collection($type);
        $this->loadCatalog($collection);
        return $this->withLock($collection, function () use ($collection, $resourceId, $tags): array {
            $catalog = $this->readJson($collection->catalogFile());
            $this->recordFromCatalog($catalog, $resourceId);
            $metadata = $this->loadMetadata($collection);
            $metadata[$resourceId] = ['tags' => $collection->normalizeTags($tags)];
            $this->writeJson($collection->metadataFile(), $metadata);
            return $this->rebuildUnlocked($collection);
        });
    }

    /** Creates a non-destructive optimized WebP for one opaque resource id. */
    public function optimize(string $type, string $resourceId): array
    {
        $collection = $this->configuration->collection($type);
        $this->loadCatalog($collection);
        return $this->withLock($collection, function () use ($collection, $resourceId): array {
            $catalog = $this->readJson($collection->catalogFile());
            $record = $this->recordFromCatalog($catalog, $resourceId);
            $sourceFile = $this->sourceFile($collection, (string) $record['name']);
            $outputFile = $this->imageProcessor->optimize($collection, $sourceFile, $resourceId);
            $catalog = $this->rebuildUnlocked($collection);
            return ['catalog' => $catalog, 'output_bytes' => (int) filesize($outputFile), 'source_bytes' => (int) filesize($sourceFile)];
        });
    }

    /** Generates a bounded batch of missing thumbnails without starting an unbounded server job. */
    public function buildThumbnailBatch(string $type): array
    {
        return $this->buildDerivativeBatch($type, 'thumbnail');
    }

    /** Generates a bounded batch of missing optimized WebP files after an explicit admin action. */
    public function optimizeBatch(string $type): array
    {
        return $this->buildDerivativeBatch($type, 'optimized');
    }

    /** Moves validated browser uploads into the selected source collection and rebuilds its catalog. */
    public function upload(string $type, array $uploads, array $tags = []): array
    {
        $collection = $this->configuration->collection($type);
        return $this->withLock($collection, function () use ($collection, $uploads, $tags): array {
            $normalizedUploads = $this->normalizeUploads($uploads);
            if (!$normalizedUploads || count($normalizedUploads) > $this->configuration->maximumUploadFiles()) {
                throw new RuntimeException('Invalid upload file count.');
            }
            $storedFiles = [];
            $metadataBeforeUpload = $this->loadMetadata($collection);
            $metadataWasWritten = false;
            try {
                foreach ($normalizedUploads as $upload) {
                    // Loop: every uploaded file passes size, name, extension and MIME checks before it enters the source folder.
                    $storedFiles[] = $this->storeUpload($collection, $upload);
                }
                $normalizedTags = $collection->normalizeTags($tags);
                if ($normalizedTags) {
                    $metadata = $metadataBeforeUpload;
                    foreach ($storedFiles as $storedFile) {
                        // Loop: a shared upload tag set is recorded once per new opaque resource id.
                        $resourceId = $collection->resourceId(basename($storedFile));
                        $metadata[$resourceId] = ['tags' => $normalizedTags];
                    }
                    $this->writeJson($collection->metadataFile(), $metadata);
                    $metadataWasWritten = true;
                }
                $catalog = $this->rebuildUnlocked($collection);
                return ['uploaded' => array_map('basename', $storedFiles), 'catalog' => $catalog];
            } catch (Throwable $error) {
                foreach ($storedFiles as $storedFile) {
                    @unlink($storedFile);
                }
                if ($metadataWasWritten) {
                    $this->writeJson($collection->metadataFile(), $metadataBeforeUpload);
                }
                throw $error;
            }
        });
    }

    /** Renames one catalog-owned resource while preserving its tags and generated derivatives. */
    public function rename(string $type, string $resourceId, string $requestedName): array
    {
        $collection = $this->configuration->collection($type);
        $this->loadCatalog($collection);
        return $this->withLock($collection, function () use ($collection, $resourceId, $requestedName): array {
            $catalog = $this->readJson($collection->catalogFile());
            $record = $this->recordFromCatalog($catalog, $resourceId);
            $oldName = (string) $record['name'];
            $newName = $collection->validatedUploadName($requestedName);
            $oldExtension = strtolower((string) pathinfo($oldName, PATHINFO_EXTENSION));
            $newExtension = strtolower((string) pathinfo($newName, PATHINFO_EXTENSION));
            if ($oldExtension !== $newExtension) {
                throw new InvalidArgumentException('Resource extension cannot change during rename.');
            }
            if ($oldName === $newName) {
                return ['renamed' => $newName, 'id' => $resourceId, 'catalog' => $catalog];
            }

            $newId = $collection->resourceId($newName);
            $moves = [[$this->sourceFile($collection, $oldName), $this->sourceFile($collection, $newName)]];
            if (!is_file($moves[0][0])) {
                throw new RuntimeException('Resource source is unavailable.');
            }
            foreach (self::RENAME_DERIVATIVE_VARIANTS as $variant) {
                // Loop: retained thumbnails and optimized files follow the new opaque id.
                $moves[] = [$collection->derivedPath($resourceId, $variant), $collection->derivedPath($newId, $variant)];
            }
            $metadataBefore = $this->loadMetadata($collection);
            $metadataAfter = $metadataBefore;
            if (array_key_exists($resourceId, $metadataAfter)) {
                $metadataAfter[$newId] = $metadataAfter[$resourceId];
                unset($metadataAfter[$resourceId]);
            }
            $completedMoves = [];
            $metadataWritten = false;
            $rebuildStarted = false;
            try {
                foreach ($moves as [$source, $destination]) {
                    // Branch: absent optional derivatives are skipped; existing targets never get overwritten.
                    if (!is_file($source)) continue;
                    if (file_exists($destination) || !@rename($source, $destination)) {
                        throw new RuntimeException('Unable to rename resource without overwriting a file.');
                    }
                    $completedMoves[] = [$source, $destination];
                }
                if ($metadataAfter !== $metadataBefore) {
                    $this->writeJson($collection->metadataFile(), $metadataAfter);
                    $metadataWritten = true;
                }
                $rebuildStarted = true;
                $updatedCatalog = $this->rebuildUnlocked($collection);
                return ['renamed' => $newName, 'id' => $newId, 'catalog' => $updatedCatalog];
            } catch (Throwable $error) {
                $recoveryFailed = false;
                foreach (array_reverse($completedMoves) as [$source, $destination]) {
                    // Loop: only this operation's completed moves are reversed after a failure.
                    if (!@rename($destination, $source)) $recoveryFailed = true;
                }
                if ($metadataWritten) {
                    try { $this->writeJson($collection->metadataFile(), $metadataBefore); }
                    catch (Throwable $recoveryError) { $recoveryFailed = true; }
                }
                if ($rebuildStarted && !$recoveryFailed) {
                    try { $this->rebuildUnlocked($collection); }
                    catch (Throwable $recoveryError) { $recoveryFailed = true; }
                }
                if ($recoveryFailed) throw new RuntimeException('Resource rename recovery failed.', 0, $error);
                throw $error;
            }
        });
    }

    /** Removes one source transactionally while keeping the old file available if catalog rebuilding fails. */
    public function delete(string $type, string $resourceId): array
    {
        $collection = $this->configuration->collection($type);
        $this->loadCatalog($collection);
        return $this->withLock($collection, function () use ($collection, $resourceId): array {
            $catalog = $this->readJson($collection->catalogFile());
            $record = $this->recordFromCatalog($catalog, $resourceId);
            $sourceFile = $this->sourceFile($collection, (string) $record['name']);
            $this->ensureDirectory($collection->trashDirectory());
            $trashFile = $collection->trashDirectory() . DIRECTORY_SEPARATOR . $resourceId . '-' . basename($sourceFile);
            if (!@rename($sourceFile, $trashFile)) {
                throw new RuntimeException('Unable to protect the resource before deletion.');
            }

            try {
                $catalog = $this->rebuildUnlocked($collection);
            } catch (Throwable $error) {
                @rename($trashFile, $sourceFile);
                throw $error;
            }

            @unlink($trashFile);
            @unlink($collection->derivedPath($resourceId, 'thumbnail'));
            @unlink($collection->derivedPath($resourceId, 'optimized'));
            return ['deleted' => (string) $record['name'], 'catalog' => $catalog];
        });
    }

    /** Processes only one configured batch and reports unsupported resources instead of hiding them. */
    private function buildDerivativeBatch(string $type, string $variant): array
    {
        $collection = $this->configuration->collection($type);
        $this->loadCatalog($collection);
        return $this->withLock($collection, function () use ($collection, $variant): array {
            $catalog = $this->readJson($collection->catalogFile());
            $limit = $this->configuration->operationBatchSize();
            $deadline = microtime(true) + $this->configuration->operationTimeBudgetSeconds();
            $attempted = 0;
            $processed = [];
            $errors = [];
            foreach ((array) ($catalog['resources'] ?? []) as $record) {
                $resourceId = (string) $record['id'];
                $sourceFile = $this->sourceFile($collection, (string) $record['name']);
                $derivedFile = $collection->derivedPath($resourceId, $variant === 'thumbnail' ? 'thumbnail' : 'optimized');
                if ($this->imageProcessor->isCurrent($sourceFile, $derivedFile)) {
                    continue;
                }
                // Branch: attempts, not only successes, are bounded so unsupported files cannot extend the request.
                if ($attempted >= $limit || microtime(true) >= $deadline) {
                    break;
                }
                $attempted++;
                try {
                    if ($variant === 'thumbnail') {
                        $this->imageProcessor->thumbnail($collection, $sourceFile, $resourceId);
                    } else {
                        $this->imageProcessor->optimize($collection, $sourceFile, $resourceId);
                    }
                    $processed[] = $resourceId;
                } catch (Throwable $error) {
                    $errors[$resourceId] = $error->getMessage();
                }
            }
            $rebuilt = $this->rebuildUnlocked($collection);
            $stateField = $variant === 'thumbnail' ? 'has_thumbnail' : 'has_optimized';
            $remaining = count(array_filter(
                (array) ($rebuilt['resources'] ?? []),
                static fn (array $record): bool => ($record[$stateField] ?? false) !== true
            ));
            return [
                'attempted' => $attempted,
                'processed' => $processed,
                'errors' => $errors,
                'remaining' => $remaining,
                'catalog' => $rebuilt,
            ];
        });
    }

    /** Scans physical files and installs JSON plus classic-script catalogs in one lock boundary. */
    private function rebuildUnlocked(ResourceCollection $collection): array
    {
        $this->assertSourceDirectory($collection);
        $this->ensureRuntimeDirectories($collection);
        $metadata = $this->loadMetadata($collection);
        $resources = [];
        foreach (new DirectoryIterator($collection->sourceDirectory()) as $entry) {
            // Loop: generated folders and unsupported files never enter the public resource contract.
            if (!$entry->isFile() || !$collection->supportsName($entry->getFilename())) {
                continue;
            }
            $name = $entry->getFilename();
            $resourceId = $collection->resourceId($name);
            $sourceFile = $entry->getPathname();
            $dimensions = $this->imageProcessor->inspect($sourceFile);
            $resourceTags = array_merge($collection->defaultTags(), (array) ($metadata[$resourceId]['tags'] ?? []));
            $resources[] = [
                'id' => $resourceId,
                'type' => $collection->id(),
                'name' => $name,
                'tags' => $collection->normalizeTags($resourceTags),
                'mime' => (string) $collection->mimeForName($name),
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'bytes' => (int) $entry->getSize(),
                'modified' => (int) $entry->getMTime(),
                'has_thumbnail' => $this->imageProcessor->isCurrent($sourceFile, $collection->derivedPath($resourceId, 'thumbnail')),
                'has_optimized' => $this->imageProcessor->isCurrent($sourceFile, $collection->derivedPath($resourceId, 'optimized')),
            ];
        }
        $resources = $this->sortNewestFirst($resources);

        $catalog = [
            'version' => 1,
            'type' => $collection->id(),
            'generated_at' => gmdate(DATE_ATOM),
            'total' => count($resources),
            'resources' => $resources,
        ];
        $this->writeJson($collection->catalogFile(), $catalog);
        $script = 'window[' . $this->json($this->configuration->catalogGlobalKey()) . '] = Object.freeze('
            . $this->json($catalog, JSON_PRETTY_PRINT) . ");\n";
        $this->writeText($collection->catalogScriptFile(), $script);
        return $catalog;
    }

    /** Orders every catalog page by source modification time and keeps equal timestamps deterministic. */
    private function sortNewestFirst(array $resources): array
    {
        usort($resources, static function (array $left, array $right): int {
            $leftModified = (int) ($left['modified'] ?? 0);
            $rightModified = (int) ($right['modified'] ?? 0);
            $modifiedOrder = $rightModified <=> $leftModified;
            if ($modifiedOrder !== 0) {
                return $modifiedOrder;
            }

            // Branch: identical filesystem timestamps retain a stable human-readable order.
            return strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });
        return $resources;
    }

    /** Reads a catalog or creates its first version through the same guarded builder. */
    private function loadCatalog(ResourceCollection $collection): array
    {
        if (!is_file($collection->catalogFile())) {
            return $this->rebuild($collection->id());
        }
        return $this->readJson($collection->catalogFile());
    }

    /** Resolves one id only through its current catalog record. */
    private function record(ResourceCollection $collection, string $resourceId): array
    {
        return $this->recordFromCatalog($this->loadCatalog($collection), $resourceId);
    }

    /** Resolves an opaque id inside an already loaded catalog without taking a nested lock. */
    private function recordFromCatalog(array $catalog, string $resourceId): array
    {
        foreach ((array) ($catalog['resources'] ?? []) as $record) {
            // Loop: callers never provide or learn an internal source path.
            if ((string) ($record['id'] ?? '') === $resourceId) {
                return $record;
            }
        }
        throw new InvalidArgumentException('Unknown resource id.');
    }

    /** Joins a catalog-owned filename to its configured collection root after basename validation. */
    private function sourceFile(ResourceCollection $collection, string $name): string
    {
        if ($name === '' || basename($name) !== $name) {
            throw new InvalidArgumentException('Invalid resource name.');
        }
        return $collection->sourceDirectory() . DIRECTORY_SEPARATOR . $name;
    }

    /** Converts the PHP multi-upload layout into independent records. */
    private function normalizeUploads(array $uploads): array
    {
        $names = $uploads['name'] ?? [];
        $temporaryNames = $uploads['tmp_name'] ?? [];
        $sizes = $uploads['size'] ?? [];
        $errors = $uploads['error'] ?? [];
        if (!is_array($names)) {
            $names = [$names];
            $temporaryNames = [$temporaryNames];
            $sizes = [$sizes];
            $errors = [$errors];
        }
        $result = [];
        foreach ($names as $index => $name) {
            // Loop: each browser file keeps its corresponding temporary path, size and transport error.
            $result[] = [
                'name' => (string) $name,
                'tmp_name' => (string) ($temporaryNames[$index] ?? ''),
                'size' => (int) ($sizes[$index] ?? 0),
                'error' => (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE),
            ];
        }
        return $result;
    }

    /** Validates and stores one HTTP upload without overwriting an existing source. */
    private function storeUpload(ResourceCollection $collection, array $upload): string
    {
        if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload transport failed.');
        }
        if ((int) $upload['size'] < 1 || (int) $upload['size'] > $this->configuration->maximumUploadBytes()) {
            throw new RuntimeException('Upload size is outside the configured limit.');
        }
        $name = $collection->validatedUploadName((string) $upload['name']);
        $temporaryFile = (string) $upload['tmp_name'];
        $expectedMime = $collection->mimeForName($name);
        $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryFile);
        if (!is_string($actualMime) || $actualMime !== $expectedMime) {
            throw new RuntimeException('Upload MIME does not match its extension.');
        }
        $destination = $this->sourceFile($collection, $name);
        if (is_file($destination)) {
            throw new RuntimeException('A resource with this name already exists.');
        }
        if (!is_uploaded_file($temporaryFile) || !move_uploaded_file($temporaryFile, $destination)) {
            throw new RuntimeException('Unable to store uploaded resource.');
        }
        @chmod($destination, 0644);
        return $destination;
    }

    /** Executes one mutation under the collection-specific filesystem lock. */
    private function withLock(ResourceCollection $collection, callable $operation): array
    {
        $this->ensureRuntimeDirectories($collection);
        $lockHandle = fopen($collection->lockFile(), 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            throw new RuntimeException('Unable to lock the resource engine.');
        }
        try {
            return $operation();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** Reads server-only tags while accepting an empty first-run state. */
    private function loadMetadata(ResourceCollection $collection): array
    {
        return is_file($collection->metadataFile()) ? $this->readJson($collection->metadataFile()) : [];
    }

    /** Creates every isolated runtime folder before lock or derivative operations. */
    private function ensureRuntimeDirectories(ResourceCollection $collection): void
    {
        foreach ([$collection->runtimeDirectory(), $collection->thumbnailDirectory(), $collection->optimizedDirectory(), $collection->trashDirectory()] as $directory) {
            // Loop: all mutable engine data stays outside the original collection directory.
            $this->ensureDirectory($directory);
        }
    }

    /** Rejects an unconfigured resource type before any filesystem mutation. */
    private function assertSourceDirectory(ResourceCollection $collection): void
    {
        if ($collection->sourceDirectory() === '' || !is_dir($collection->sourceDirectory())) {
            throw new RuntimeException('Resource source directory is unavailable.');
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create resource engine directory.');
        }
    }

    /** Reads one trusted engine JSON file as an associative structure. */
    private function readJson(string $file): array
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('Unable to read resource engine data.');
        }
        $value = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : [];
    }

    private function writeJson(string $file, array $value): void
    {
        $this->writeText($file, $this->json($value, JSON_PRETTY_PRINT) . "\n");
    }

    /** Atomically replaces one generated text artifact. */
    private function writeText(string $file, string $source): void
    {
        $this->ensureDirectory(dirname($file));
        $temporaryFile = tempnam(dirname($file), '.engine-');
        if ($temporaryFile === false) {
            throw new RuntimeException('Unable to create a temporary engine file.');
        }
        try {
            if (file_put_contents($temporaryFile, $source, LOCK_EX) === false || !@rename($temporaryFile, $file)) {
                throw new RuntimeException('Unable to install generated engine data.');
            }
            @chmod($file, 0644);
            $temporaryFile = '';
        } finally {
            if ($temporaryFile !== '' && is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    /** Serializes public data with safe script characters and stable Unicode. */
    private function json($value, int $extraFlags = 0): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | $extraFlags
        );
    }
}
