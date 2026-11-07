<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine {
    /** Lets the CLI fixture place a real inode swap at the digest boundary of the actual transaction. */
    function hash_file(string $algorithm, string $path, bool $binary = false): string|false
    {
        $digest = \hash_file($algorithm, $path, $binary);
        if (isset($GLOBALS['bznAliasAfterDigest'])) ($GLOBALS['bznAliasAfterDigest'])($path);
        return $digest;
    }

    /** Places a real selected-copy edit just after its bounded comparison read. */
    function hash_update_stream(\HashContext $context, $stream, int $length = -1): int
    {
        $read = \hash_update_stream($context, $stream, $length);
        if (isset($GLOBALS['bznAliasAfterStream'])) ($GLOBALS['bznAliasAfterStream'])(stream_get_meta_data($stream)['uri']);
        return $read;
    }
}

namespace {
    use Bzn\ResourceEngine\EngineConfiguration;
    use Bzn\ResourceEngine\ImageProcessor;
    use Bzn\ResourceEngine\ImageTags;
    use Bzn\ResourceEngine\ImageTagStore;
    use Bzn\ResourceEngine\ImageTagTransaction;
    use Bzn\ResourceEngine\ResourceCollection;
    use Bzn\ResourceEngine\ResourceEngine;

    if (PHP_SAPI !== 'cli') return;
    foreach (['ResourceCollection', 'EngineConfiguration', 'ImageProcessor', 'ResourceEngine'] as $class) {
        // Loop: the CLI uses the actual selected engine code, with no web service or user assets.
        require_once dirname(__DIR__) . '/src/' . $class . '.php';
    }

    final class PublicAliasTagContract
    {
        private const C = ['prefix' => 'bzn-public-tag-alias-', 'mode' => 0700, 'random' => 8, 'width' => 4, 'height' => 3,
            'type' => 'images', 'name' => 'selected.png', 'other' => 'unrelated.png', 'thumb' => 'thumbnail', 'opt' => 'optimized',
            'before' => ['0', 'old'], 'after' => ['0', 'new'], 'external' => ['0', 'ext'], 'replace' => 'replace', 'add' => 'add',
            'conflict' => 409, 'tick' => 1, 'second_digest' => 2, 'zero' => 0,
            'safe_fraction' => 0.5, 'microseconds' => 1000000, 'clock_margin' => 20000, 'white' => 255];
        private string $root;
        private string $source;
        private string $other;
        private string $id;
        private array $aliases;
        private array $variants;
        private ResourceCollection $collection;
        private ResourceEngine $engine;
        private ImageTags $codec;
        private int $checks = self::C['zero'];

        public function __construct()
        {
            $this->root = realpath(sys_get_temp_dir()) . '/' . self::C['prefix'] . bin2hex(random_bytes(self::C['random']));
            $category = $this->root . '/category'; $originals = $category . '/originals'; $previews = $category . '/previews';
            mkdir($originals, self::C['mode'], true); mkdir($previews, self::C['mode']);
            $configuration = new EngineConfiguration(['runtime_directory' => $this->root . '/runtime', 'collections' => [self::C['type'] => [
                'source_directory' => $originals, 'allowed_mime_by_extension' => ['png' => 'image/png'],
            ]]]);
            $this->collection = $configuration->collection(self::C['type']);
            $this->engine = new ResourceEngine($configuration, new ImageProcessor());
            $this->codec = new ImageTags();
            $this->source = $originals . '/' . self::C['name'];
            $this->other = $originals . '/' . self::C['other'];
            $this->id = $this->collection->resourceId(self::C['name']);
            $raw = $this->png();
            file_put_contents($this->source, $this->codec->embed($raw, self::C['before']));
            file_put_contents($this->other, $raw);
            $this->engine->rebuild(self::C['type']);
            $this->engine->resource(self::C['type'], $this->id, self::C['thumb']);
            $this->engine->optimize(self::C['type'], $this->id);
            $this->aliases = [self::C['opt'] => $category . '/Selected.webp', self::C['thumb'] => $previews . '/Selected.webp'];
            $this->variants = [self::C['opt'] => $this->collection->derivedPath($this->id, self::C['opt']),
                self::C['thumb'] => $this->collection->derivedPath($this->id, self::C['thumb'])];
            link($this->variants[self::C['opt']], $this->aliases[self::C['opt']]);
            copy($this->variants[self::C['thumb']], $this->aliases[self::C['thumb']]);
        }

        private function png(): string
        {
            $image = imagecreatetruecolor(self::C['width'], self::C['height']);
            ob_start(); imagepng($image); $bytes = (string) ob_get_clean(); imagedestroy($image);
            return $bytes;
        }

        private function check(bool $condition, string $message): void
        { if (!$condition) throw new \RuntimeException($message); $this->checks++; }

        /** The callback receives the trusted record under the same collection lock as its transaction. */
        private function edit(array $tags, string $operation = self::C['replace'], ?array $aliases = null): array
        {
            $aliases ??= $this->aliases;
            $revision = $operation === self::C['replace'] ? ImageTagTransaction::revision($this->source) : null;
            return $this->engine->updateTagsWithPublicAliases(self::C['type'], $this->id, $tags, $operation, $revision,
                function (ResourceCollection $collection, string $name) use ($aliases): array {
                    $lock = new \ReflectionProperty(ResourceCollection::class, 'mutationLocked');
                    if ($collection !== $this->collection || $name !== self::C['name'] || $lock->getValue($collection) !== true) {
                        throw new \LogicException('The host resolved an alias outside the selected lock.');
                    }
                    return $aliases;
                });
        }

        private function rejects(callable $operation, ?int $code = null): void
        {
            try { $operation(); }
            catch (\RuntimeException $error) {
                $this->check($code === null || $error->getCode() === $code, 'Unexpected conflict code.'); return;
            }
            throw new \RuntimeException('An unsafe alias edit passed.');
        }

        /** The displayed opaque id survives a same-name replacement; only the exact selected revision permits a bulk union. */
        private function selectedAdd(): void
        {
            $name = 'selected-guard.png'; $path = $this->collection->sourceDirectory() . '/' . $name;
            $id = $this->collection->resourceId($name);
            file_put_contents($path, $this->codec->embed($this->png(), self::C['before']));
            $selected = ImageTagTransaction::revision($path); $mtime = filemtime($path);
            $stage = $this->root . '/guard-replacement.tmp';
            $replacement = imagecreatetruecolor(self::C['width'], self::C['height']);
            $white = imagecolorallocate($replacement, self::C['white'], self::C['white'], self::C['white']);
            imagefill($replacement, self::C['zero'], self::C['zero'], $white);
            ob_start(); imagepng($replacement); $bytes = (string) ob_get_clean(); imagedestroy($replacement);
            $fresh = $this->codec->embed($bytes, ['replacement']);
            file_put_contents($stage, $fresh); touch($stage, $mtime); rename($stage, $path);
            $guard = static fn () => [];
            $edit = fn (string $operation, string $revision, array $tags) => $this->engine->updateTagsWithPublicAliases(
                self::C['type'], $id, $tags, $operation, $revision, $guard);
            $newRevision = ImageTagTransaction::revision($path);
            $this->check($selected !== $newRevision && filemtime($path) === $mtime, 'Fixture did not keep the id/mtime and change the source identity.');
            $this->rejects(fn () => $edit(ImageTagStore::SELECTED_ADD, $selected, ['wrong']), self::C['conflict']);
            $this->check(file_get_contents($path) === $fresh, 'Rejected selected ADD changed replacement bytes.');
            $edit(ImageTagStore::SELECTED_ADD, $newRevision, ['fresh']);
            $this->check($this->codec->readFile($path) === ['fresh', 'replacement'], 'Selected ADD replaced the current tags.');
            $this->rejects(fn () => $edit(ImageTagStore::SELECTED_ADD, $newRevision, ['stale']), self::C['conflict']);
            $edit(self::C['add'], $selected, ['legacy-add']);
            $this->check($this->codec->readFile($path) === ['fresh', 'legacy-add', 'replacement'], 'The ordinary fresh ADD changed semantics.');
        }

        private function files(): array
        { return [$this->source, ...array_values($this->variants), ...array_values($this->aliases)]; }

        private function bytes(array $paths): array
        {
            $records = [];
            foreach ($paths as $path) { $records[$path] = file_get_contents($path); }
            return $records;
        }

        /** A physical hardlink and a separate copy both receive only the selected tag envelope. */
        private function success(): void
        {
            $paths = $this->files(); $before = $this->bytes($paths); $unrelated = file_get_contents($this->other);
            $this->check(ImageTagTransaction::revision($this->variants[self::C['opt']]) === ImageTagTransaction::revision($this->aliases[self::C['opt']])
                && ImageTagTransaction::revision($this->variants[self::C['thumb']]) !== ImageTagTransaction::revision($this->aliases[self::C['thumb']]),
                'Fixture did not include both physical alias layouts.');
            $mtime = array_map('filemtime', $paths);
            sleep(self::C['tick']); // Ensure Linux can observe its own unlink's changed ctime.
            $this->edit(self::C['after']);
            foreach ($paths as $index => $path) {
                // Loop: public and runtime representations retain pixels, container data and mtimes independently.
                clearstatcache(true, $path);
                $this->check(file_get_contents($path) === $this->codec->embed($before[$path], self::C['after']) && filemtime($path) === $mtime[$index],
                    'Selected family was not updated exactly.');
            }
            $this->check(file_get_contents($this->other) === $unrelated, 'The tag action changed an unrelated original.');
            $revisions = array_map([ImageTagTransaction::class, 'revision'], $paths);
            $this->edit(self::C['after'], self::C['add']);
            $this->check($revisions === array_map([ImageTagTransaction::class, 'revision'], $paths), 'A byte-exact ADD rewrote a selected file.');
        }

        /** Stale and unsafe aliases stop before writes; missing aliases are never generated. */
        private function admission(): void
        {
            $path = $this->aliases[self::C['thumb']]; $good = file_get_contents($path);
            file_put_contents($path, $this->codec->embed($good, self::C['external']));
            $files = $this->files(); $before = $this->bytes($files);
            $this->rejects(fn () => $this->edit([]), self::C['conflict']);
            $this->check($this->bytes($files) === $before, 'Stale alias rejection changed bytes.');
            unlink($path); symlink($this->variants[self::C['thumb']], $path);
            $this->rejects(fn () => $this->edit([]), self::C['conflict']);
            $this->check(is_link($path), 'A public link was removed during rejection.');
            unlink($path);
            $this->rejects(fn () => $this->edit([], aliases: [self::C['opt'] => $this->root . '/foreign.webp']));
            $this->edit([]);
            $this->check(!file_exists($path) && $this->codec->readFile($this->source) === [], 'Missing preview was created or the clear did not persist.');
            copy($this->variants[self::C['thumb']], $path);
            $this->coldIndex($path);
        }

        /** Directory-driven selection does not publish a missing index before the alias is admitted. */
        private function coldIndex(string $alias): void
        {
            $catalog = $this->collection->catalogFile(); $script = $this->collection->catalogScriptFile();
            $current = file_get_contents($alias);
            unlink($catalog); unlink($script);
            file_put_contents($alias, $this->codec->embed($current, self::C['external']));
            $this->rejects(fn () => $this->edit([]), self::C['conflict']);
            $this->check(!file_exists($catalog) && !file_exists($script), 'Rejected cold edit published an index before admission.');
            file_put_contents($alias, $current);
            $this->edit([]);
            $this->check(is_file($catalog) && is_file($script) && $this->codec->readFile($alias) === [],
                'A valid selected file could not publish its first index.');
        }

        /** A failure on the final catalog artifact restores the selected family and the previous index. */
        private function recovery(): void
        {
            $alias = $this->aliases[self::C['opt']]; unlink($alias); link($this->variants[self::C['opt']], $alias);
            $before = $this->bytes([...$this->files(), $this->collection->metadataFile(), $this->collection->catalogFile()]);
            $script = $this->collection->catalogScriptFile(); $saved = $script . '.kept';
            rename($script, $saved); mkdir($script, self::C['mode']);
            try { $this->rejects(fn () => $this->edit(self::C['before']), self::C['conflict']); }
            finally { rmdir($script); rename($saved, $script); }
            $this->check($this->bytes(array_keys($before)) === $before, 'Late publication failure did not recover selected originals/aliases/catalog.');
        }

        private function swap(string $path): string
        {
            $external = $this->root . '/external.tmp';
            copy($path, $external); touch($external, filemtime($path)); rename($external, $path);
            return ImageTagTransaction::revision($path);
        }

        /** A new inode with the same bytes and mtime is never mistaken for this transaction's file. */
        private function swaps(): void
        {
            $path = $this->root . '/ordinary.bin'; $old = 'old'; $new = 'new'; file_put_contents($path, $old);
            $txn = new ImageTagTransaction($this->root . '/recovery'); $txn->stage($path, $old, $new);
            $revision = null;
            $GLOBALS['bznAliasAfterDigest'] = function (string $selected) use ($path, &$revision): void {
                if ($selected === $path && $revision === null) $revision = $this->swap($path);
            };
            try { $this->rejects(fn () => $txn->install(), self::C['conflict']); }
            finally { unset($GLOBALS['bznAliasAfterDigest']); }
            $this->check(is_string($revision) && ImageTagTransaction::revision($path) === $revision && file_get_contents($path) === $old,
                'Equal-byte inode swap during digest was overwritten.');

            $sidecar = $this->collection->metadataFile();
            file_put_contents($sidecar, json_encode([$this->id => ['tags' => ['retire']]], JSON_THROW_ON_ERROR));
            $before = $this->bytes([$sidecar, $this->collection->catalogFile(), $this->collection->catalogScriptFile()]);
            $selected = $this->aliases[self::C['thumb']]; $revision = null; $calls = self::C['zero'];
            $GLOBALS['bznAliasAfterDigest'] = function (string $file) use ($sidecar, $selected, &$revision, &$calls): void {
                if ($file === $sidecar && ++$calls === self::C['second_digest']) $revision = $this->swap($selected);
            };
            try { $this->rejects(fn () => $this->edit([], self::C['add']), self::C['conflict']); }
            finally { unset($GLOBALS['bznAliasAfterDigest']); }
            $this->check(is_string($revision) && ImageTagTransaction::revision($selected) === $revision
                && $this->bytes(array_keys($before)) === $before, 'No-op alias swap left a stale committed catalog or reverted newer bytes.');
        }

        /** A same-inode same-stat edit during a byte-exact command is visible to the bounded digest guard. */
        private function sameStatNoOp(): void
        {
            $this->edit(self::C['before']);
            $selected = $this->aliases[self::C['thumb']]; $sidecar = $this->collection->metadataFile();
            $old = file_get_contents($selected); $external = $this->codec->embed($old, self::C['external']);
            file_put_contents($sidecar, json_encode([$this->id => ['tags' => ['retire']]], JSON_THROW_ON_ERROR));
            $index = $this->bytes([$sidecar, $this->collection->catalogFile(), $this->collection->catalogScriptFile()]);
            $mtime = $this->sameSecond($selected);
            $expected = ImageTagTransaction::revision($selected); $observed = null; $calls = self::C['zero'];
            $GLOBALS['bznAliasAfterDigest'] = function (string $file) use ($sidecar, $selected, $external, $mtime, &$observed, &$calls): void {
                if ($file === $sidecar && ++$calls === self::C['second_digest']) {
                    file_put_contents($selected, $external); touch($selected, $mtime);
                    $observed = ImageTagTransaction::revision($selected);
                }
            };
            try { $this->rejects(fn () => $this->edit(self::C['before']), self::C['conflict']); }
            finally { unset($GLOBALS['bznAliasAfterDigest']); }
            $this->check($observed === $expected && strlen($old) === strlen($external) && $old !== $external,
                'Fixture did not exercise an actual same-stat byte change.');
            $this->check(file_get_contents($selected) === $external && $this->bytes(array_keys($index)) === $index,
                'A byte-exact command changed the concurrent alias or published a stale index.');
        }

        /** The staging bytes must match the earlier exact alias proof even at unchanged stat. */
        private function sameStatAtAdmission(): void
        {
            $selected = $this->aliases[self::C['thumb']]; $current = file_get_contents($this->variants[self::C['thumb']]);
            file_put_contents($selected, $current);
            $external = $this->codec->embed($current, self::C['external']);
            $paths = array_values(array_diff($this->files(), [$selected]));
            $index = $this->bytes([...$paths, $this->collection->metadataFile(), $this->collection->catalogFile(), $this->collection->catalogScriptFile()]);
            $mtime = $this->sameSecond($selected);
            $expected = ImageTagTransaction::revision($selected); $observed = null;
            $GLOBALS['bznAliasAfterStream'] = function (string $file) use ($selected, $external, $mtime, &$observed): void {
                if ($file === $selected && $observed === null) {
                    file_put_contents($selected, $external); touch($selected, $mtime);
                    $observed = ImageTagTransaction::revision($selected);
                }
            };
            try { $this->rejects(fn () => $this->edit(self::C['after']), self::C['conflict']); }
            finally { unset($GLOBALS['bznAliasAfterStream']); }
            $this->check($observed === $expected && strlen($current) === strlen($external) && $current !== $external,
                'Fixture did not keep the edited alias at the same observed stat.');
            $this->check(file_get_contents($selected) === $external && $this->bytes(array_keys($index)) === $index,
                'A same-stat alias change during proof became an admitted write.');
        }

        /** Keeps two real Linux ctime observations in one second without simulating the filesystem. */
        private function sameSecond(string $path): int
        {
            $at = microtime(true); $fraction = $at - floor($at);
            if ($fraction > self::C['safe_fraction']) {
                // Branch: a brief wait leaves enough of the next second for the tiny transaction.
                usleep((int) ((self::C['tick'] - $fraction) * self::C['microseconds']) + self::C['clock_margin']);
            }
            clearstatcache(true, $path); $mtime = filemtime($path); touch($path, $mtime);
            return $mtime;
        }

        public function run(): void
        {
            try {
                $this->success(); $this->admission(); $this->recovery(); $this->swaps(); $this->sameStatNoOp(); $this->sameStatAtAdmission();
                $this->selectedAdd();
                echo 'PASS public alias tag transaction: ' . $this->checks . ' actual assertions.' . PHP_EOL;
            } finally {
                // Cleanup: only this exact generated fixture tree and no links followed.
                if (realpath($this->root) === $this->root) {
                    $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($entries as $entry) {
                        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
                    }
                    rmdir($this->root);
                }
            }
        }
    }

    (new PublicAliasTagContract())->run();
}
