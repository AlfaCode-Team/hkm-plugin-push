<?php

declare(strict_types=1);

/**
 * The plugin is developed inside the workspace, so the kernel's autoloader is
 * the one that knows about the Kernel\Ports interfaces this plugin types
 * against, and about firebase/php-jwt — which the tests use as an independent
 * signature oracle.
 */

$kernelAutoload = '/Users/user/Documents/HKMCODE/PHP/vendor/autoload.php';

foreach ([__DIR__ . '/../vendor/autoload.php', $kernelAutoload] as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
    }
}

// This plugin's own classes, PSR-4 from the repository root.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Plugins\\Push\\')) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, \strlen('Plugins\\Push\\')));

    foreach ([
        __DIR__ . '/../' . $relative . '.php',
        __DIR__ . '/' . substr($relative, \strlen('Tests/')) . '.php',
    ] as $path) {
        if (is_file($path)) {
            require_once $path;

            return;
        }
    }
});
