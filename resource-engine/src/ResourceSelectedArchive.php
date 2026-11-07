<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use RuntimeException;

/** Moves only a proven file family into one private recovery directory under the caller's collection lock. */
final class ResourceSelectedArchive
{
    private const C = ['mode' => 0700, 'regular_mask' => 0170000, 'regular' => 0100000,
        'random' => 16, 'prefix' => 'selection-', 'slot' => 'file-', 'journal' => 'restore.json', 'journal_mode' => 'xb',
        'conflict' => 409, 'changed' => 'Selected image changed before archiving.', 'failed' => 'Image archive is unavailable.'];
    private const STAT = ['dev' => true, 'ino' => true, 'mode' => true, 'uid' => true, 'gid' => true,
        'size' => true, 'mtime' => true, 'ctime' => true, 'nlink' => true];
    private const IDENTITY = ['dev' => true, 'ino' => true, 'mode' => true, 'uid' => true, 'gid' => true,
        'size' => true, 'mtime' => true, 'nlink' => true];
    private array $files = [];
    private array $missing = [];
    private array $moved = [];
    private array $inodeStates = [];
    private ?string $directory = null;

    /** The collection owns the trusted runtime and recovery roots. */
    public function __construct(private readonly ResourceCollection $collection, private readonly ImageTagStore $tagStore) {}

    /** Records a canonical selected file without mutating the archive or the live directory. */
    public function expect(string $path, ?string $revision = null, bool $optional = false, ?string $digest = null, ?string $source = null): void
    {
        if (isset($this->files[$path]) || isset($this->missing[$path])) throw $this->conflict();
        $parent = dirname($path); $canonical = @realpath($parent);
        if (is_link($parent) || ($canonical !== false && $canonical !== $parent) || (is_dir($parent) && !is_readable($parent))) throw $this->conflict();
        $state = $this->fileState($path);
        if ($state === null) {
            // Branch: only explicitly optional and absent paths may remain missing, never links or directories.
            if (!$optional || !$this->absent($path)) throw $this->conflict();
            $this->missing[$path] = true; return;
        }
        if ($canonical !== $parent || @realpath($path) !== $path || ($revision !== null
            && (!hash_equals($revision, ImageTagTransaction::revision($path)) || $this->fileState($path) !== $state))
            || !$this->matchesDigest($path, $state, $digest)) {
            throw $this->conflict();
        }
        $this->files[$path] = ['before' => $state, 'digest' => $digest, 'source' => $source ?? $path,
            'slot' => self::C['slot'] . count($this->files)];
    }

    /** Missing public aliases cannot be replaced with an unverified file between proof and planning. */
    public function expectMissing(string $path): void
    {
        if (isset($this->files[$path]) || isset($this->missing[$path]) || !$this->absent($path)) throw $this->conflict();
        $this->missing[$path] = true;
    }

    /** Writes recovery information first, then moves originals and exact variants without copying image bytes. */
    public function move(array $records): void
    {
        $root = $this->collection->trashDirectory();
        $runtime = $this->collection->runtimeDirectory();
        if (@realpath($runtime) !== $runtime || is_link($root)
            || (!is_dir($root) && !@mkdir($root, self::C['mode']) && !is_dir($root)) || @realpath($root) !== $root) {
            throw new RuntimeException(self::C['failed']);
        }
        $candidate = $root . DIRECTORY_SEPARATOR . self::C['prefix'] . bin2hex(random_bytes(self::C['random']));
        if (!@mkdir($candidate, self::C['mode']) || @realpath($candidate) !== $candidate) throw new RuntimeException(self::C['failed']);
        $this->directory = $candidate;
        $archiveStat = @stat($candidate);
        if (!is_array($archiveStat)) throw new RuntimeException(self::C['failed']);
        foreach ($this->files as $entry) {
            // Loop: a cross-device rename may copy and unlink, so reject the entire selection before moving anything.
            if ($entry['before']['dev'] !== $archiveStat['dev']) throw new RuntimeException(self::C['failed']);
        }
        $this->writeJournal($records);
        foreach ($this->files as $path => $entry) $this->moveFile($path, $entry);
        $this->assertRemoved();
    }

    /** A matching selection must stay absent through catalog publication. */
    public function removedPaths(): array { return array_keys($this->files + $this->missing); }

    /** Detached public copies keep their content proof through the catalog's final publication check. */
    public function archiveProofs(): array
    {
        $proofs = [];
        foreach ($this->moved as $path => $record) {
            // Loop: identical inode pairs need no digest; detached copies retain the bytes approved before the move.
            $digest = $this->files[$path]['digest'];
            if ($digest === null) continue;
            $backup = $record['backup']; $state = $this->fileState($backup);
            if ($state === null || @realpath($backup) !== $backup || $this->identity($state) !== $record['identity']) throw $this->conflict();
            $proofs[$backup] = $this->digestProof($backup, $state, $digest);
        }
        return $proofs;
    }

    /** Restores only proven families; a replacement at the source leaves old variants in recovery. */
    public function rollback(): array
    {
        if ($this->directory !== null && @realpath($this->directory) !== $this->directory) throw new RuntimeException(self::C['failed']);
        $families = []; $partial = false; $replacements = [];
        foreach ($this->moved as $path => $record) {
            // Loop: restoring a cache may not be separated from its original source's admission.
            $families[$this->files[$path]['source']][$path] = $record;
        }
        foreach ($families as $source => $files) {
            // Loop: another family's conflicting source cannot prevent this exact original from returning.
            $result = $this->restoreFamily($source, $files);
            $partial = $partial || !$result['complete'];
            if ($result['replacement'] !== null) $replacements[$source] = $result['replacement'];
        }
        if (!$partial) $this->discardEmptyDirectory();
        return ['partial' => $partial, 'replacements' => $replacements];
    }

    /** Keep backup links until a restored original proves that returning its caches is still safe. */
    private function restoreFamily(string $source, array $files): array
    {
        $original = $files[$source] ?? null;
        if ($original === null || !$this->restoreLink($source, $original)) {
            return ['complete' => false, 'replacement' => $this->replacementRevision($source, $original)];
        }
        // Branch: a file that arrived during rename is restored as-is, but never inherits the selected original's caches or tags.
        if ($original['identity'] !== $original['selected_identity']) {
            $replacement = ImageTagTransaction::revision($source) ?: null;
            if ($this->ownsLiveLink($source, $original)) @unlink($original['backup']);
            return ['complete' => false, 'replacement' => $replacement];
        }
        $installed = [$source => $original];
        foreach (array_diff_key($files, $installed) as $path => $record) {
            // Loop: even a late replacement of a just-restored source prevents further old cache publication.
            if (!$this->ownsLiveLink($source, $original)) break;
            if ($this->restoreLink($path, $record)) $installed[$path] = $record;
        }
        if (!$this->ownsLiveLink($source, $original)) {
            foreach (array_diff_key($installed, [$source => true]) as $path => $record) {
                // Loop: retire only a link this attempt installed, retaining all of its private backups.
                if ($this->ownsLiveLink($path, $record)) @unlink($path);
            }
            return ['complete' => false, 'replacement' => $this->replacementRevision($source, $original)];
        }
        $complete = count($installed) === count($files);
        foreach ($installed as $path => $record) {
            // Loop: retire a recovery link only when the same inode is back at the intended live path.
            if (!$this->ownsLiveLink($path, $record) || !@unlink($record['backup'])) $complete = false;
        }
        return ['complete' => $complete, 'replacement' => null];
    }

    /** A no-clobber link restores the exact inode into a canonical vacant slot. */
    private function restoreLink(string $path, array $record): bool
    {
        return $this->matchesRestoration($record['backup'], $record)
            && @realpath(dirname($path)) === dirname($path) && $this->absent($path) && @link($record['backup'], $path);
    }

    /** The live file and its still-held private backup must both name the planned inode. */
    private function ownsLiveLink(string $path, array $record): bool
    { return $this->matchesRestoration($path, $record) && $this->matchesRestoration($record['backup'], $record); }

    /** Restoring sibling links changes their link count without changing the selected bytes or identity. */
    private function matchesRestoration(string $path, array $record): bool
    {
        $state = $this->fileState($path); $expected = $record['identity'];
        if ($state === null || $expected === null || @realpath($path) !== $path) return false;
        $actual = $this->identity($state);
        unset($actual['nlink'], $expected['nlink']);
        return $actual === $expected;
    }

    /** Only a proven independently arrived regular source can retire that family's legacy sidecar entry. */
    private function replacementRevision(string $source, ?array $original): ?string
    {
        if ($original === null || !$this->matchesRestoration($original['backup'], $original)
            || $this->matchesRestoration($source, $original) || @realpath($source) !== $source) return null;
        return ImageTagTransaction::revision($source) ?: null;
    }

    /** Binds each move to its last observed inode state, including intentional moves of a sibling hardlink. */
    private function moveFile(string $path, array $entry): void
    {
        $before = $entry['before']; $inode = $before['dev'] . ':' . $before['ino'];
        $expected = $this->inodeStates[$inode] ?? $before;
        $backup = $this->directory . DIRECTORY_SEPARATOR . $entry['slot'];
        if ($this->fileState($path) !== $expected || @realpath($path) !== $path || @realpath($this->directory) !== $this->directory
            || !$this->absent($backup) || !$this->matchesDigest($path, $expected, $entry['digest'])) throw $this->conflict();
        if (!@rename($path, $backup)) throw new RuntimeException(self::C['failed']);
        $observed = $this->fileState($backup);
        // Branch: remember the actual moved entry for conservative recovery, even if an external writer raced this move.
        $this->moved[$path] = ['backup' => $backup, 'identity' => $observed === null ? null : $this->identity($observed),
            'selected_identity' => $this->identity($before)];
        if ($observed === null || $this->identity($observed) !== $this->identity($before)) throw $this->conflict();
        $this->inodeStates[$inode] = $observed;
    }

    /** Uses the existing bounded reader to prove a detached file, followed by the exact observed inode state. */
    private function matchesDigest(string $path, array $state, ?string $digest): bool
    {
        return $digest === null || ($this->tagStore->unchangedMatches($path, $this->digestProof($path, $state, $digest))
            && $this->fileState($path) === $state);
    }

    /** Catalog checks and move admission share the same content-proof format. */
    private function digestProof(string $path, array $state, string $digest): array
    { return ['revision' => ImageTagTransaction::revision($path), 'size' => $state['size'], 'digest' => $digest]; }

    /** Exact paths and legacy sidecar values are private recovery data, never response fields. */
    private function writeJournal(array $records): void
    {
        $locations = array_map(static fn (array $entry): string => $entry['slot'], $this->files);
        $payload = json_encode(['type' => $this->collection->id(), 'records' => $records, 'locations' => $locations], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $journal = $this->directory . DIRECTORY_SEPARATOR . self::C['journal'];
        $handle = @fopen($journal, self::C['journal_mode']);
        if ($handle === false) throw new RuntimeException(self::C['failed']);
        try {
            if (@fwrite($handle, $payload) !== strlen($payload) || !@fflush($handle)) throw new RuntimeException(self::C['failed']);
        } finally {
            fclose($handle);
        }
    }

    /** Rechecks selected destinations without following links or deleting any unplanned path. */
    private function assertRemoved(): void
    {
        foreach ($this->removedPaths() as $path) {
            // Loop: an arrival in a moved or previously missing slot fails the transaction rather than being removed.
            if (!$this->absent($path)) throw $this->conflict();
        }
    }

    /** Only this operation's empty recovery directory may be removed on a complete rollback. */
    private function discardEmptyDirectory(): void
    {
        if ($this->directory === null || @realpath($this->directory) !== $this->directory) return;
        $journal = $this->directory . DIRECTORY_SEPARATOR . self::C['journal'];
        $remaining = @scandir($this->directory);
        if (!is_array($remaining) || array_diff($remaining, ['.', '..', self::C['journal']])) return;
        if (is_file($journal) && !is_link($journal)) @unlink($journal);
        @rmdir($this->directory);
    }

    /** Rename may update ctime; ownership still follows the exact remaining inode and metadata. */
    private function identity(array $state): array { return array_intersect_key($state, self::IDENTITY); }
    /** One conflict code marks a stale or independently changed path. */
    private function conflict(): RuntimeException { return new RuntimeException(self::C['changed'], self::C['conflict']); }
    /** A dangling link occupies its path just as a regular file does. */
    private function absent(string $path): bool { clearstatcache(true, $path); return !file_exists($path) && !is_link($path); }

    /** Reads regular files only; atime is deliberately irrelevant to mutation identity. */
    private function fileState(string $path): ?array
    {
        clearstatcache(true, $path); $state = @lstat($path);
        return is_array($state) && ($state['mode'] & self::C['regular_mask']) === self::C['regular']
            ? array_intersect_key($state, self::STAT) : null;
    }
}
