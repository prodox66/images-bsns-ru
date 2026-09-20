<?php
// Configuration template: copy to service.local.php and keep paths plus service key outside Git.
declare(strict_types=1);

$engineDirectory = __DIR__;
$bytesPerMegabyte = 1024 * 1024;
$templateNamePattern = '/^[\p{L}\p{N}][\p{L}\p{N}._-]{0,159}_prj\.png$/iuD';
$templateCollection = [
    'label' => 'Templates',
    'name_pattern' => $templateNamePattern,
    'allowed_mime_by_extension' => ['png' => 'image/png'],
    'tags' => ['template'],
    'thumbnail' => ['maximum_width' => 360, 'maximum_height' => 360, 'quality' => 76],
    'optimization' => ['quality' => 84],
];

return [
    'service_key' => '',
    'service_key_file' => '',
    'service_key_header' => 'HTTP_X_BZN_SERVICE_KEY',
    'allowed_methods' => ['GET'],
    'runtime_directory' => $engineDirectory . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'service',
    'default_page_size' => 30,
    'maximum_page_size' => 100,
    'maximum_upload_files' => 10,
    'maximum_upload_bytes' => 64 * $bytesPerMegabyte,
    'owner_pattern' => '/^[a-f0-9]{64}$/D',
    'scopes' => ['demo' => 'demo', 'user' => 'user'],
    'resources' => [
        'templates' => [
            'demo_source_directory' => '/absolute/path/to/files.bsns.ru/templates',
            'user_root_directory' => '/absolute/path/to/files.bsns.ru/users',
            'user_subdirectory' => 'templates',
            'create_user_directory' => true,
            'user_directory_mode' => 0750,
            'collection' => $templateCollection,
            'allowed_kinds' => ['original', 'thumbnail'],
            'format' => 'png',
            'rebuild_on_list' => true,
            'demo_cache_control' => 'public, max-age=3600, stale-while-revalidate=86400',
            'user_cache_control' => 'private, no-store, max-age=0',
        ],
    ],
];
