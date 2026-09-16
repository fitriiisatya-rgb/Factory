<?php

declare(strict_types=1);

/**
 * Minimal PSR-4-ish autoloader for the Amor\Api\ namespace, mapped onto
 * src/. No Composer dependency required — keeps this skeleton runnable on
 * a shared-hosting cPanel account with nothing beyond stock PHP.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'Amor\\Api\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
