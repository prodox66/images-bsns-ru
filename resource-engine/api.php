<?php
declare(strict_types=1);

use Bzn\ResourceEngine\HttpResponder;
use Bzn\ResourceEngine\PublicApi;
use Bzn\ResourceEngine\ResourceUrlBuilder;

$engine = require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/src/HttpResponder.php';
require_once __DIR__ . '/src/ResourceUrlBuilder.php';
require_once __DIR__ . '/src/PublicApi.php';

$endpointPath = (string) ($_SERVER['SCRIPT_NAME'] ?? '/resource-engine/api.php');
$application = new PublicApi($engine, new HttpResponder(), new ResourceUrlBuilder($endpointPath));
$application->run();
