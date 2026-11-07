<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine {
    /** Actual digest reads expose narrow fixture seams for a same-stat writer at two transaction boundaries. */
    function hash_update_stream(\HashContext $context, $stream, int $length = -1): int
    {
        $read = \hash_update_stream($context, $stream, $length);
        if (isset($GLOBALS['bznArchiveAfterRead'])) ($GLOBALS['bznArchiveAfterRead'])(stream_get_meta_data($stream)['uri']);
        return $read;
    }

    function hash_file(string $algorithm, string $path, bool $binary = false): string|false
    {
        $digest = \hash_file($algorithm, $path, $binary);
        if (isset($GLOBALS['bznArchiveAfterHash'])) ($GLOBALS['bznArchiveAfterHash'])($path);
        return $digest;
    }
}

namespace {
use Bzn\ResourceEngine\EngineConfiguration;
use Bzn\ResourceEngine\ImageProcessor;
use Bzn\ResourceEngine\ImageTagTransaction;
use Bzn\ResourceEngine\ResourceCollection;
use Bzn\ResourceEngine\ResourceEngine;

if (PHP_SAPI !== 'cli') return;
foreach (['ResourceCollection', 'EngineConfiguration', 'ImageProcessor', 'ResourceEngine'] as $class) {
    // Loop: only actual class code is loaded; all storage paths below are isolated fixture paths.
    require_once dirname(__DIR__) . '/src/' . $class . '.php';
}

final class SelectedResourceArchiveContract
{
    private const C = ['prefix' => 'bzn-selected-archive-', 'random' => 8, 'mode' => 0700, 'width' => 3, 'height' => 2,
        'type' => 'resources', 'first' => 'First.png', 'second' => 'Second.png', 'neighbor' => 'Neighbor.png',
        'thumb' => 'thumbnail', 'opt' => 'optimized', 'conflict' => 409, 'zero' => 0, 'one' => 1, 'white' => 255,
        'fraction' => 0.45, 'us_per_second' => 1000000, 'clock_margin' => 20000];
    private string $root;
    private ResourceCollection $collection;
    private ResourceEngine $engine;
    private array $aliases;
    private array $variants;
    private int $checks = self::C['zero'];

    /** Builds tiny originals and a real optimized/thumbnail family inside a unique temp root. */
    public function __construct()
    {
        $this->root = realpath(sys_get_temp_dir()) . '/' . self::C['prefix'] . bin2hex(random_bytes(self::C['random']));
        $public = $this->root . '/images/pictures'; $source = $public . '/originals'; $preview = $public . '/previews';
        mkdir($source, self::C['mode'], true); mkdir($preview, self::C['mode']);
        $configuration = new EngineConfiguration(['runtime_directory' => $this->root . '/private-runtime', 'collections' => [self::C['type'] => [
            'source_directory' => $source, 'allowed_mime_by_extension' => ['png' => 'image/png'],
        ]]]);
        $this->collection = $configuration->collection(self::C['type']);
        $this->engine = new ResourceEngine($configuration, new ImageProcessor());
        foreach ([self::C['first'], self::C['second'], self::C['neighbor']] as $name) {
            // Loop: no production catalog or image is used by the test.
            file_put_contents($this->path($name), $this->raster());
        }
        $id = $this->collection->resourceId(self::C['first']);
        $other = $this->collection->resourceId(self::C['neighbor']);
        $this->engine->rebuild(self::C['type']);
        file_put_contents($this->collection->metadataFile(), json_encode([$id => ['tags' => ['keep for restore']], $other => ['tags' => ['neighbor']]], JSON_THROW_ON_ERROR));
        $this->engine->rebuild(self::C['type']);
        $this->engine->resource(self::C['type'], $id, self::C['thumb']); $this->engine->optimize(self::C['type'], $id);
        $this->variants = [self::C['opt'] => $this->collection->derivedPath($id, self::C['opt']), self::C['thumb'] => $this->collection->derivedPath($id, self::C['thumb'])];
        $this->aliases = [self::C['opt'] => $public . '/First.webp', self::C['thumb'] => $preview . '/First.webp'];
        link($this->variants[self::C['opt']], $this->aliases[self::C['opt']]);
        copy($this->variants[self::C['thumb']], $this->aliases[self::C['thumb']]);
    }

    /** A second PNG has different pixels with the same dimensions and name. */
    private function raster(bool $white = false): string
    {
        $image = imagecreatetruecolor(self::C['width'], self::C['height']);
        if ($white) {
            $c = self::C['white']; imagefill($image, self::C['zero'], self::C['zero'], imagecolorallocate($image, $c, $c, $c));
        }
        ob_start(); imagepng($image); $bytes = (string) ob_get_clean(); imagedestroy($image); return $bytes;
    }

    private function path(string $name): string { return $this->collection->sourceDirectory() . '/' . $name; }
    private function selection(string $name): array
    { return ['id' => $this->collection->resourceId($name), 'revision' => ImageTagTransaction::revision($this->path($name))]; }
    private function pair(): array { return [$this->selection(self::C['first']), $this->selection(self::C['second'])]; }
    private function paths(): array { return [$this->path(self::C['first']), $this->path(self::C['second']), ...array_values($this->variants), ...array_values($this->aliases)]; }
    private function archive(array $items): array
    {
        return $this->engine->archiveSelectedWithPublicAliases(self::C['type'], $items, function (ResourceCollection $owner, string $name): array {
            $locked = new ReflectionProperty(ResourceCollection::class, 'mutationLocked');
            if ($owner !== $this->collection || !$locked->getValue($owner)) throw new LogicException('Archive proof escaped its lock.');
            return $name === self::C['first'] ? $this->aliases : [];
        });
    }
    private function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); $this->checks++; }
    private function savedDirectories(): array { return glob($this->collection->trashDirectory() . '/selection-*', GLOB_ONLYDIR) ?: []; }

    /** An older selection for one replaced image must leave both selected families in place. */
    private function stale(): void
    {
        $selection = $this->pair(); $path = $this->path(self::C['second']);
        $temp = $this->root . '/replacement.tmp'; file_put_contents($temp, $this->raster(true)); touch($temp, filemtime($path)); rename($temp, $path);
        $paths = $this->paths(); $bytes = array_map('file_get_contents', $paths); $status = null;
        try { $this->archive($selection); } catch (RuntimeException $error) { $status = $error->getCode(); }
        $this->check($status === self::C['conflict'] && array_map('file_get_contents', $paths) === $bytes
            && !$this->savedDirectories(), 'A stale selection moved a file or created recovery content.');
    }

    /** A later catalog-stage failure restores the same source and alias topology without touching a foreign target. */
    private function recovery(): void
    {
        $script = $this->collection->catalogScriptFile(); $old = file_get_contents($script);
        $target = $this->root . '/foreign.txt'; $foreign = 'outside archive'; file_put_contents($target, $foreign);
        unlink($script); symlink($target, $script);
        $paths = $this->paths(); $before = array_map('file_get_contents', $paths);
        $metadata = file_get_contents($this->collection->metadataFile()); $failed = false;
        try { $this->archive($this->pair()); } catch (RuntimeException) { $failed = true; }
        $this->check($failed && is_link($script) && file_get_contents($target) === $foreign
            && array_map('file_get_contents', $paths) === $before && file_get_contents($this->collection->metadataFile()) === $metadata
            && fileinode($this->variants[self::C['opt']]) === fileinode($this->aliases[self::C['opt']])
            && !$this->savedDirectories(), 'Catalog failure lost a source, its shared alias or prior metadata.');
        unlink($script); file_put_contents($script, $old);
    }

    /** Drift in a detached alias is visible even with the same inode, size and timestamp. */
    private function copyDrift(bool $late): void
    {
        $path = $this->aliases[self::C['thumb']]; $metadata = $this->collection->metadataFile();
        $all = [...$this->paths(), $metadata, $this->collection->catalogFile(), $this->collection->catalogScriptFile()];
        $before = array_combine($all, array_map('file_get_contents', $all));
        $external = $before[$path]; $last = strlen($external) - self::C['one'];
        $external[$last] = $external[$last] === 'A' ? 'B' : 'A';
        $now = microtime(true); $fraction = $now - floor($now);
        if ($fraction > self::C['fraction']) {
            // Branch: keep the tiny physical fixture operation inside the next stat second.
            usleep((int) ((self::C['one'] - $fraction) * self::C['us_per_second']) + self::C['clock_margin']);
        }
        $mtime = filemtime($path); touch($path, $mtime);
        $matched = false; $mutated = false; $status = null;
        $mutate = static function (string $target) use ($external, &$matched, &$mutated): void {
            $mutated = true;
            $expected = ImageTagTransaction::revision($target); $time = filemtime($target);
            file_put_contents($target, $external); touch($target, $time);
            $matched = $expected === ImageTagTransaction::revision($target);
        };
        if ($late) {
            $GLOBALS['bznArchiveAfterHash'] = function (string $candidate) use ($path, $metadata, $mutate, &$mutated): void {
                if ($candidate !== $metadata || $mutated) return;
                $directory = $this->savedDirectories()[self::C['zero']] ?? null;
                if ($directory === null) return;
                $journal = json_decode(file_get_contents($directory . '/restore.json'), true, flags: JSON_THROW_ON_ERROR);
                $mutate($directory . '/' . $journal['locations'][$path]);
            };
        } else {
            $GLOBALS['bznArchiveAfterRead'] = static function (string $candidate) use ($path, $mutate, &$mutated): void {
                if ($candidate === $path && !$mutated) $mutate($path);
            };
        }
        try { $this->archive($this->pair()); }
        catch (RuntimeException $error) { $status = $error->getCode(); }
        finally { unset($GLOBALS['bznArchiveAfterRead'], $GLOBALS['bznArchiveAfterHash']); }
        $expected = $before; $expected[$path] = $external;
        $after = array_combine($all, array_map('file_get_contents', $all));
        $this->check($matched && $status === self::C['conflict'] && $after === $expected && !$this->savedDirectories(),
            'A same-stat external copy drift was committed, overwritten, or left the catalogs changed.');
        file_put_contents($path, $before[$path]); touch($path, $mtime);
    }

    /** A new source at the old name keeps its own data, without the archived source's aliases or sidecar. */
    private function occupiedRecovery(bool $denyDerived): void
    {
        $source = $this->path(self::C['first']); $other = $this->path(self::C['second']);
        $old = file_get_contents($source); $second = file_get_contents($other); $mtime = filemtime($source);
        $replacement = $this->raster(true); $variants = [...array_values($this->variants), ...array_values($this->aliases)];
        $previous = array_combine($variants, array_map('file_get_contents', $variants));
        $selection = $this->pair(); $metadata = $this->collection->metadataFile(); $inserted = false; $status = null;
        $script = $this->collection->catalogScriptFile(); $foreignPath = $this->root . '/foreign-catalog.txt';
        $foreign = 'not an engine artifact';
        if ($denyDerived) {
            // Branch: both the original transaction and its catalog repair must fail persistently.
            file_put_contents($foreignPath, $foreign); unlink($script); symlink($foreignPath, $script);
        }
        $GLOBALS['bznArchiveAfterHash'] = static function (string $candidate) use ($metadata, $source, $replacement, $mtime, &$inserted): void {
            if ($candidate !== $metadata || $inserted || file_exists($source)) return;
            $inserted = true; file_put_contents($source, $replacement); touch($source, $mtime);
        };
        try { $this->archive($selection); }
        catch (RuntimeException $error) { $status = $error->getCode(); }
        finally { unset($GLOBALS['bznArchiveAfterHash']); }
        $this->check($inserted && ($denyDerived ? $status !== null : $status === self::C['conflict']) && file_get_contents($source) === $replacement
            && file_get_contents($other) === $second && !array_filter(array_map('file_exists', $variants)),
            'A replacement source inherited old variants, or another selected family was not restored.');
        $archive = $this->savedDirectories()[self::C['zero']] ?? '';
        $journal = json_decode(file_get_contents($archive . '/restore.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->check(file_get_contents($archive . '/' . $journal['locations'][$source]) === $old
            && $journal['records'][self::C['zero']]['metadata']['tags'] === ['keep for restore'], 'The old source family or tags were lost.');
        foreach ($previous as $path => $bytes) {
            // Loop: obsolete public aliases remain private when the selected original could not be restored.
            $this->check(file_get_contents($archive . '/' . $journal['locations'][$path]) === $bytes, 'Old cache did not stay in recovery.');
        }
        $id = $selection[self::C['zero']]['id'];
        $currentTags = json_decode(file_get_contents($metadata), true, flags: JSON_THROW_ON_ERROR);
        $this->check(!isset($currentTags[$id]) && $currentTags[$this->collection->resourceId(self::C['neighbor'])]['tags'] === ['neighbor'],
            'The replacement inherited legacy tags or changed the neighbor.');
        $direct = $this->engine->tagFieldsForLegacyList($this->collection, self::C['first'], $currentTags[$id] ?? []);
        $rows = array_column($this->engine->list(self::C['type'])['items'], null, 'id');
        $this->check($direct['tags'] === [] && $rows[$id]['tags'] === [] && (!$denyDerived
            || is_link($script) && file_get_contents($foreignPath) === $foreign), 'A derived catalog failure reattached stale legacy tags.');
        foreach (['original', 'resource'] as $kind) {
            // Loop: the actual original and preferred resource must resolve to the same new physical version.
            $descriptor = $this->engine->resource(self::C['type'], $id, $kind);
            $this->check(file_get_contents($descriptor['path']) === $replacement, 'The resource route returned the old cache for the new source.');
        }
    }

    /** Successful archiving retains exact bytes, one recovery manifest and unrelated data. */
    private function success(): void
    {
        $paths = $this->paths(); $before = array_combine($paths, array_map('file_get_contents', $paths));
        $neighbor = $this->path(self::C['neighbor']); $unrelated = file_get_contents($neighbor);
        $expected = $this->pair(); $reply = $this->archive($expected);
        $directories = $this->savedDirectories();
        $this->check(count($directories) === self::C['one'] && array_column($reply['items'], 'id') === array_column($expected, 'id'),
            'Archive did not return exactly its selected public IDs.');
        $recovery = $directories[self::C['zero']];
        $journal = json_decode(file_get_contents($recovery . '/restore.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($before as $path => $bytes) {
            // Loop: every original and prepared alias has exact bytes in its recorded recovery slot.
            $this->check(!file_exists($path) && file_get_contents($recovery . '/' . $journal['locations'][$path]) === $bytes, 'Archive lost selected bytes.');
        }
        $catalog = json_decode(file_get_contents($this->collection->catalogFile()), true, flags: JSON_THROW_ON_ERROR);
        $metadata = json_decode(file_get_contents($this->collection->metadataFile()), true, flags: JSON_THROW_ON_ERROR);
        $this->check($journal['records'][self::C['zero']]['metadata']['tags'] === ['keep for restore']
            && $catalog['total'] === self::C['one'] && array_column($catalog['resources'], 'name') === [self::C['neighbor']]
            && $metadata[$this->collection->resourceId(self::C['neighbor'])]['tags'] === ['neighbor']
            && !isset($metadata[$expected[self::C['zero']]['id']]) && file_get_contents($neighbor) === $unrelated,
            'Archive dropped unrelated data or retained selected legacy tags on a reusable name.');
    }

    public function run(?bool $denyDerived = null): void
    {
        try {
            if ($denyDerived !== null) $this->occupiedRecovery($denyDerived);
            else { $this->stale(); $this->recovery(); $this->copyDrift(false); $this->copyDrift(true); $this->success(); }
            echo 'PASS protected common archive: ' . $this->checks . ' actual assertions.' . PHP_EOL;
        } finally {
            // Cleanup: only the exact generated fixture and no symlink traversal.
            if (realpath($this->root) === $this->root) {
                $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($entries as $entry) {
                    if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
                }
                rmdir($this->root);
            }
        }
    }
}

(new SelectedResourceArchiveContract())->run();
(new SelectedResourceArchiveContract())->run(false);
(new SelectedResourceArchiveContract())->run(true);
}
