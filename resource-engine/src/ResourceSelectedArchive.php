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
    public function __construct(private readonly ResourceCollection $collection) {}

    /** Records a canonical selected file without mutating the archive or the live directory. */
    public function expect(string $path, ?string $revision = null, bool $optional = false): void
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
            && (!hash_equals($revision, ImageTagTransaction::revision($path)) || $this->fileState($path) !== $state))) {
            throw $this->conflict();
        }
        $this->files[$path] = ['before' => $state, 'slot' => self::C['slot'] . count($this->files)];
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

    /** Recovery never overwrites a file that arrived independently after the move. */
    public function rollback(): void
    {
        if ($this->directory !== null && @realpath($this->directory) !== $this->directory) throw new RuntimeException(self::C['failed']);
        $unrestored = false;
        foreach (array_reverse($this->moved, true) as $path => $record) {
            // Loop: a no-clobber hardlink restores the same inode and its original aliases when the slot is still vacant.
            $backup = $record['backup']; $state = $this->fileState($backup);
            if ($state === null || @realpath($backup) !== $backup || $record['identity'] === null || $this->identity($state) !== $record['identity']
                || @realpath(dirname($path)) !== dirname($path) || !$this->absent($path) || !@link($backup, $path)) {
                $unrestored = true; continue;
            }
            // Branch: if removing our duplicate fails, leave recovery intact and never undo the restored source.
            if (!@unlink($backup)) $unrestored = true;
        }
        if ($unrestored) throw new RuntimeException(self::C['failed']);
        $this->discardEmptyDirectory();
    }

    /** Binds each move to its last observed inode state, including intentional moves of a sibling hardlink. */
    private function moveFile(string $path, array $entry): void
    {
        $before = $entry['before']; $inode = $before['dev'] . ':' . $before['ino'];
        $expected = $this->inodeStates[$inode] ?? $before;
        $backup = $this->directory . DIRECTORY_SEPARATOR . $entry['slot'];
        if ($this->fileState($path) !== $expected || @realpath($path) !== $path || @realpath($this->directory) !== $this->directory
            || !$this->absent($backup)) throw $this->conflict();
        if (!@rename($path, $backup)) throw new RuntimeException(self::C['failed']);
        $observed = $this->fileState($backup);
        // Branch: remember the actual moved entry for conservative recovery, even if an external writer raced this move.
        $this->moved[$path] = ['backup' => $backup, 'identity' => $observed === null ? null : $this->identity($observed)];
        if ($observed === null || $this->identity($observed) !== $this->identity($before)) throw $this->conflict();
        $this->inodeStates[$inode] = $observed;
    }

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
