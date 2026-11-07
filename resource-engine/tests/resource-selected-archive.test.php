<?php
declare(strict_types=1);

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
        'thumb' => 'thumbnail', 'opt' => 'optimized', 'conflict' => 409, 'zero' => 0, 'one' => 1, 'white' => 255];
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

    public function run(): void
    {
        try {
            $this->stale(); $this->recovery(); $this->success();
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
