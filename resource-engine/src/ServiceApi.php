<?php
// BZN-FILE-PURPOSE-20260920: ServiceApi.php — маршрутизирует закрытые межсерверные ресурсы через существующий Resource Engine.
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

// Service: one server-only key protects every domain-to-domain resource request.
final class ServiceAuthenticator
{
    public function __construct(private string $expectedKey)
    {
    }

    // Function: an empty or mismatched key always fails closed.
    public function authorized(string $providedKey): bool
    {
        return $this->expectedKey !== '' && $providedKey !== '' && hash_equals($this->expectedKey, $providedKey);
    }
}

// Value object: callers see only logical identity while the engine collection id remains private.
final class ServiceResourceContext
{
    public function __construct(
        public readonly ResourceEngine $engine,
        public readonly string $collectionId,
        public readonly string $logicalType,
        public readonly string $scope,
        public readonly string $format,
        public readonly array $allowedKinds,
        public readonly bool $rebuildOnList,
        public readonly bool $writable,
        public readonly string $cacheControl
    ) {
    }
}

// Configuration: scope resolution owns every physical path, runtime folder and collection policy.
final class ServiceResourceConfiguration
{
    public function __construct(private array $settings)
    {
        if (trim((string) ($settings['runtime_directory'] ?? '')) === '') {
            throw new InvalidArgumentException('Service runtime directory is not configured.');
        }
        if (!(array) ($settings['resources'] ?? [])) {
            throw new InvalidArgumentException('Service resource types are not configured.');
        }
    }

    // Function: the secret may be supplied directly by a test or through one host-owned file in production.
    public function serviceKey(): string
    {
        $directKey = trim((string) ($this->settings['service_key'] ?? ''));
        if ($directKey !== '') return $directKey;
        $keyFile = trim((string) ($this->settings['service_key_file'] ?? ''));
        if ($keyFile === '' || !is_readable($keyFile)) return '';
        return trim((string) file_get_contents($keyFile));
    }

    public function serviceKeyHeader(): string { return (string) ($this->settings['service_key_header'] ?? 'HTTP_X_BZN_SERVICE_KEY'); }
    public function allowedMethods(): array { return array_values((array) ($this->settings['allowed_methods'] ?? ['GET'])); }

    // Function: logical type, scope and validated opaque owner become one isolated Resource Engine instance.
    public function context(string $logicalType, string $scope, string $owner): ServiceResourceContext
    {
        $resourceSettings = $this->settings['resources'][$logicalType] ?? null;
        if (!is_array($resourceSettings)) throw new InvalidArgumentException('Unknown service resource type.');
        $demoScope = (string) ($this->settings['scopes']['demo'] ?? 'demo');
        $userScope = (string) ($this->settings['scopes']['user'] ?? 'user');
        if (!in_array($scope, [$demoScope, $userScope], true)) throw new InvalidArgumentException('Unknown service resource scope.');

        $collectionId = 'service-' . $logicalType . '-' . $scope;
        $sourceDirectory = trim((string) ($resourceSettings['demo_source_directory'] ?? ''));
        $writable = false;
        // Branch: personal scope accepts only the opaque namespace and creates no email-address directory.
        if ($scope === $userScope) {
            if (preg_match((string) ($this->settings['owner_pattern'] ?? ''), $owner) !== 1) {
                throw new InvalidArgumentException('Invalid service resource owner.');
            }
            $collectionId .= '-' . $owner;
            $sourceDirectory = rtrim((string) ($resourceSettings['user_root_directory'] ?? ''), '/\\')
                . DIRECTORY_SEPARATOR . $owner
                . DIRECTORY_SEPARATOR . trim((string) ($resourceSettings['user_subdirectory'] ?? ''), '/\\');
            $writable = true;
            if (!is_dir($sourceDirectory) && (bool) ($resourceSettings['create_user_directory'] ?? false)) {
                $directoryMode = max(0700, min(0770, (int) ($resourceSettings['user_directory_mode'] ?? 0750)));
                if (!mkdir($sourceDirectory, $directoryMode, true) && !is_dir($sourceDirectory)) {
                    throw new RuntimeException('Unable to create personal resource directory.');
                }
            }
        }
        if ($sourceDirectory === '') throw new InvalidArgumentException('Service source directory is not configured.');

        $collectionSettings = array_replace_recursive(
            (array) ($resourceSettings['collection'] ?? []),
            ['source_directory' => $sourceDirectory]
        );
        $engineSettings = [
            'runtime_directory' => (string) $this->settings['runtime_directory'],
            'default_page_size' => (int) ($this->settings['default_page_size'] ?? 30),
            'maximum_page_size' => (int) ($this->settings['maximum_page_size'] ?? 100),
            'operation_batch_size' => 1,
            'operation_time_budget_seconds' => 5,
            'maximum_upload_files' => (int) ($this->settings['maximum_upload_files'] ?? 10),
            'maximum_upload_bytes' => (int) ($this->settings['maximum_upload_bytes'] ?? 67108864),
            'collections' => [$collectionId => $collectionSettings],
        ];
        $engine = new ResourceEngine(new EngineConfiguration($engineSettings), new ImageProcessor());
        $cacheControl = $scope === $userScope
            ? (string) ($resourceSettings['user_cache_control'] ?? 'private, no-store, max-age=0')
            : (string) ($resourceSettings['demo_cache_control'] ?? 'public, max-age=3600');
        return new ServiceResourceContext(
            $engine,
            $collectionId,
            $logicalType,
            $scope,
            (string) ($resourceSettings['format'] ?? ''),
            array_values((array) ($resourceSettings['allowed_kinds'] ?? ['original', 'thumbnail'])),
            (bool) ($resourceSettings['rebuild_on_list'] ?? false),
            $writable,
            $cacheControl
        );
    }
}

// Gateway: one list and one binary function hide the selected host, filesystem and derivative algorithm.
final class ServiceResourceGateway
{
    // Function: logical pages reuse Resource Engine filtering, newest-first order and pagination.
    public function list(ServiceResourceContext $context, array $tags = [], int $page = 1, int $pageSize = 0): array
    {
        if ($context->rebuildOnList) $context->engine->rebuild($context->collectionId);
        $result = $context->engine->list($context->collectionId, $tags, $page, $pageSize);
        foreach ((array) ($result['items'] ?? []) as $index => $item) {
            // Loop: internal collection ids are replaced by the stable logical public contract.
            $result['items'][$index] = [
                ...$item,
                'type' => $context->logicalType,
                'scope' => $context->scope,
                'format' => $context->format,
                'writable' => $context->writable,
            ];
        }
        $result['ok'] = true;
        $result['type'] = $context->logicalType;
        $result['scope'] = $context->scope;
        unset($result['generated_at']);
        return $result;
    }

    // Function: only explicitly allowed variants can leave one service collection.
    public function resource(ServiceResourceContext $context, string $resourceId, string $kind): array
    {
        if (!in_array($kind, $context->allowedKinds, true)) throw new InvalidArgumentException('Unknown service resource kind.');
        return $context->engine->resource($context->collectionId, $resourceId, $kind);
    }
}

// HTTP owner: input normalization, authorization and output live outside storage and image processing.
final class ServiceApi
{
    public function __construct(
        private ServiceResourceConfiguration $configuration,
        private ServiceResourceGateway $gateway,
        private HttpResponder $responder
    ) {
    }

    // Function: one protected GET contract serves list, original and thumbnail operations.
    public function run(): never
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, $this->configuration->allowedMethods(), true)) {
            $this->responder->json(['ok' => false, 'error' => 'Method is not allowed.'], 405);
        }
        $providedKey = trim((string) ($_SERVER[$this->configuration->serviceKeyHeader()] ?? ''));
        if (!(new ServiceAuthenticator($this->configuration->serviceKey()))->authorized($providedKey)) {
            $this->responder->json(['ok' => false, 'error' => 'Service authorization required.'], 401);
        }

        try {
            $type = trim((string) ($_GET['type'] ?? ''));
            $scope = trim((string) ($_GET['scope'] ?? ''));
            $owner = trim((string) ($_GET['owner'] ?? ''));
            $kind = trim((string) ($_GET['kind'] ?? 'list'));
            $context = $this->configuration->context($type, $scope, $owner);
            if ($kind === 'list') {
                $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['tags'] ?? '')))));
                $page = max(1, (int) ($_GET['page'] ?? 1));
                $pageSize = max(0, (int) ($_GET['page_size'] ?? 0));
                $this->responder->json($this->gateway->list($context, $tags, $page, $pageSize));
            }
            $resourceId = trim((string) ($_GET['id'] ?? ''));
            $descriptor = $this->gateway->resource($context, $resourceId, $kind);
            $this->responder->resource($descriptor, $context->cacheControl);
        } catch (InvalidArgumentException $error) {
            $this->responder->json(['ok' => false, 'error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            $this->responder->json(['ok' => false, 'error' => 'Service resource is temporarily unavailable.'], 500);
        }
    }
}
