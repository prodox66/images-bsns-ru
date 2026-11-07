<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use DirectoryIterator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/ImageTagStore.php';

/** Owns catalog, metadata, upload and derivative operations behind one resource contract. */
final class ResourceEngine
{
    public const TAG_FILTER_UNAVAILABLE = 503;
    private const TAG_CONFLICT = 409;
    private const TAG = ['empty' => '', 'absent' => 'absent', 'embedded' => 'embedded', 'legacy' => 'legacy',
        'unsupported' => 'unsupported', 'embedded_marker' => 'tags_embedded',
        'add' => 'add', 'replace' => 'replace', 'recovery' => 'tag-recovery', 'failed' => 'Image tags are unavailable.'];
    private const TAG_ERROR = ['tags' => null, 'tag_state' => 'error', 'tag_revision' => '', 'tag_editable' => false];
    private const DELIVERY = ['original' => 'original', 'optimized' => 'optimized', 'resource' => 'resource', 'empty' => ''];
    private const RENAME_DERIVATIVE_VARIANTS = ['thumbnail', 'optimized'];
    private const REPLACEMENT = ['one' => 1, 'zero' => 0, 'revision_step' => 1,
        'random_bytes' => 8, 'prefix' => '.replace-', 'separator' => '-'];
    private EngineConfiguration $configuration;
    private ImageProcessor $imageProcessor;
    private ?ImageTagStore $tagStore = null;

    public function __construct(EngineConfiguration $configuration, ImageProcessor $imageProcessor)
    {
        $this->configuration = $configuration;
        $this->imageProcessor = $imageProcessor;
    }

    public function configuration(): EngineConfiguration { return $this->configuration; }

    /** The trusted collection and basename are already resolved; a single header read owns the file's tag state. */
    public function tagState(ResourceCollection $collection, string $name, ?array $legacyEntry): array
    {
        try {
            $mime = $collection->mimeForName($name);
            if ($mime === null) return self::TAG_ERROR;
            $state = $this->tagStore()->snapshot($this->sourceFile($collection, $name), $mime);
            // Branch: embedded (including []) and parser errors never consult or normalize legacy data.
            if ($state['tag_state'] !== self::TAG['absent']) return $state;
            $legacy = $this->legacyTagValues($collection, $legacyEntry);
            if ($legacy === null) return self::TAG_ERROR;
            $state['tags'] = $legacy;
            $state['tag_state'] = $legacy ? self::TAG['legacy'] : self::TAG['absent'];
            return $state;
        } catch (Throwable) { return self::TAG_ERROR; }
    }

    /** Metadata helpers are instantiated only for the new reader/writer, never hot binary lookup. */
    private function tagStore(): ImageTagStore
    {
        return $this->tagStore ??= new ImageTagStore();
    }

    /** Historical sidecar tags keep collection defaults until the file owns an explicit set. */
    private function legacyTagValues(ResourceCollection $collection, ?array $entry): ?array
    {
        if ($entry === null || ($entry[self::TAG['embedded_marker']] ?? false) === true) return null;
        $tags = (array) ($entry['tags'] ?? []);
        foreach ($tags as $tag) {
            // Loop: historical scalars are allowed, but malformed structured tags cannot become guessed strings.
            if (!is_scalar($tag)) return null;
        }
        return $collection->normalizeTags(array_merge($collection->defaultTags(), $tags));
    }

    /** Reads the selected sidecar once and retains its exact bytes for guarded mutation staging. */
    private function tagMetadata(ResourceCollection $collection): array
    {
        $file = $collection->metadataFile();
        if (!is_file($file)) return ['entries' => [], 'bytes' => null];
        $bytes = @file_get_contents($file);
        $decoded = is_string($bytes) ? json_decode($bytes, true) : null;
        return ['entries' => is_array($decoded) ? $decoded : null, 'bytes' => is_string($bytes) ? $bytes : null];
    }

    /** Empty means the valid sidecar has no entry; null keeps corruption distinguishable. */
    private function tagEntry(?array $metadata, string $id): ?array
    {
        $entry = $metadata === null ? null : (array_key_exists($id, $metadata) ? $metadata[$id] : []);
        return is_array($entry) ? $entry : null;
    }

    /** Legacy list consumers keep their searchable sidecar set for codec-unsupported files, without advertising a file edit. */
    public function tagFieldsForLegacyList(ResourceCollection $collection, string $name, ?array $legacy): array
    {
        $fields = $this->tagState($collection, $name, $legacy);
        if ($fields['tag_state'] !== self::TAG['unsupported']) return $fields;
        $tags = $this->legacyTagValues($collection, $legacy);
        return $tags === null ? self::TAG_ERROR : array_replace($fields, ['tags' => $tags, 'tag_state' => self::TAG['legacy']]);
    }

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
        $existingCatalog = is_file($collection->catalogFile());
        $catalog = $this->loadCatalog($collection);
        $records = (array) ($catalog['resources'] ?? []);
        if ($existingCatalog) {
            // Branch: live file tags can change without a generated catalog write; cold rebuild already read them.
            $metadata = $this->tagMetadata($collection)['entries'];
            foreach ($records as &$record) {
                // Loop: the whole result set receives tag state before any AND filter or page slice.
                $fields = $this->tagFieldsForLegacyList($collection, $record['name'], $this->tagEntry($metadata, $record['id']));
                $record = array_replace($record, $fields);
            }
            unset($record);
        }
        $requestedTags = $collection->normalizeTags($tags);
        $records = $this->filterTagRecords($records, $requestedTags);

        $effectivePageSize = $pageSize > 0 ? $pageSize : $this->configuration->defaultPageSize();
        $effectivePageSize = min($effectivePageSize, $this->configuration->maximumPageSize());
        $total = count($records);
        $pages = max(1, (int) ceil($total / $effectivePageSize));
        $effectivePage = max(1, min($pages, $page));
        $offset = ($effectivePage - 1) * $effectivePageSize;
        $items = array_slice($records, $offset, $effectivePageSize);
        foreach ($items as &$item) {
            // Loop: only visible records need delivery versions, separate from the metadata editing revision.
            $item['delivery_revisions'] = $this->deliveryRevisions($collection, $item);
        }
        unset($item);

        return [
            'ok' => true,
            'type' => $collection->id(),
            'page' => $effectivePage,
            'page_size' => $effectivePageSize,
            'pages' => $pages,
            'total' => $total,
            'items' => $items,
        ];
    }

    /** The list versions the same original or ready variant that the binary resolver will select. */
    private function deliveryRevisions(ResourceCollection $collection, array $record): array
    {
        $source = $this->sourceFile($collection, $record['name']);
        $original = ImageTagTransaction::revision($source);
        $versions = [self::DELIVERY['original'] => $original];
        foreach (self::RENAME_DERIVATIVE_VARIANTS as $variant) {
            // Loop: a missing/stale variant uses the original identity until it has a current published file.
            $path = $collection->derivedPath($record['id'], $variant);
            $revision = $this->imageProcessor->isCurrent($source, $path) ? ImageTagTransaction::revision($path) : self::DELIVERY['empty'];
            $versions[$variant] = $revision !== self::DELIVERY['empty'] ? $revision : $original;
        }
        $versions[self::DELIVERY['resource']] = $versions[self::DELIVERY['optimized']];
        return $versions;
    }

    /** A filtered result cannot claim a complete total while some file tag sets are unknown. */
    private function filterTagRecords(array $records, array $tags): array
    {
        if (!$tags) return $records;
        foreach ($records as $record) {
            // Loop: unknown records are retained unfiltered and are never silently counted as nonmatches.
            if (!is_array($record['tags'] ?? null)) {
                throw new RuntimeException('Image tag metadata is incomplete.', self::TAG_FILTER_UNAVAILABLE);
            }
        }
        return array_values(array_filter($records, static fn (array $record): bool => !array_diff($tags, $record['tags'])));
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
            'revision' => ImageTagTransaction::revision($selectedFile),
        ];
    }

    /** Keeps the existing admin action; unsupported formats retain their legacy sidecar behavior. */
    public function setTags(string $type, string $resourceId, array $tags, ?callable $publicAliases = null): array
    {
        return $this->changeTags($type, $resourceId, $tags, self::TAG['replace'], null, true, $publicAliases);
    }

    /** New callers use a fresh union or an exact replacement bound to the revision they displayed. */
    public function updateTags(string $type, string $resourceId, array $tags, string $operation, ?string $expectedRevision): array
    { return $this->requestTagUpdate($type, $resourceId, $tags, $operation, $expectedRevision); }

    /** A trusted host can include only its selected current public counterparts in the same tag transaction. */
    public function updateTagsWithPublicAliases(string $type, string $resourceId, array $tags, string $operation,
        ?string $expectedRevision, callable $publicAliases): array
    { return $this->requestTagUpdate($type, $resourceId, $tags, $operation, $expectedRevision, $publicAliases); }

    /** Both strict entry points share the same operation and revision admission. */
    private function requestTagUpdate(string $type, string $resourceId, array $tags, string $operation,
        ?string $expectedRevision, ?callable $publicAliases = null): array
    {
        if (!in_array($operation, [self::TAG['add'], self::TAG['replace'], ImageTagStore::SELECTED_ADD], true)
            || ImageTagStore::usesExpectedRevision($operation) && ($expectedRevision === null || $expectedRevision === self::TAG['empty'])) {
            throw new InvalidArgumentException(self::TAG['failed']);
        }
        return $this->changeTags($type, $resourceId, $tags, $operation, $expectedRevision, false, $publicAliases);
    }

    /** Resolves the selected id again inside the existing collection mutation lock. */
    private function changeTags(string $type, string $resourceId, array $tags, string $operation,
        ?string $expectedRevision, bool $allowLegacy, ?callable $publicAliases = null): array
    {
        $collection = $this->configuration->collection($type);
        // Branch: the existing portable API keeps its catalog lookup; the host list is directory-driven.
        if ($publicAliases === null) $this->loadCatalog($collection);
        return $this->withLock($collection, function () use ($collection, $resourceId, $tags, $operation, $expectedRevision, $allowLegacy, $publicAliases): array {
            $record = $publicAliases === null ? $this->recordFromCatalog($this->readJson($collection->catalogFile()), $resourceId)
                : $this->currentAliasTagRecord($collection, $resourceId);
            return $this->writeTagUpdate($collection, $record, $tags, $operation, $expectedRevision, $allowLegacy, $publicAliases);
        });
    }

    /** Rechecks the host's physical selected ID without publishing an index before alias admission. */
    private function currentAliasTagRecord(ResourceCollection $collection, string $resourceId): array
    {
        $this->assertSourceDirectory($collection);
        foreach (new DirectoryIterator($collection->sourceDirectory()) as $entry) {
            // Loop: a new FTP arrival can be selected without trusting an old catalog or following a link.
            $name = $entry->getFilename();
            if (!$entry->isFile() || $entry->isLink() || !$collection->supportsName($name)) continue;
            $id = $collection->resourceId($name);
            if (hash_equals($id, $resourceId)) return ['id' => $id, 'name' => $name];
        }
        throw new InvalidArgumentException('Resource not found.');
    }

    /** Stages only the selected source, current cached variants and its sidecar entry. */
    private function writeTagUpdate(ResourceCollection $collection, array $record, array $tags, string $operation,
        ?string $expectedRevision, bool $allowLegacy, ?callable $publicAliases): array
    {
        $sidecar = $this->tagMetadata($collection);
        $metadata = $sidecar['entries'];
        $id = $record['id']; $name = $record['name'];
        $legacy = $this->tagEntry($metadata, $id);
        if ($legacy === null) throw new RuntimeException(self::TAG['failed']);
        $state = $this->tagState($collection, $name, $legacy);
        if ($expectedRevision !== null && ImageTagStore::usesExpectedRevision($operation) && !hash_equals($state['tag_revision'], $expectedRevision)) {
            // Branch: a removed, replaced or newly unreadable source cannot satisfy a displayed revision.
            throw new RuntimeException('Image changed during metadata editing.', self::TAG_CONFLICT);
        }
        $recovery = $collection->runtimeDirectory() . DIRECTORY_SEPARATOR . self::TAG['recovery'];
        $legacyOnly = !$state['tag_editable'] && in_array($state['tag_state'],
            [self::TAG['unsupported'], self::TAG['legacy'], self::TAG['absent']], true);
        $unchanged = []; $missing = [];
        if ($allowLegacy && $legacyOnly) {
            // Branch: existing admin retains sidecar editing only for known untagged/read-only or unsupported formats.
            $legacy['tags'] = $collection->normalizeTags($tags);
            unset($legacy[self::TAG['embedded_marker']]);
            $transaction = new ImageTagTransaction($recovery);
        } else {
            // Branch: the new file mutation requires a known writable state and leaves other entry fields intact.
            if (!$state['tag_editable']) throw new RuntimeException(self::TAG['failed']);
            $source = $this->sourceFile($collection, $name);
            $variants = $this->currentTagVariants($collection, $source, $id);
            $proof = $publicAliases === null ? ['files' => [], 'missing' => []]
                : $this->publicAliasProof($collection, $name, $variants, $publicAliases);
            // Branch: old setTags retains its effective collection defaults; the new writer is exact.
            $requested = $allowLegacy ? $collection->normalizeTags(array_merge($collection->defaultTags(), $tags)) : $tags;
            $prepared = $this->tagStore()->prepare($source, $collection->mimeForName($name), $requested, $operation,
                $state['tags'], $expectedRevision, $recovery, array_merge(array_values($variants), array_keys($proof['files'])),
                $proof['files'], $publicAliases !== null);
            $transaction = $prepared['transaction'];
            if ($publicAliases !== null) {
                // Branch: the alias proof cannot be combined with a different source or lose a byte-exact no-op guard.
                if ($prepared['before_revision'] !== $state['tag_revision']) {
                    $transaction->rollback();
                    throw new RuntimeException(self::TAG['failed'], self::TAG_CONFLICT);
                }
                $unchanged = $prepared['unchanged']; $missing = $proof['missing'];
            }
            unset($legacy['tags']);
            $legacy[self::TAG['embedded_marker']] = true;
        }
        $metadata[$id] = $legacy;
        return $this->publishTagUpdate($collection, $transaction, $metadata, $sidecar['bytes'], $unchanged, $missing);
    }

    /** Selects only existing variants that already correspond to this source; no derivative is generated by a tag action. */
    private function currentTagVariants(ResourceCollection $collection, string $source, string $id): array
    {
        $variants = [];
        foreach (self::RENAME_DERIVATIVE_VARIANTS as $variant) {
            // Loop: the processor's existing freshness rule selects its own exact cached file.
            $path = $collection->derivedPath($id, $variant);
            if ($this->imageProcessor->isCurrent($source, $path)) $variants[$variant] = $path;
        }
        return $variants;
    }

    /** Existing aliases are admitted only under the exact public category and tied to a current ready variant. */
    private function publicAliasProof(ResourceCollection $collection, string $name, array $variants, callable $publicAliases): array
    {
        $source = realpath($collection->sourceDirectory());
        $aliases = $publicAliases($collection, $name);
        if (!is_string($source) || !is_array($aliases) || array_diff_key($aliases, array_flip(self::RENAME_DERIVATIVE_VARIANTS))) {
            throw new RuntimeException(self::TAG['failed']);
        }
        $category = dirname($source);
        $files = []; $missing = []; $seen = [];
        foreach ($aliases as $variant => $path) {
            // Loop: no arbitrary type, duplicate path, private source, or external directory can become a public alias.
            if (!is_string($path) || !$this->publicAliasPath($category, $source, $variant, $path)
                || in_array($path, $variants, true) || isset($seen[$path])) throw new RuntimeException(self::TAG['failed']);
            $seen[$path] = true;
            $parent = dirname($path);
            clearstatcache(true, $path);
            if (is_link($path) || is_link($parent) || is_dir($parent) && @realpath($parent) !== $parent) {
                throw new RuntimeException(self::TAG['failed'], self::TAG_CONFLICT);
            }
            if (!file_exists($path)) { $missing[] = $path; continue; }
            // Branch: an unbound or stale alias is never rewritten or silently left behind by a tag edit.
            if (!isset($variants[$variant]) || @realpath($path) !== $path) throw new RuntimeException(self::TAG['failed'], self::TAG_CONFLICT);
            $files += $this->tagStore()->bindCurrentAlias($variants[$variant], $path);
        }
        return ['files' => $files, 'missing' => $missing];
    }

    /** Working WebP is directly in the category; its public preview is in one child, outside originals. */
    private function publicAliasPath(string $category, string $source, string $variant, string $path): bool
    {
        $parent = dirname($path);
        if ($path !== $parent . DIRECTORY_SEPARATOR . basename($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'webp') return false;
        if ($variant === self::DELIVERY['optimized']) return $parent === $category;
        return dirname($parent) === $category && $parent !== $source && !in_array(basename($parent), ['.', '..'], true);
    }

    /** The two catalog artifacts join the same recovery boundary as the selected metadata edit. */
    private function publishTagUpdate(ResourceCollection $collection, ImageTagTransaction $transaction,
        array $metadata, ?string $previousSidecar, array $unchanged, array $missing): array
    {
        try {
            $transaction->stage($collection->metadataFile(), $previousSidecar, $this->jsonText($metadata));
            $transaction->install();
            $catalog = $this->compileCatalog($collection, $metadata);
            foreach ($this->catalogArtifacts($collection, $catalog) as $path => $bytes) {
                // Loop: exact prepublication catalog bytes remain recoverable if either artifact cannot be installed.
                $before = is_file($path) ? @file_get_contents($path) : null;
                if ($before === false) throw new RuntimeException(self::TAG['failed']);
                $transaction->stage($path, $before, $bytes);
            }
            $transaction->install();
            $this->verifyUnchangedTagTargets($unchanged, $missing);
            $transaction->commit();
            return $catalog;
        } catch (Throwable $error) {
            // Recovery: only matching installed bytes are rolled back; newer external bytes are never erased.
            $transaction->rollback();
            throw $error;
        }
    }

    /** A no-op or missing alias is still part of the selected family at the publication boundary. */
    private function verifyUnchangedTagTargets(array $unchanged, array $missing): void
    {
        foreach ($unchanged as $path => $proof) {
            // Loop: even an in-place change with the same stat cannot become part of the just-committed edit.
            if (!$this->tagStore()->unchangedMatches($path, $proof)) throw new RuntimeException(self::TAG['failed'], self::TAG_CONFLICT);
        }
        foreach ($missing as $path) {
            // Loop: metadata editing does not create absent variants or adopt a concurrently published alias.
            clearstatcache(true, $path);
            if (file_exists($path) || is_link($path)) throw new RuntimeException(self::TAG['failed'], self::TAG_CONFLICT);
        }
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

    /** Replaces one catalog-owned image; the prior source remains available in the private recovery folder. */
    public function replace(string $type, string $resourceId, array $uploads): array
    {
        $collection = $this->configuration->collection($type);
        $this->loadCatalog($collection);
        return $this->withLock($collection, function () use ($collection, $resourceId, $uploads): array {
            $catalog = $this->readJson($collection->catalogFile());
            $record = $this->recordFromCatalog($catalog, $resourceId);
            $files = $this->normalizeUploads($uploads);
            if (count($files) !== self::REPLACEMENT['one']) throw new InvalidArgumentException('Select one replacement image.');
            $upload = $files[self::REPLACEMENT['zero']];
            $name = $this->validatedUpload($collection, $upload);
            $extension = (string) pathinfo($name, PATHINFO_EXTENSION);
            $targetName = $collection->validatedUploadName(pathinfo((string) $record['name'], PATHINFO_FILENAME) . '.' . $extension);
            $staged = tempnam($collection->sourceDirectory(), self::REPLACEMENT['prefix']);
            if ($staged === false) throw new RuntimeException('Unable to stage replacement.');
            try {
                // Branch: only PHP-owned uploads can supply bytes to the locked publication transaction.
                if (!is_uploaded_file($upload['tmp_name']) || !move_uploaded_file($upload['tmp_name'], $staged)) {
                    throw new RuntimeException('Unable to stage uploaded replacement.');
                }
                return $this->publishReplacement($collection, $resourceId, $record, $staged, $targetName);
            } finally {
                if (is_file($staged)) @unlink($staged);
            }
        });
    }

    /** Publishes validated staged bytes atomically and restores the exact previous source on a failed catalog write. */
    private function publishReplacement(ResourceCollection $collection, string $resourceId, array $record, string $staged, string $name): array
    {
        $source = $this->sourceFile($collection, (string) $record['name']);
        $target = $this->sourceFile($collection, $name);
        if (!is_file($source) || is_link($source) || ($target !== $source && file_exists($target))) {
            throw new RuntimeException('The selected resource changed or its target name is already used.');
        }
        $this->ensureDirectory($collection->trashDirectory());
        $recovery = $collection->trashDirectory() . DIRECTORY_SEPARATOR . $resourceId . self::REPLACEMENT['separator']
            . bin2hex(random_bytes(self::REPLACEMENT['random_bytes'])) . self::REPLACEMENT['separator'] . basename($source);
        $metadataBefore = $this->loadMetadata($collection);
        $metadata = $metadataBefore;
        $id = $collection->resourceId($name);
        $this->prepareReplacementTags($collection, $record['name'], $name, $staged, $this->tagEntry($metadata, $resourceId));
        $revision = max(time(), (int) filemtime($source) + self::REPLACEMENT['revision_step']);
        if (!rename($source, $recovery)) throw new RuntimeException('Unable to preserve previous resource.');
        $installed = false;
        try {
            // Branch: format changes retain the display stem; the real extension continues to describe the uploaded bytes.
            if (!rename($staged, $target)) throw new RuntimeException('Unable to install replacement.');
            $installed = true; @chmod($target, 0644); touch($target, $revision);
            if ($id !== $resourceId && array_key_exists($resourceId, $metadata)) {
                $metadata[$id] = $metadata[$resourceId]; unset($metadata[$resourceId]);
                $this->writeJson($collection->metadataFile(), $metadata);
            }
            $catalog = $this->rebuildUnlocked($collection);
        } catch (Throwable $error) {
            // Recovery touches only this replacement, retaining all unrelated sources and metadata records.
            if ($installed && is_file($target)) @unlink($target);
            if (!rename($recovery, $source)) throw new RuntimeException('Replacement recovery failed.', self::REPLACEMENT['zero'], $error);
            $this->writeJson($collection->metadataFile(), $metadataBefore);
            $this->rebuildUnlocked($collection);
            throw $error;
        }
        foreach (array_unique([$resourceId, $id]) as $retiredId) {
            // Loop: successful replacement retires only its own cached derivatives.
            foreach (self::RENAME_DERIVATIVE_VARIANTS as $variant) @unlink($collection->derivedPath($retiredId, $variant));
        }
        return ['replaced' => $name, 'previous_name' => (string) $record['name'], 'id' => $id, 'catalog' => $catalog];
    }

    /** A previously embedded set is carried only by its own metadata block in the unpublished upload. */
    private function prepareReplacementTags(ResourceCollection $collection, string $oldName, string $newName,
        string $staged, ?array $legacy): void
    {
        $previous = $this->tagState($collection, $oldName, $legacy);
        // Branch: historical sidecar replacement and an already tagged incoming file keep their exact input bytes.
        if ($previous['tag_state'] !== self::TAG['embedded']) return;
        $mime = $collection->mimeForName($newName);
        $incoming = $this->tagStore()->snapshot($staged, $mime);
        if ($incoming['tag_state'] === self::TAG['embedded']) return;
        if ($incoming['tag_state'] !== self::TAG['absent'] || !$incoming['tag_editable']) {
            // Branch: refuse before moving the selected source if carrying its metadata would be unsafe.
            throw new InvalidArgumentException('Replacement cannot preserve the image tags.');
        }
        $recovery = $collection->runtimeDirectory() . DIRECTORY_SEPARATOR . self::TAG['recovery'];
        $prepared = $this->tagStore()->prepare($staged, $mime, $previous['tags'], self::TAG['replace'],
            [], $incoming['tag_revision'], $recovery);
        $transaction = $prepared['transaction'];
        try { $transaction->install(); $transaction->commit(); }
        catch (Throwable $error) { $transaction->rollback(); throw $error; }
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
        $catalog = $this->compileCatalog($collection, $this->tagMetadata($collection)['entries']);
        foreach ($this->catalogArtifacts($collection, $catalog) as $path => $bytes) {
            // Loop: ordinary rebuild retains the existing atomic-per-file publication policy.
            $this->writeText($path, $bytes);
        }
        return $catalog;
    }

    /** Catalog construction is separate from publication so a tag transaction can stage both exact artifacts. */
    private function compileCatalog(ResourceCollection $collection, ?array $metadata): array
    {
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
            $fields = $this->tagFieldsForLegacyList($collection, $name, $this->tagEntry($metadata, $resourceId));
            $resources[] = [
                'id' => $resourceId,
                'type' => $collection->id(),
                'name' => $name,
                'mime' => (string) $collection->mimeForName($name),
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'bytes' => (int) $entry->getSize(),
                'modified' => (int) $entry->getMTime(),
                'has_thumbnail' => $this->imageProcessor->isCurrent($sourceFile, $collection->derivedPath($resourceId, 'thumbnail')),
                'has_optimized' => $this->imageProcessor->isCurrent($sourceFile, $collection->derivedPath($resourceId, 'optimized')),
            ] + $fields;
        }
        $resources = $this->sortNewestFirst($resources);

        return [
            'version' => 1,
            'type' => $collection->id(),
            'generated_at' => gmdate(DATE_ATOM),
            'total' => count($resources),
            'resources' => $resources,
        ];
    }

    /** The JSON and classic script representations always serialize the same catalog. */
    private function catalogArtifacts(ResourceCollection $collection, array $catalog): array
    {
        $script = 'window[' . $this->json($this->configuration->catalogGlobalKey()) . '] = Object.freeze('
            . $this->json($catalog, JSON_PRETTY_PRINT) . ");\n";
        return [$collection->catalogFile() => $this->jsonText($catalog),
            $collection->catalogScriptFile() => $script];
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
        $name = $this->validatedUpload($collection, $upload);
        $temporaryFile = (string) $upload['tmp_name'];
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

    /** New uploads and addressed replacements share the exact existing size, name and MIME checks. */
    private function validatedUpload(ResourceCollection $collection, array $upload): string
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
        return $name;
    }

    /** Executes one mutation under the collection-specific filesystem lock. */
    private function withLock(ResourceCollection $collection, callable $operation): array
    {
        $this->ensureRuntimeDirectories($collection);
        return $collection->withMutationLock($operation);
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
        $this->writeText($file, $this->jsonText($value));
    }

    /** Generated JSON retains the existing line ending and serialization options. */
    private function jsonText(array $value): string { return $this->json($value, JSON_PRETTY_PRINT) . "\n"; }

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
