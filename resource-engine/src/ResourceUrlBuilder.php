<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

/** Builds stable public links from logical resource fields only. */
final class ResourceUrlBuilder
{
    private string $endpointPath;

    public function __construct(string $endpointPath)
    {
        $this->endpointPath = $endpointPath !== '' ? $endpointPath : 'api.php';
    }

    /** Adds black-box URLs to every catalog item without exposing its source filename as a path. */
    public function decorateList(array $page): array
    {
        foreach ((array) ($page['items'] ?? []) as $index => $item) {
            // Loop: every consumer receives the same variant contract for every resource type.
            $page['items'][$index]['urls'] = $this->urls((string) $item['type'], (string) $item['id']);
        }
        return $page;
    }

    /** Returns all agreed resource variants for one opaque id. */
    public function urls(string $type, string $resourceId): array
    {
        return [
            'resource' => $this->url($type, 'resource', $resourceId),
            'original' => $this->url($type, 'original', $resourceId),
            'thumbnail' => $this->url($type, 'thumbnail', $resourceId),
            'optimized' => $this->url($type, 'optimized', $resourceId),
        ];
    }

    /** Encodes one logical request into the current endpoint path. */
    public function url(string $type, string $kind, string $resourceId): string
    {
        $query = http_build_query(['type' => $type, 'kind' => $kind, 'id' => $resourceId], '', '&', PHP_QUERY_RFC3986);
        return $this->endpointPath . '?' . $query;
    }
}
