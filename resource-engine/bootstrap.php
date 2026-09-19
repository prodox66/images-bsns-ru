<?php
declare(strict_types=1);

use Bzn\ResourceEngine\EngineConfiguration;
use Bzn\ResourceEngine\ImageProcessor;
use Bzn\ResourceEngine\ResourceEngine;

require_once __DIR__ . '/src/ResourceCollection.php';
require_once __DIR__ . '/src/EngineConfiguration.php';
require_once __DIR__ . '/src/ImageProcessor.php';
require_once __DIR__ . '/src/ResourceEngine.php';

$localConfigurationFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
$exampleConfigurationFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.example.php';
$configurationFile = is_file($localConfigurationFile) ? $localConfigurationFile : $exampleConfigurationFile;
$configurationValues = require $configurationFile;
$engineConfiguration = new EngineConfiguration($configurationValues);

return new ResourceEngine($engineConfiguration, new ImageProcessor());
