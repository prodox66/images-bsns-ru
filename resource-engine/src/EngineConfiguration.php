<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use InvalidArgumentException;

/** Exposes one validated configuration object to every engine boundary. */
final class EngineConfiguration
{
    private array $settings;
    private array $collections = [];

    /** Builds immutable collection owners from one deployment configuration. */
    public function __construct(array $settings)
    {
        $runtimeDirectory = rtrim((string) ($settings['runtime_directory'] ?? ''), '/\\');
        if ($runtimeDirectory === '') {
            throw new InvalidArgumentException('Resource runtime directory is not configured.');
        }

        $this->settings = $settings;
        foreach ((array) ($settings['collections'] ?? []) as $id => $collectionSettings) {
            // Loop: collection-specific paths never leak into request handlers or UI clients.
            $collection = new ResourceCollection((string) $id, (array) $collectionSettings, $runtimeDirectory);
            $this->collections[$collection->id()] = $collection;
        }
        if (!$this->collections) {
            throw new InvalidArgumentException('At least one resource collection is required.');
        }
    }

    /** Resolves the requested resource type through the agreed public contract. */
    public function collection(string $id): ResourceCollection
    {
        if (!isset($this->collections[$id])) {
            throw new InvalidArgumentException('Unknown resource type.');
        }
        return $this->collections[$id];
    }

    public function collections(): array { return array_values($this->collections); }
    public function collectionIds(): array { return array_keys($this->collections); }
    public function catalogGlobalKey(): string { return (string) ($this->settings['catalog_global_key'] ?? 'BZNResourceCatalog'); }
    public function defaultPageSize(): int { return max(1, (int) ($this->settings['default_page_size'] ?? 30)); }
    public function maximumPageSize(): int { return max($this->defaultPageSize(), (int) ($this->settings['maximum_page_size'] ?? 100)); }
    public function operationBatchSize(): int { return max(1, (int) ($this->settings['operation_batch_size'] ?? 3)); }
    public function operationTimeBudgetSeconds(): int { return max(1, (int) ($this->settings['operation_time_budget_seconds'] ?? 8)); }
    public function maximumUploadFiles(): int { return max(1, (int) ($this->settings['maximum_upload_files'] ?? 50)); }
    public function maximumUploadBytes(): int { return max(1, (int) ($this->settings['maximum_upload_bytes'] ?? 20971520)); }
    public function corsOrigin(): string { return (string) ($this->settings['cors_origin'] ?? '*'); }
    public function adminCorsOrigin(): string { return (string) ($this->settings['admin_cors_origin'] ?? ''); }

    /** Returns the server-only authentication hash without accepting plaintext secrets in Git. */
    public function adminPasswordHash(): string
    {
        return (string) (((array) ($this->settings['admin'] ?? []))['password_hash'] ?? '');
    }

    public function adminSessionName(): string
    {
        return (string) (((array) ($this->settings['admin'] ?? []))['session_name'] ?? 'bzn_resource_engine_admin');
    }

    /** Returns an optional project-owned role policy used by embedded account interfaces. */
    public function adminAuthorizationCallback(): ?callable
    {
        $callback = ((array) ($this->settings['admin'] ?? []))['authorization_callback'] ?? null;
        return is_callable($callback) ? $callback : null;
    }
}
