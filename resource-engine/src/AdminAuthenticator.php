<?php
declare(strict_types=1);

namespace Bzn\ResourceEngine;

use RuntimeException;

/** Adapts either a local admin password or a host project's role callback to one policy. */
final class AdminAuthenticator
{
    private const AUTHENTICATED_KEY = 'bzn_resource_engine_authenticated';
    private const CSRF_KEY = 'bzn_resource_engine_csrf';
    private EngineConfiguration $configuration;

    public function __construct(EngineConfiguration $configuration)
    {
        $this->configuration = $configuration;
        $this->startSession();
    }

    /** Reports authorization without requiring the widget to understand roles or sessions. */
    public function authorized(): bool
    {
        $callback = $this->configuration->adminAuthorizationCallback();
        if ($callback !== null) {
            $context = ['server' => $_SERVER, 'session' => $_SESSION];
            if ($callback($context) === true) {
                return true;
            }
        }
        return ($_SESSION[self::AUTHENTICATED_KEY] ?? false) === true;
    }

    /** Enables the standalone page when its server-only password hash matches. */
    public function login(string $password): bool
    {
        $passwordHash = $this->configuration->adminPasswordHash();
        if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION[self::AUTHENTICATED_KEY] = true;
        $this->rotateCsrf();
        return true;
    }

    /** Removes only engine-owned authentication state from a potentially shared account session. */
    public function logout(): void
    {
        unset($_SESSION[self::AUTHENTICATED_KEY], $_SESSION[self::CSRF_KEY]);
        session_regenerate_id(true);
    }

    public function canUsePasswordLogin(): bool
    {
        return $this->configuration->adminPasswordHash() !== '';
    }

    /** Returns a stable per-session token used by every state-changing widget request. */
    public function csrfToken(): string
    {
        if (!isset($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $this->rotateCsrf();
        }
        return (string) $_SESSION[self::CSRF_KEY];
    }

    /** Rejects mutations that were not initiated by the authenticated interface. */
    public function assertCsrf(string $providedToken): void
    {
        if ($providedToken === '' || !hash_equals($this->csrfToken(), $providedToken)) {
            throw new RuntimeException('Invalid administration token.');
        }
    }

    /** Starts one secure session boundary shared by standalone and embedded interfaces. */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secureCookie = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        session_name($this->configuration->adminSessionName());
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Replaces the mutation token after every authentication boundary change. */
    private function rotateCsrf(): void
    {
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
    }
}
