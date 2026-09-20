<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

/** Owns HTTP headers and output so domain/storage details stay outside business methods. */
final class HttpResponder
{
    /** Applies the configured cross-domain contract used by public resource consumers. */
    public function applyCors(string $allowedOrigin, bool $allowCredentials = false): void
    {
        $requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $responseOrigin = $allowedOrigin;
        if ($allowedOrigin !== '*' && $requestOrigin !== $allowedOrigin) {
            $responseOrigin = '';
        }
        if ($responseOrigin !== '') {
            header('Access-Control-Allow-Origin: ' . $responseOrigin);
        }
        if ($allowCredentials && $responseOrigin !== '*') {
            header('Access-Control-Allow-Credentials: true');
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-BZN-CSRF');
        header('Vary: Origin');
        header('X-Content-Type-Options: nosniff');
    }

    /** Emits one JSON contract response and ends the request. */
    public function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        exit;
    }

    /** Streams one resolved file with validators while keeping its physical path private. */
    public function resource(array $descriptor, string $cacheControl = 'public, max-age=86400, stale-while-revalidate=604800'): never
    {
        $modified = (int) $descriptor['modified'];
        $bytes = (int) $descriptor['bytes'];
        $entityTag = '"' . hash('sha256', $modified . ':' . $bytes . ':' . (string) $descriptor['name']) . '"';
        $requestEntityTag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

        // Branch: a matching browser cache validator needs no resource body.
        if ($requestEntityTag === $entityTag) {
            http_response_code(304);
            header('ETag: ' . $entityTag);
            exit;
        }

        header('Content-Type: ' . (string) $descriptor['mime']);
        header('Content-Length: ' . $bytes);
        header('Content-Disposition: inline; filename="' . rawurlencode((string) $descriptor['name']) . '"');
        header('Cache-Control: ' . $cacheControl);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        header('ETag: ' . $entityTag);
        readfile((string) $descriptor['path']);
        exit;
    }

    /** Completes a preflight request without entering any application operation. */
    public function preflight(): never
    {
        http_response_code(204);
        exit;
    }
}
