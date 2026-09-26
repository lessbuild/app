<?php

$applicationRoot = dirname(__DIR__, 3);
$publicRoot = realpath($applicationRoot.'/public');
$requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$assetPath = $publicRoot === false ? false : realpath($publicRoot.$requestPath);

if ($requestPath !== '/' && $assetPath !== false && $publicRoot !== false
    && str_starts_with($assetPath, $publicRoot.DIRECTORY_SEPARATOR)
    && is_file($assetPath)) {
    return false;
}

require $applicationRoot.'/public/index.php';
