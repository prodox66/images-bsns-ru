<?php
// BZN-FILE-PURPOSE-20260920: service-api.php — закрытая HTTP-точка для других BZN-доменов.
declare(strict_types=1);

use Bzn\ResourceEngine\HttpResponder;
use Bzn\ResourceEngine\ServiceApi;
use Bzn\ResourceEngine\ServiceResourceConfiguration;
use Bzn\ResourceEngine\ServiceResourceGateway;

require_once __DIR__ . '/src/ResourceCollection.php';
require_once __DIR__ . '/src/EngineConfiguration.php';
require_once __DIR__ . '/src/ImageProcessor.php';
require_once __DIR__ . '/src/ResourceEngine.php';
require_once __DIR__ . '/src/HttpResponder.php';
require_once __DIR__ . '/src/ServiceApi.php';

$localConfigurationFile = __DIR__ . DIRECTORY_SEPARATOR . 'service.local.php';
$exampleConfigurationFile = __DIR__ . DIRECTORY_SEPARATOR . 'service.config.example.php';
$configurationFile = is_file($localConfigurationFile) ? $localConfigurationFile : $exampleConfigurationFile;
$configurationValues = require $configurationFile;
$configuration = new ServiceResourceConfiguration(is_array($configurationValues) ? $configurationValues : []);
(new ServiceApi($configuration, new ServiceResourceGateway(), new HttpResponder()))->run();
