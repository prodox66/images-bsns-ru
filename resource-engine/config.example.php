<?php
declare(strict_types=1);

// Configuration template: deployment paths and password hashes belong only to config.local.php.
$engineDirectory = __DIR__;
$repositoryDirectory = dirname($engineDirectory);
$defaultRuntimeDirectory = $engineDirectory . DIRECTORY_SEPARATOR . 'runtime';
$defaultImageDirectory = $repositoryDirectory . DIRECTORY_SEPARATOR . 'images';

$supportedMimeByExtension = [
    'avif' => 'image/avif',
    'gif' => 'image/gif',
    'jpeg' => 'image/jpeg',
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
];

$sharedImagePolicy = [
    'allowed_mime_by_extension' => $supportedMimeByExtension,
    'thumbnail' => [
        'maximum_width' => 360,
        'maximum_height' => 360,
        'quality' => 76,
    ],
    'optimization' => [
        'quality' => 84,
    ],
];

return [
    'runtime_directory' => $defaultRuntimeDirectory,
    'catalog_global_key' => 'BZNResourceCatalog',
    'default_page_size' => 30,
    'maximum_page_size' => 100,
    'operation_batch_size' => 3,
    'operation_time_budget_seconds' => 8,
    'maximum_upload_files' => 50,
    'maximum_upload_bytes' => 20 * 1024 * 1024,
    'cors_origin' => '*',
    'admin_cors_origin' => '',
    'admin' => [
        'session_name' => 'bzn_resource_engine_admin',
        'password_hash' => '',
        // Optional callback: return true when the current cabinet session has an allowed role.
        'authorization_callback' => null,
    ],
    'collections' => [
        'resources' => array_replace_recursive($sharedImagePolicy, [
            'label' => 'Ресурсы',
            'source_directory' => $defaultImageDirectory,
            'tags' => ['resource'],
        ]),
    ],
];
