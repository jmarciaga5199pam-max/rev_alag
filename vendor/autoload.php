<?php

/**
 * PSR-4 Autoloader
 *
 * When Composer is available, run `composer install` and this file
 * will be overwritten by Composer's autoloader. This manual autoloader
 * provides the same PSR-4 functionality for environments without Composer.
 *
 * It also falls back to a lowercase-directory lookup so the same code
 * works on case-sensitive (Linux) filesystems where directories like
 * `app/middleware` use lowercase names while the namespace is
 * `App\Middleware`.
 */

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'App\\' => dirname(__DIR__) . '/app/',
        'Config\\' => dirname(__DIR__) . '/config/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $relativePath = str_replace('\\', '/', $relativeClass);

        // 1. Strict PSR-4 lookup (Namespace casing preserved).
        $file = $baseDir . $relativePath . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }

        // 2. Fallback: lowercase every directory segment but keep the
        //    class filename casing. Lets `App\Middleware\ParentMiddleware`
        //    resolve to `app/middleware/ParentMiddleware.php` on Linux.
        $segments = explode('/', $relativePath);
        $className = array_pop($segments);
        $lowerDirs = array_map('strtolower', $segments);
        $fallback = $baseDir . (empty($lowerDirs) ? '' : implode('/', $lowerDirs) . '/') . $className . '.php';
        if (is_file($fallback)) {
            require $fallback;
            return;
        }
    }
});
