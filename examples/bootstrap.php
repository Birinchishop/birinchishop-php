<?php

// Loads the SDK: Composer's autoloader if installed, otherwise straight from ../src.
// In your own project just use: require 'vendor/autoload.php';
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Birinchishop\\')) {
            $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 13)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}
