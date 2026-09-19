<?php
declare(strict_types=1);

$pageTitle = 'BZN — администрирование ресурсов';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="resource-admin.css">
</head>
<body class="bzn-resource-admin-page">
    <main id="resourceAdminRoot" data-mode="page" aria-label="Администрирование галереи"></main>
    <script src="resource-client.js"></script>
    <script src="resource-admin.js"></script>
</body>
</html>
