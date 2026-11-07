<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/ImageTags.php';

/** A caller-owned mutation lock encloses this recoverable transaction over exact selected files. */
final class ImageTagTransaction
{
    private const C = ['zero' => 0, 'one' => 1, 'empty' => '', 'hash' => 'sha256', 'directory_mode' => 0700,
        'private_mode' => 0600, 'permission_mask' => 0777, 'new_prefix' => '.tags-new-', 'old_prefix' => 'tags-old-',
        'conflict' => 409, 'changed' => 'Image changed during metadata editing.', 'failed' => 'Unable to store image tags.'];
    private const LINK_IDENTITY = ['dev' => true, 'ino' => true, 'mode' => true, 'uid' => true, 'gid' => true, 'size' => true, 'mtime' => true];
    private array $records = [];
    private bool $closed = false;
    private string $recoveryDirectory;

    /** Recovery files stay in a host-selected private directory, never a browser-supplied path. */
    public function __construct(string $recoveryDirectory, private readonly int $maximumBytes = ImageTagSource::MAXIMUM_BYTES)
    {
        if ($maximumBytes <= self::C['zero'] || $maximumBytes > ImageTagSource::MAXIMUM_BYTES
            || is_link($recoveryDirectory) || (!is_dir($recoveryDirectory) && !mkdir($recoveryDirectory, self::C['directory_mode']) && !is_dir($recoveryDirectory))) {
            throw new RuntimeException(self::C['failed']);
        }
        $resolved = realpath($recoveryDirectory);
        if (!is_string($resolved) || !is_writable($resolved)) throw new RuntimeException(self::C['failed']);
        $this->recoveryDirectory = $resolved;
    }

    /** One local stat revision detects our atomic replacements without pretending to be a remote content digest. */
    public static function revision(string $path): string
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) return self::C['empty'];
        $stat = stat($path);
        if (!is_array($stat)) return self::C['empty'];
        $values = array_intersect_key($stat, array_fill_keys(['dev', 'ino', 'size', 'mtime', 'ctime'], true));
        return hash(self::C['hash'], json_encode($values, JSON_THROW_ON_ERROR));
    }

    /** Staging validates the exact old bytes and creates only its own new/recovery temporary files. */
    public function stage(string $path, ?string $before, string $after, ?string $expectedRevision = null): bool
    {
        if ($this->closed || strlen($after) > $this->maximumBytes || $before !== null && strlen($before) > $this->maximumBytes) {
            throw new RuntimeException(self::C['failed']);
        }
        $parent = realpath(dirname($path));
        if (!is_string($parent) || !is_writable($parent)) throw new RuntimeException(self::C['failed']);
        $path = $parent . DIRECTORY_SEPARATOR . basename($path);
        if (isset($this->records[$path])) throw new InvalidArgumentException(self::C['failed']);
        $beforeHash = $before === null ? null : hash(self::C['hash'], $before);
        $record = ['path' => $path, 'before_hash' => $beforeHash, 'after_hash' => hash(self::C['hash'], $after),
            'before_size' => $before === null ? null : strlen($before), 'after_size' => strlen($after),
            'revision' => self::revision($path), 'installed' => false, 'temporary' => null, 'recovery' => null, 'after_revision' => null];
        if (!$this->matches($record, false) || $expectedRevision !== null && !hash_equals($expectedRevision, $record['revision'])) {
            throw new RuntimeException(self::C['changed'], self::C['conflict']);
        }
        if ($before === $after) return false;
        $stat = $before === null ? null : stat($path);
        try {
            // Branch: every pending replacement has exact recoverable bytes before its original can be replaced.
            if ($before !== null) $record['recovery'] = $this->temporary($this->recoveryDirectory, self::C['old_prefix'], $before, null);
            $record['temporary'] = $this->temporary($parent, self::C['new_prefix'], $after, $stat);
            $record['stat'] = $stat;
            $this->records[$path] = $record;
            return true;
        } catch (Throwable $error) {
            $this->remove($record['temporary']); $this->remove($record['recovery']); throw $error;
        }
    }

    /** Selected images precede their sidecar retirement; failures roll back only still-owned installed results. */
    public function install(): void
    {
        if ($this->closed) throw new RuntimeException(self::C['failed']);
        try {
            foreach ($this->records as &$record) {
                // Loop: a fresh revision and digest check occurs immediately before each atomic replacement.
                if ($record['installed']) continue;
                if (!$this->matches($record, false) || !$this->pendingRevisionMatches($record)) {
                    throw new RuntimeException(self::C['changed'], self::C['conflict']);
                }
                if (!rename($record['temporary'], $record['path'])) throw new RuntimeException(self::C['failed']);
                $record['temporary'] = null; $record['installed'] = true;
                $record['after_revision'] = self::revision($record['path']);
            }
            unset($record);
        } catch (Throwable $error) {
            unset($record);
            $this->rollback(); throw $error;
        }
    }

    /** Replacing one hardlink changes ctime on its still-pending siblings without changing their data. */
    private function pendingRevisionMatches(array $record): bool
    {
        if (self::revision($record['path']) === $record['revision']) return true;
        $before = $record['stat'];
        $current = is_link($record['path']) ? false : @stat($record['path']);
        if (!is_array($before) || !is_array($current)
            || array_intersect_key($before, self::LINK_IDENTITY) !== array_intersect_key($current, self::LINK_IDENTITY)) return false;
        $removed = self::C['zero'];
        foreach ($this->records as $installed) {
            // Loop: only this transaction's already displaced links can explain a changed old link count.
            $original = $installed['stat'];
            if ($installed['installed'] && is_array($original) && $original['dev'] === $before['dev'] && $original['ino'] === $before['ino']) $removed++;
        }
        // The caller still requires the exact original digest before moving this sibling.
        return $removed > self::C['zero'] && $current['nlink'] === $before['nlink'] - $removed;
    }

    /** A newer external version is kept; its exact original recovery file remains available rather than being overwritten. */
    public function rollback(): void
    {
        if ($this->closed) return;
        $conflict = false;
        foreach (array_reverse(array_keys($this->records)) as $key) {
            $record = &$this->records[$key];
            if (!$record['installed']) { unset($record); continue; }
            if (!$this->matches($record, true) || self::revision($record['path']) !== $record['after_revision']) {
                // Branch: external changes do not become rollback targets, even if another selected file can be restored.
                $conflict = true; unset($record); continue;
            }
            if ($record['before_hash'] === null) {
                if (!unlink($record['path'])) throw new RuntimeException(self::C['failed']);
            } else {
                $bytes = file_get_contents($record['recovery']);
                if (!is_string($bytes) || !hash_equals($record['before_hash'], hash(self::C['hash'], $bytes))) throw new RuntimeException(self::C['failed']);
                $temporary = $this->temporary(dirname($record['path']), self::C['new_prefix'], $bytes, $record['stat']);
                if (!rename($temporary, $record['path'])) { $this->remove($temporary); throw new RuntimeException(self::C['failed']); }
            }
            $record['installed'] = false; unset($record);
        }
        if ($conflict) throw new RuntimeException(self::C['changed'], self::C['conflict']);
        $this->finish();
    }

    /** Success retires only exact temporary/recovery files created by this transaction. */
    public function commit(): void
    {
        if ($this->closed) return;
        foreach ($this->records as $record) {
            // Loop: success cannot discard recovery bytes for a pending or externally changed result.
            if (!$record['installed'] || !$this->matches($record, true) || self::revision($record['path']) !== $record['after_revision']) {
                throw new RuntimeException(self::C['changed'], self::C['conflict']);
            }
        }
        $this->finish();
    }

    /** File ownership is checked by type and the matching old/new byte digest. */
    private function matches(array $record, bool $after): bool
    {
        $path = $record['path']; clearstatcache(true, $path);
        $expected = $record[$after ? 'after_hash' : 'before_hash'];
        if ($expected === null) return !file_exists($path) && !is_link($path);
        if (is_link($path) || !is_file($path) || @filesize($path) !== $record[$after ? 'after_size' : 'before_size']) return false;
        $digest = hash_file(self::C['hash'], $path);
        return is_string($digest) && hash_equals($expected, $digest);
    }

    /** A staged image retains ordinary permissions/mtime; recovery bytes remain private. */
    private function temporary(string $directory, string $prefix, string $bytes, ?array $stat): string
    {
        $path = tempnam($directory, $prefix);
        if (!is_string($path)) throw new RuntimeException(self::C['failed']);
        try {
            if (dirname($path) !== $directory) throw new RuntimeException(self::C['failed']);
            $mode = $stat === null ? self::C['private_mode'] : $stat['mode'] & self::C['permission_mask'];
            if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes) || !chmod($path, $mode)
                || $stat !== null && !touch($path, $stat['mtime'])) throw new RuntimeException(self::C['failed']);
            return $path;
        } catch (Throwable $error) { $this->remove($path); throw $error; }
    }

    /** Cleanup never enumerates or recursively removes a directory. */
    private function remove(?string $path): void { if (is_string($path) && is_file($path)) @unlink($path); }
    private function finish(): void
    {
        $this->closed = true;
        foreach ($this->records as $record) {
            // Loop: paths come only from this transaction's tempnam results, never from an incoming record.
            $this->remove($record['temporary']); $this->remove($record['recovery']);
        }
    }

    /** An abandoned staged operation leaves recoveries for any installed bytes and only retires unused new buffers. */
    public function __destruct()
    {
        if ($this->closed) return;
        foreach ($this->records as $record) {
            $this->remove($record['temporary']);
            if (!$record['installed']) $this->remove($record['recovery']);
        }
    }
}

/** Shared file-tag states and ADD/REPLACE preparation keep persistence out of both gallery renderers. */
final class ImageTagStore
{
    public const SELECTED_ADD = 'add_selected';
    private const SELECTED_REVISION_PATTERN = '/^[a-f0-9]{64}$/D';
    private const C = ['empty' => '', 'zero' => 0, 'one' => 1, 'hash' => 'sha256', 'read_mode' => 'rb', 'maximum_edit_bytes' => 20971520,
        'supported_mimes' => ['image/png', 'image/jpeg', 'image/webp'], 'add' => 'add', 'replace' => 'replace',
        'known' => 'embedded', 'legacy' => 'legacy', 'absent' => 'absent', 'unsupported' => 'unsupported', 'error' => 'error',
        'conflict' => 409, 'changed' => 'Image changed during metadata editing.', 'failed' => 'Image tags are unavailable.'];
    private ImageTags $reader;
    private ImageTags $writer;

    public function __construct(private readonly int $maximumBytes = self::C['maximum_edit_bytes'])
    { $this->reader = new ImageTags(); $this->writer = new ImageTags($maximumBytes); }

    /** Binds an existing exact public file to its current derived counterpart before staging. */
    public function bindCurrentAlias(string $variant, string $alias): array
    {
        $first = @ImageTagTransaction::revision($variant); $second = @ImageTagTransaction::revision($alias);
        if ($variant === $alias || $first === self::C['empty'] || $second === self::C['empty']) {
            throw new RuntimeException(self::C['changed'], self::C['conflict']);
        }
        $digest = null;
        if (!hash_equals($first, $second)) {
            // Branch: same-inode aliases need no extra read; real copies must have the exact bounded bytes.
            $size = @filesize($variant);
            if (!is_int($size) || $size > $this->maximumBytes || @filesize($alias) !== $size) {
                throw new RuntimeException(self::C['changed'], self::C['conflict']);
            }
            $digest = $this->boundedDigest($variant, $size); $copy = $this->boundedDigest($alias, $size);
            if (!is_string($digest) || !is_string($copy) || !hash_equals($digest, $copy)) {
                throw new RuntimeException(self::C['changed'], self::C['conflict']);
            }
        }
        if (@ImageTagTransaction::revision($variant) !== $first || @ImageTagTransaction::revision($alias) !== $second) {
            throw new RuntimeException(self::C['changed'], self::C['conflict']);
        }
        // A copy keeps its verified digest; a hardlink must match the counterpart bytes read during staging.
        return [$variant => ['revision' => $first, 'digest' => $digest],
            $alias => ['revision' => $second, 'digest' => $digest, 'counterpart' => $variant]];
    }

    /** Header reads distinguish known-empty, legacy fallback, unsupported data and parse errors. */
    public function snapshot(string $path, string $mime, array $legacy = []): array
    {
        $revision = ImageTagTransaction::revision($path);
        $result = ['tags' => null, 'tag_state' => self::C['error'], 'tag_revision' => $revision, 'tag_editable' => false];
        if ($revision === self::C['empty']) return $result;
        if (!in_array($mime, self::C['supported_mimes'], true)) return array_replace($result, ['tag_state' => self::C['unsupported']]);
        try {
            $state = $this->reader->fileState($path, $this->maximumBytes); $tags = $state['tags'];
            // Branch: a concurrent external replacement never becomes a coherent cached metadata snapshot.
            if (ImageTagTransaction::revision($path) !== $revision) return $result;
            $fallback = $tags === null ? $this->legacy($legacy) : [];
            return array_replace($result, ['tags' => $tags ?? $fallback,
                'tag_state' => $tags !== null ? self::C['known'] : ($fallback ? self::C['legacy'] : self::C['absent']),
                'tag_editable' => $state['writable']]);
        } catch (Throwable) { return $result; }
    }

    /** Only selected ADD and REPLACE bind their initial source to a revision; ordinary ADD keeps its fresh union. */
    public static function usesExpectedRevision(string $operation): bool
    { return in_array($operation, [self::C['replace'], self::SELECTED_ADD], true); }

    /** The lock owner supplies only already-resolved source/variant paths and its current legacy entry. */
    public function prepare(string $path, string $mime, array $tags, string $operation, array $legacy,
        ?string $expectedRevision, string $recoveryDirectory, array $variants = [], array $variantProofs = [],
        bool $observeUnchanged = false): array
    {
        if (!in_array($operation, [self::C['add'], self::C['replace'], self::SELECTED_ADD], true)
            || !in_array($mime, self::C['supported_mimes'], true)
            || $operation === self::SELECTED_ADD && ($expectedRevision === null || preg_match(self::SELECTED_REVISION_PATTERN, $expectedRevision) !== self::C['one'])) {
            throw new InvalidArgumentException(self::C['failed']);
        }
        $revision = ImageTagTransaction::revision($path);
        if ($revision === self::C['empty'] || self::usesExpectedRevision($operation) && $expectedRevision !== null && !hash_equals($revision, $expectedRevision)) {
            throw new RuntimeException(self::C['changed'], self::C['conflict']);
        }
        $before = $this->bytes($path); $current = $this->reader->read($before);
        $requested = $this->writer->normalize($tags);
        // Branch: a selected addition with no actual values must not create an embedded empty set.
        if ($operation === self::SELECTED_ADD && !$requested) throw new InvalidArgumentException(self::C['failed']);
        // Branch: ADD uses the freshly read complete set; REPLACE and explicit clear do not inherit any fallback.
        $wanted = $operation !== self::C['replace'] ? $this->writer->normalize(array_values(array_unique(array_merge(
            $current ?? $this->legacy($legacy), $requested), SORT_STRING))) : $requested;
        $transaction = new ImageTagTransaction($recoveryDirectory, $this->maximumBytes);
        try {
            $unchanged = []; $digests = [];
            if (!$transaction->stage($path, $before, $this->writer->embed($before, $wanted), $revision) && $observeUnchanged) {
                $unchanged[$path] = $this->unchangedProof($before, $revision);
            }
            foreach (array_unique($variants) as $variant) {
                // Loop: existing current variants receive metadata only; this path never creates or encodes a derivative.
                if ($variant === $path) continue;
                $variantRevision = ImageTagTransaction::revision($variant);
                $proof = $variantProofs[$variant] ?? null;
                if ($proof !== null && $proof['revision'] !== $variantRevision) {
                    throw new RuntimeException(self::C['changed'], self::C['conflict']);
                }
                $bytes = $this->bytes($variant);
                if ($proof !== null) $digests[$variant] = $this->assertVariantBytes($bytes, $proof, $digests);
                if (!$transaction->stage($variant, $bytes, $this->writer->embed($bytes, $wanted), $variantRevision) && $observeUnchanged) {
                    $unchanged[$variant] = $this->unchangedProof($bytes, $variantRevision);
                }
            }
            return ['transaction' => $transaction, 'tags' => $wanted, 'before_revision' => $revision, 'unchanged' => $unchanged];
        } catch (Throwable $error) { $transaction->rollback(); throw $error; }
    }

    /** Exact stage bytes must still satisfy the proof that admitted this selected alias. */
    private function assertVariantBytes(string $bytes, array $proof, array $digests): string
    {
        $actual = hash(self::C['hash'], $bytes);
        $expected = $proof['digest'] ?? (isset($proof['counterpart']) ? ($digests[$proof['counterpart']] ?? self::C['empty']) : null);
        if ($expected !== null && !hash_equals($expected, $actual)) {
            throw new RuntimeException(self::C['changed'], self::C['conflict']);
        }
        return $actual;
    }

    /** An opted-in unchanged target keeps a digest of the bytes already read for preparation. */
    private function unchangedProof(string $bytes, string $revision): array
    { return ['revision' => $revision, 'size' => strlen($bytes), 'digest' => hash(self::C['hash'], $bytes)]; }

    /** No-op publication verifies bounded content and then the current identity, without rewriting the file. */
    public function unchangedMatches(string $path, array $proof): bool
    {
        $size = $proof['size']; $expected = $proof['revision'];
        if (@ImageTagTransaction::revision($path) !== $expected) return false;
        $digest = $this->boundedDigest($path, $size);
        return $digest !== null && hash_equals($proof['digest'], $digest) && @ImageTagTransaction::revision($path) === $expected;
    }

    /** A changed file cannot consume more than its verified size plus the extra-byte guard. */
    private function boundedDigest(string $path, int $size): ?string
    {
        if ($size > $this->maximumBytes) return null;
        $stream = @fopen($path, self::C['read_mode']);
        if ($stream === false) return null;
        try {
            // Branch: an in-place growth can only consume the expected length and one extra byte.
            $hash = hash_init(self::C['hash']);
            $read = @hash_update_stream($hash, $stream, $size + self::C['one']);
            return $read === $size ? hash_final($hash) : null;
        } finally { fclose($stream); }
    }

    /** Only the historical boundary converts scalar legacy tags; embedded envelopes stay strictly string typed. */
    private function legacy(array $tags): array
    {
        $strings = [];
        foreach ($tags as $tag) {
            if (!is_scalar($tag)) throw new InvalidArgumentException(self::C['failed']);
            $strings[] = (string) $tag;
        }
        return $strings;
    }

    /** Explicit writes are bounded before loading source or current variant bytes into memory. */
    private function bytes(string $path): string
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || filesize($path) > $this->maximumBytes) throw new RuntimeException(self::C['failed']);
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) > $this->maximumBytes) throw new RuntimeException(self::C['failed']);
        return $bytes;
    }
}
