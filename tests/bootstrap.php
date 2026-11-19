<?php

declare(strict_types=1);

// Loads the SDK with Composer's autoloader if present, otherwise with a tiny PSR-4 autoloader,
// so the tests run on any machine with plain `php tests/run.php`.
$composer = __DIR__ . '/../vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
}
spl_autoload_register(static function (string $class): void {
    foreach (['Birinchishop\\Tests\\' => __DIR__ . '/', 'Birinchishop\\' => __DIR__ . '/../src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
