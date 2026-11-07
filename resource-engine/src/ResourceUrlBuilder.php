<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

/** Builds stable public links from logical resource fields only. */
final class ResourceUrlBuilder
{
    private const VARIANTS = ['resource', 'original', 'thumbnail', 'optimized'];
    private const EMPTY_REVISION = '';
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
            $versions = $item['delivery_revisions'] ?? [];
            $page['items'][$index]['urls'] = $this->urls((string) $item['type'], (string) $item['id'], is_array($versions) ? $versions : []);
        }
        return $page;
    }

    /** Returns all agreed resource variants for one opaque id. */
    public function urls(string $type, string $resourceId, array $versions = []): array
    {
        $urls = [];
        foreach (self::VARIANTS as $variant) {
            // Loop: only the selected variant's revision changes its link.
            $revision = $versions[$variant] ?? self::EMPTY_REVISION;
            $urls[$variant] = $this->url($type, $variant, $resourceId, is_string($revision) ? $revision : self::EMPTY_REVISION);
        }
        return $urls;
    }

    /** Encodes one logical request into the current endpoint path. */
    public function url(string $type, string $kind, string $resourceId, string $revision = self::EMPTY_REVISION): string
    {
        $parameters = ['type' => $type, 'kind' => $kind, 'id' => $resourceId];
        // Branch: unversioned callers retain their exact original URL contract.
        if ($revision !== self::EMPTY_REVISION) $parameters['v'] = $revision;
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        return $this->endpointPath . '?' . $query;
    }
}
