<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use InvalidArgumentException;
use Throwable;

/** Exposes list and binary-resource black boxes without revealing storage implementation. */
final class PublicApi
{
    private ResourceEngine $engine;
    private HttpResponder $responder;
    private ResourceUrlBuilder $urlBuilder;

    public function __construct(ResourceEngine $engine, HttpResponder $responder, ResourceUrlBuilder $urlBuilder)
    {
        $this->engine = $engine;
        $this->responder = $responder;
        $this->urlBuilder = $urlBuilder;
    }

    /** Routes one public request through the fixed resource contract. */
    public function run(): never
    {
        $this->responder->applyCors($this->engine->configuration()->corsOrigin());
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'OPTIONS') {
            $this->responder->preflight();
        }
        if ($method !== 'GET') {
            $this->responder->json(['ok' => false, 'error' => 'Method is not allowed.'], 405);
        }

        try {
            $type = trim((string) ($_GET['type'] ?? 'resources'));
            $kind = trim((string) ($_GET['kind'] ?? 'list'));
            if ($kind === 'list') {
                $tags = $this->tags((string) ($_GET['tags'] ?? ''));
                $page = max(1, (int) ($_GET['page'] ?? 1));
                $pageSize = max(0, (int) ($_GET['page_size'] ?? 0));
                $result = $this->engine->list($type, $tags, $page, $pageSize);
                $this->responder->json($this->urlBuilder->decorateList($result));
            }

            $resourceId = trim((string) ($_GET['id'] ?? ''));
            $descriptor = $this->engine->resource($type, $resourceId, $kind);
            $this->responder->resource($descriptor);
        } catch (InvalidArgumentException $error) {
            $this->responder->json(['ok' => false, 'error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            $this->responder->json(['ok' => false, 'error' => 'Resource service is temporarily unavailable.'], 500);
        }
    }

    /** Parses a comma-separated public filter into independent tags. */
    private function tags(string $source): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $source)), static fn (string $tag): bool => $tag !== ''));
    }
}
