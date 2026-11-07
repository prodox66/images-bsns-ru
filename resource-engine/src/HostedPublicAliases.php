<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use RuntimeException;

/** A protected portable admin action can use the existing public layout only for an exact hosted collection. */
final class HostedPublicAliases
{
    private const SITE_PARENTS = 4;
    private const IMAGES_PARENTS = 2;
    private const EDITOR = 'ui';
    private const SETTINGS = 'config/shared-gallery.php';
    private const STORAGE = 'lib/SharedImageStorage.php';
    private const UNAVAILABLE = 'Shared image storage is unavailable.';

    private function __construct(private readonly ResourceCollection $collection, private readonly HostedPublicCollectionView $view,
        private readonly \BznSharedImageStorage $storage) {}

    /** Canonical originals, configured category and engine ownership must all match; independent portable installs stay independent. */
    public static function discover(ResourceCollection $collection, string $engineDirectory): ?self
    {
        $source = @realpath($collection->sourceDirectory());
        if (!is_string($source)) return null;
        $site = dirname($source, self::SITE_PARENTS);
        $editor = $site . DIRECTORY_SEPARATOR . self::EDITOR;
        $settingsPath = $editor . DIRECTORY_SEPARATOR . self::SETTINGS;
        if (!@file_exists($settingsPath) && !@is_link($settingsPath)) return null;
        // Branch: a present but unverifiable hosted configuration cannot authorize an alias-unaware write.
        if (@realpath($settingsPath) !== $settingsPath || !@is_file($settingsPath) || !@is_readable($settingsPath)) throw new RuntimeException(self::UNAVAILABLE);
        $settings = (static function (string $path): mixed { return require $path; })($settingsPath);
        if (!is_array($settings)) throw new RuntimeException(self::UNAVAILABLE);
        if (!self::matches($collection, $source, $engineDirectory, $settings)) return null;
        self::assertSameLock($collection, $settings);
        $implementation = $editor . DIRECTORY_SEPARATOR . self::STORAGE;
        // Branch: a verified hosted collection cannot fall back to an alias-unaware writer if its owner is unavailable.
        if (@realpath($implementation) !== $implementation || !@is_readable($implementation)) throw new RuntimeException(self::UNAVAILABLE);
        require_once $implementation;
        if (!class_exists(\BznSharedImageStorage::class, false)) throw new RuntimeException(self::UNAVAILABLE);
        return new self($collection, new HostedPublicCollectionView($collection, $source), new \BznSharedImageStorage($settings));
    }

    /** No request can supply the editor root or associate a portable type with a different public category. */
    private static function matches(ResourceCollection $collection, string $source, string $engineDirectory, array $settings): bool
    {
        $images = dirname($source, self::IMAGES_PARENTS);
        $category = basename(dirname($source));
        $configuredEngine = $settings['engine_directory'] ?? null;
        $configuredImages = $settings['images_directory'] ?? null;
        $originals = $settings['originals_folder'] ?? null;
        $known = $settings['known'] ?? null;
        $engine = @realpath($engineDirectory);
        $hostEngine = is_string($configuredEngine) ? @realpath($configuredEngine) : false;
        $hostImages = is_string($configuredImages) ? @realpath($configuredImages) : false;
        if (!is_string($engine) || !is_string($hostEngine) || !is_string($hostImages) || !is_string($originals) || !is_array($known)) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
        // Branch: only a verified different owner can keep the standalone path for a present host policy.
        if ($hostImages !== $images) return false;
        if ($hostEngine !== $engine || basename($source) !== $originals || ($known[$category]['type'] ?? null) !== $collection->id()) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
        return true;
    }

    /** A host runtime override must not split the exact lock owner for shared writes. */
    private static function assertSameLock(ResourceCollection $collection, array $settings): void
    {
        $runtime = $settings['runtime_directory'] ?? '';
        if ($runtime === '') return;
        if (!is_string($runtime)) throw new RuntimeException(self::UNAVAILABLE);
        $configured = rtrim($runtime, '/\\') . DIRECTORY_SEPARATOR . $collection->id();
        if ($configured === $collection->runtimeDirectory()) return;
        $directory = @realpath($configured);
        if (!is_string($directory) || $directory !== @realpath($collection->runtimeDirectory())) throw new RuntimeException(self::UNAVAILABLE);
    }

    /** Uses the same working-name mapping as the gallery, bound to the exact engine collection object. */
    public function paths(ResourceCollection $collection, string $name): array
    {
        $this->assertCurrent($collection);
        return $this->storage->aliasPaths($this->view, $name);
    }

    /** Rechecks the canonical source link inside the exact collection lock before dispatch. */
    public function locked(callable $operation): mixed
    {
        return $this->collection->withMutationLock(function () use ($operation): mixed {
            $this->assertCurrent($this->collection);
            return $operation();
        });
    }

    /** Only the selected old aliases are retired; the view reuses the engine object's reentrant lock. */
    public function retire(string $name): void
    {
        $this->assertCurrent($this->collection);
        $this->storage->removeWorking($this->view, $name);
    }

    /** A moved portable source link never transfers the hosted public namespace to its new target. */
    private function assertCurrent(ResourceCollection $collection): void
    {
        $source = $collection->sourceDirectory();
        clearstatcache(true, $source);
        if ($collection !== $this->collection || @realpath($source) !== $this->view->sourceDirectory()) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
    }
}

/** The shared layout receives canonical source coordinates without inventing another collection lock. */
final class HostedPublicCollectionView
{
    public function __construct(private readonly ResourceCollection $collection, private readonly string $source) {}
    public function sourceDirectory(): string { return $this->source; }
    public function resourceId(string $name): string { return $this->collection->resourceId($name); }
    public function withMutationLock(callable $operation): mixed { return $this->collection->withMutationLock($operation); }
}
