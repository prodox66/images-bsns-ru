<?php
declare(strict_types=1);

use Bzn\ResourceEngine\AdminApi;
use Bzn\ResourceEngine\AdminAuthenticator;
use Bzn\ResourceEngine\EngineConfiguration;
use Bzn\ResourceEngine\HostedPublicAliases;
use Bzn\ResourceEngine\HttpResponder;
use Bzn\ResourceEngine\ImageProcessor;
use Bzn\ResourceEngine\ImageTags;
use Bzn\ResourceEngine\ResourceCollection;
use Bzn\ResourceEngine\ResourceEngine;
use Bzn\ResourceEngine\ResourceUrlBuilder;

if (PHP_SAPI !== 'cli') return;
foreach (['ResourceCollection', 'EngineConfiguration', 'ImageProcessor', 'ResourceEngine', 'ResourceUrlBuilder', 'HttpResponder', 'AdminAuthenticator', 'AdminApi'] as $class) {
    // Loop: the fixture drives the current portable admin and engine, with no HTTP listener.
    require_once dirname(__DIR__) . '/src/' . $class . '.php';
}

/** Uses the published shared storage class with a small, separately generated two-owner fixture. */
final class HostedAliasContract
{
    private const C = ['env' => 'BZN_HOSTED_ALIAS_UI', 'repo_parents' => 3, 'root' => 'bzn-hosted-alias-', 'random' => 8,
        'mode' => 0700, 'width' => 3, 'height' => 2, 'type' => 'resources', 'name' => 'Selected.png', 'renamed' => 'Renamed.png',
        'other' => 'Other.png', 'gif' => 'Legacy.gif', 'opt' => 'optimized', 'thumb' => 'thumbnail', 'default' => 'default-tag', 'zero' => 0];
    private string $root;
    private string $source;
    private string $public;
    private string $settingsFile;
    private array $settings;
    private ResourceCollection $collection;
    private ResourceEngine $engine;
    private HostedPublicAliases $layout;
    private AdminApi $admin;
    private ImageTags $codec;
    private int $checks = self::C['zero'];

    public function __construct(string $editor)
    {
        $this->root = realpath(sys_get_temp_dir()) . '/' . self::C['root'] . bin2hex(random_bytes(self::C['random']));
        $site = $this->root . '/site'; $fixtureEditor = $site . '/ui'; $this->public = $site . '/library/images/pictures';
        $this->source = $this->public . '/originals';
        foreach ([$this->source, $fixtureEditor . '/lib', $fixtureEditor . '/config'] as $directory) {
            // Loop: the portable source symlink and the editor both address only this disposable category.
            mkdir($directory, self::C['mode'], true);
        }
        $alias = $this->root . '/portable-images'; symlink($this->source, $alias);
        $engineDir = dirname(__DIR__);
        // The source may define variables. Isolate it so no external config can change a fixture write target.
        $this->settings = (static function (string $path): array { return require $path; })($editor . '/config/shared-gallery.php');
        $this->settings = array_replace($this->settings, ['engine_directory' => $engineDir, 'images_directory' => dirname($this->public)]);
        copy($editor . '/lib/SharedImageStorage.php', $this->fixtureFile('site/ui/lib/SharedImageStorage.php'));
        $this->settingsFile = $this->fixtureFile('site/ui/config/shared-gallery.php');
        $this->setSettings($this->settings);
        $configuration = new EngineConfiguration(['runtime_directory' => $this->root . '/runtime', 'collections' => [self::C['type'] => [
            'source_directory' => $alias, 'tags' => [self::C['default']], 'allowed_mime_by_extension' => ['png' => 'image/png', 'gif' => 'image/gif'],
        ]]]);
        $this->collection = $configuration->collection(self::C['type']);
        $this->engine = new ResourceEngine($configuration, new ImageProcessor());
        $this->codec = new ImageTags();
        $this->layout = HostedPublicAliases::discover($this->collection, $engineDir) ?? throw new RuntimeException('The exact shared source was not discovered.');
        $this->admin = new AdminApi($this->engine, new AdminAuthenticator($configuration), new HttpResponder(), new ResourceUrlBuilder('/fixture/api.php'));
    }

    private function setSettings(mixed $settings): void
    {
        $fixture = $this->fixtureFile('site/ui/config/shared-gallery.php');
        if ($this->settingsFile !== $fixture) throw new RuntimeException('Unsafe fixture settings path.');
        // These locals must not affect the loader's verified source and editor directory.
        file_put_contents($fixture, '<?php $editor = $source = "fixture-local"; return ' . var_export($settings, true) . ';');
    }

    /** Configuration and copied code can only be written below this exact generated fixture tree. */
    private function fixtureFile(string $relative): string
    {
        $path = $this->root . '/' . $relative;
        $parent = realpath(dirname($path));
        if ($parent !== dirname($path) || !str_starts_with($parent, $this->root . '/') || is_link($path)) {
            throw new RuntimeException('Unsafe fixture write target.');
        }
        return $path;
    }

    private function raster(string $encoder): string
    {
        $image = imagecreatetruecolor(self::C['width'], self::C['height']);
        ob_start(); $encoder($image); $bytes = (string) ob_get_clean(); imagedestroy($image);
        return $bytes;
    }

    private function check(bool $condition, string $message): void
    { if (!$condition) throw new RuntimeException($message); $this->checks++; }

    /** The shared adapter must not select an independent portable source, foreign collection or split lock. */
    private function boundary(): void
    {
        $own = $this->root . '/independent'; mkdir($own, self::C['mode']);
        $portable = new ResourceCollection(self::C['type'], ['source_directory' => $own], $this->root . '/runtime');
        $this->check(HostedPublicAliases::discover($portable, dirname(__DIR__)) === null, 'Independent portable storage gained hosted aliases.');
        $foreign = new ResourceCollection('textures', ['source_directory' => $this->source], $this->root . '/runtime');
        $this->rejects(fn () => HostedPublicAliases::discover($foreign, dirname(__DIR__)), 'Wrong type bypassed the public category owner.');
        $separate = array_replace($this->settings, ['images_directory' => $own]);
        $missingEngine = array_diff_key($this->settings, ['engine_directory' => true]);
        try {
            $this->setSettings($separate);
            $this->check(HostedPublicAliases::discover($this->collection, dirname(__DIR__)) === null, 'A configured separate root selected this layout.');
            foreach ([false, $missingEngine, array_replace($this->settings, ['engine_directory' => $own]),
                array_replace($this->settings, ['runtime_directory' => $this->root . '/separate'])] as $invalid) {
                // Loop: a present malformed or split policy is never interpreted as an independent portable layout.
                $this->setSettings($invalid);
                $this->rejects(fn () => HostedPublicAliases::discover($this->collection, dirname(__DIR__)), 'An invalid hosted policy fell back to a standalone write.');
            }
        } finally { $this->setSettings($this->settings); }
        $alias = $this->collection->sourceDirectory();
        if (!is_link($alias) || realpath($alias) !== $this->source || !str_starts_with($alias, $this->root . '/')) throw new RuntimeException('Unsafe fixture source link.');
        unlink($alias); symlink($own, $alias);
        try {
            try {
                $this->layout->locked(static function (): never { throw new LogicException('Changed source link reached mutation.'); });
            } catch (RuntimeException) { $this->checks++; }
        } finally { unlink($alias); symlink($this->source, $alias); }
    }

    private function rejects(callable $operation, string $message): void
    {
        try { $operation(); }
        catch (RuntimeException) { $this->checks++; return; }
        throw new RuntimeException($message);
    }

    /** Actual generated variants stand in for the currently published hardlink and copy. */
    private function makeAliases(string $name): array
    {
        $id = $this->collection->resourceId($name);
        $this->engine->resource(self::C['type'], $id, self::C['thumb']);
        $this->engine->optimize(self::C['type'], $id);
        $paths = $this->layout->paths($this->collection, $name);
        if (!is_dir(dirname($paths[self::C['thumb']]))) mkdir(dirname($paths[self::C['thumb']]), self::C['mode']);
        link($this->collection->derivedPath($id, self::C['opt']), $paths[self::C['opt']]);
        copy($this->collection->derivedPath($id, self::C['thumb']), $paths[self::C['thumb']]);
        return $paths;
    }

    /** The existing legacy writer keeps its default tags and read-only fallback while joining current public bytes. */
    private function legacyTags(string $id, array $paths): void
    {
        $selected = new ReflectionMethod(AdminApi::class, 'setSelectedTags');
        $catalog = $selected->invoke($this->admin, self::C['type'], $id, ['blue']);
        $item = array_column($catalog['resources'], null, 'id')[$id];
        $this->check($item['tags'] === ['blue', self::C['default']], 'Hosted setter lost the legacy default tags.');
        foreach ($paths as $variant => $path) {
            // Loop: both alias formats carry the exact same output as their selected ready counterpart.
            $this->check(file_get_contents($path) === file_get_contents($this->collection->derivedPath($id, $variant))
                && $this->codec->readFile($path) === $item['tags'], 'Hosted alias is stale after the legacy edit.');
        }
        $clear = $selected->invoke($this->admin, self::C['type'], $id, []);
        $this->check(array_column($clear['resources'], null, 'id')[$id]['tags'] === [self::C['default']], 'Legacy clear changed its default contract.');
        $gif = $this->source . '/' . self::C['gif']; $bytes = file_get_contents($gif);
        $gifId = $this->collection->resourceId(self::C['gif']);
        $fallback = $selected->invoke($this->admin, self::C['type'], $gifId, ['readonly']);
        $this->check(in_array('readonly', array_column($fallback['resources'], null, 'id')[$gifId]['tags'], true)
            && file_get_contents($gif) === $bytes, 'Legacy readonly sidecar path was lost.');
    }

    /** The actual protected handler's inner operation retires only the old aliases in its source lock. */
    private function sourceMoves(string $id, array $paths): void
    {
        $operation = new ReflectionMethod(AdminApi::class, 'changeSource');
        $noop = $operation->invoke($this->admin, self::C['type'], $id, 'rename', self::C['name']);
        $this->check($noop['id'] === $id && is_file($paths[self::C['opt']]) && is_file($paths[self::C['thumb']]), 'No-op rename retired valid aliases.');
        $renamed = $operation->invoke($this->admin, self::C['type'], $id, 'rename', self::C['renamed']);
        $this->check($renamed['renamed'] === self::C['renamed'] && !file_exists($paths[self::C['opt']]) && !file_exists($paths[self::C['thumb']])
            && is_file($this->source . '/' . self::C['renamed']), 'Hosted rename left its old aliases.');
        $next = $this->makeAliases(self::C['renamed']);
        $deleted = $operation->invoke($this->admin, self::C['type'], $renamed['id'], 'delete');
        $this->check($deleted['deleted'] === self::C['renamed'] && !file_exists($next[self::C['opt']]) && !file_exists($next[self::C['thumb']])
            && is_file($this->source . '/' . self::C['other']), 'Hosted deletion affected a different resource or left aliases.');
    }

    public function run(): void
    {
        try {
            $this->boundary();
            $raw = $this->raster('imagepng');
            file_put_contents($this->source . '/' . self::C['name'], $raw);
            file_put_contents($this->source . '/' . self::C['other'], $raw);
            file_put_contents($this->source . '/' . self::C['gif'], $this->raster('imagegif'));
            $this->engine->rebuild(self::C['type']);
            $id = $this->collection->resourceId(self::C['name']);
            $paths = $this->makeAliases(self::C['name']);
            $this->legacyTags($id, $paths);
            $this->sourceMoves($id, $paths);
            echo 'PASS hosted portable alias ownership: ' . $this->checks . ' actual assertions.' . PHP_EOL;
        } finally {
            // Cleanup: no production directory is traversed and links are removed without following them.
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

$editor = getenv('BZN_HOSTED_ALIAS_UI');
if (!is_string($editor) || $editor === '') $editor = dirname(__DIR__, 3) . '/ui';
(new HostedAliasContract($editor))->run();
