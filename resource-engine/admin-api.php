<?php
declare(strict_types=1);

use Bzn\ResourceEngine\AdminApi;
use Bzn\ResourceEngine\AdminAuthenticator;
use Bzn\ResourceEngine\HttpResponder;
use Bzn\ResourceEngine\ResourceUrlBuilder;

$engine = require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/src/HttpResponder.php';
require_once __DIR__ . '/src/ResourceUrlBuilder.php';
require_once __DIR__ . '/src/AdminAuthenticator.php';
require_once __DIR__ . '/src/AdminApi.php';

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/resource-engine/admin-api.php');
$publicEndpointPath = preg_replace('/admin-api\.php$/', 'api.php', $scriptName) ?: '/resource-engine/api.php';
$application = new AdminApi(
    $engine,
    new AdminAuthenticator($engine->configuration()),
    new HttpResponder(),
    new ResourceUrlBuilder($publicEndpointPath)
);
$application->run();
