<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Routes all administration actions while delegating authorization and storage to owners. */
final class AdminApi
{
    private ResourceEngine $engine;
    private AdminAuthenticator $authenticator;
    private HttpResponder $responder;
    private ResourceUrlBuilder $urlBuilder;

    public function __construct(
        ResourceEngine $engine,
        AdminAuthenticator $authenticator,
        HttpResponder $responder,
        ResourceUrlBuilder $urlBuilder
    ) {
        $this->engine = $engine;
        $this->authenticator = $authenticator;
        $this->responder = $responder;
        $this->urlBuilder = $urlBuilder;
    }

    /** Executes a bounded action from the reusable administration widget. */
    public function run(): never
    {
        // Completed boundary: PHP warnings become JSON failures instead of corrupting the response body.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        $allowedOrigin = $this->engine->configuration()->adminCorsOrigin();
        $this->responder->applyCors($allowedOrigin, true);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'OPTIONS') {
            $this->responder->preflight();
        }
        $action = trim((string) ($method === 'GET' ? ($_GET['action'] ?? 'status') : ($_POST['action'] ?? '')));

        try {
            if ($action === 'status') {
                $this->responder->json($this->status());
            }
            if ($action === 'login') {
                $this->login($method);
            }
            if (!$this->authenticator->authorized()) {
                $this->responder->json(['ok' => false, 'error' => 'Authorization is required.'], 401);
            }
            if ($method === 'GET' && $action === 'list') {
                $this->responder->json($this->list());
            }
            if ($method !== 'POST') {
                $this->responder->json(['ok' => false, 'error' => 'Method is not allowed.'], 405);
            }

            $csrfToken = (string) ($_SERVER['HTTP_X_BZN_CSRF'] ?? ($_POST['csrf'] ?? ''));
            $this->authenticator->assertCsrf($csrfToken);
            $this->mutate($action);
        } catch (InvalidArgumentException $error) {
            $this->responder->json(['ok' => false, 'error' => $error->getMessage()], 400);
        } catch (RuntimeException $error) {
            $this->responder->json(['ok' => false, 'error' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            $this->internalFailure($error);
        }
    }

    /** Logs private failure details and returns a safe diagnostic id to the administrator. */
    private function internalFailure(Throwable $error): never
    {
        $diagnosticLength = 12;
        $diagnosticId = substr(hash('sha256', microtime(true) . ':' . random_bytes(16)), 0, $diagnosticLength);
        $logMessage = sprintf(
            '[BZN_RESOURCE_ADMIN:%s] %s: %s in %s:%d',
            $diagnosticId,
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        );
        error_log($logMessage);
        $this->responder->json([
            'ok' => false,
            'error' => 'Administration operation failed.',
            'diagnostic' => $diagnosticId,
        ], 500);
    }

    /** Returns only information required to bootstrap the standalone or embedded widget. */
    private function status(): array
    {
        $collections = [];
        foreach ($this->engine->configuration()->collections() as $collection) {
            // Loop: configured collections automatically become choices in the same interface.
            $collections[] = ['id' => $collection->id(), 'label' => $collection->label()];
        }
        $authorized = $this->authenticator->authorized();
        return [
            'ok' => true,
            'authorized' => $authorized,
            'password_login' => $this->authenticator->canUsePasswordLogin(),
            'csrf' => $authorized ? $this->authenticator->csrfToken() : '',
            'collections' => $collections,
        ];
    }

    /** Authenticates only the standalone password flow; role-based hosts bypass this action. */
    private function login(string $method): never
    {
        if ($method !== 'POST') {
            $this->responder->json(['ok' => false, 'error' => 'Method is not allowed.'], 405);
        }
        $password = (string) ($_POST['password'] ?? '');
        if (!$this->authenticator->login($password)) {
            $this->responder->json(['ok' => false, 'error' => 'Invalid administrator credentials.'], 401);
        }
        $this->responder->json($this->status());
    }

    /** Returns an authenticated gallery page decorated with cached-thumbnail links. */
    private function list(): array
    {
        $type = $this->type($_GET);
        $tags = $this->tags((string) ($_GET['tags'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = max(0, (int) ($_GET['page_size'] ?? 0));
        return $this->urlBuilder->decorateList($this->engine->list($type, $tags, $page, $pageSize));
    }

    /** Maps a protected widget command to one encapsulated engine operation. */
    private function mutate(string $action): never
    {
        $type = $this->type($_POST);
        if ($action === 'logout') {
            $this->authenticator->logout();
            $this->responder->json(['ok' => true]);
        }
        if ($action === 'upload') {
            $files = isset($_FILES['files']) && is_array($_FILES['files']) ? $_FILES['files'] : [];
            $result = $this->engine->upload($type, $files, $this->tags((string) ($_POST['tags'] ?? '')));
            $this->responder->json(['ok' => true, 'uploaded' => $result['uploaded']]);
        }
        if ($action === 'tags') {
            $catalog = $this->engine->setTags($type, $this->resourceId(), $this->tags((string) ($_POST['tags'] ?? '')));
            $this->responder->json(['ok' => true, 'total' => (int) $catalog['total']]);
        }
        if ($action === 'delete') {
            $result = $this->engine->delete($type, $this->resourceId());
            $this->responder->json(['ok' => true, 'deleted' => $result['deleted']]);
        }
        if ($action === 'rebuild') {
            $catalog = $this->engine->rebuild($type);
            $this->responder->json(['ok' => true, 'total' => (int) $catalog['total']]);
        }
        if ($action === 'thumbnails') {
            $result = $this->engine->buildThumbnailBatch($type);
            $this->responder->json(['ok' => true, 'processed' => count($result['processed']), 'errors' => $result['errors']]);
        }
        if ($action === 'optimize_batch') {
            $result = $this->engine->optimizeBatch($type);
            $this->responder->json(['ok' => true, 'processed' => count($result['processed']), 'errors' => $result['errors']]);
        }
        if ($action === 'optimize') {
            $result = $this->engine->optimize($type, $this->resourceId());
            $this->responder->json([
                'ok' => true,
                'source_bytes' => $result['source_bytes'],
                'output_bytes' => $result['output_bytes'],
            ]);
        }
        throw new InvalidArgumentException('Unknown administration action.');
    }

    /** Reads a logical collection id from one request array. */
    private function type(array $request): string
    {
        return trim((string) ($request['type'] ?? 'resources'));
    }

    /** Reads the opaque resource id required by item-level actions. */
    private function resourceId(): string
    {
        $resourceId = trim((string) ($_POST['id'] ?? ''));
        if ($resourceId === '') {
            throw new InvalidArgumentException('Resource id is required.');
        }
        return $resourceId;
    }

    /** Parses the shared comma-separated tag contract. */
    private function tags(string $source): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $source)), static fn (string $tag): bool => $tag !== ''));
    }
}
