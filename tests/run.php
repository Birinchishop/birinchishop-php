<?php

declare(strict_types=1);

// Dependency-free test runner: php tests/run.php [filter]
// Runs every public test* method of tests/*Test.php. Exit code 0 = all passed.

const BSK_STANDALONE_RUNNER = true;

require __DIR__ . '/bootstrap.php';

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false; // silenced with @
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

$filter = $argv[1] ?? null;
$passed = 0;
$failed = [];
$assertions = 0;
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

foreach ($files as $file) {
    $class = 'Birinchishop\\Tests\\' . basename($file, '.php');
    $ref = new ReflectionClass($class);
    if ($ref->isAbstract()) {
        continue;
    }
    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if (!str_starts_with($m->getName(), 'test') || $m->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        $name = $ref->getShortName() . '::' . $m->getName();
        if ($filter !== null && !str_contains($name, $filter)) {
            continue;
        }
        $test = new $class();
        try {
            $test->runTest($m->getName());
            $passed++;
            echo '.';
        } catch (\Throwable $e) {
            $failed[] = [$name, $e];
            echo 'F';
        }
        $assertions += $test->assertions;
    }
}

echo "\n\n";
foreach ($failed as [$name, $e]) {
    echo "FAIL {$name}\n  " . $e::class . ': ' . $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine() . "\n\n";
}
printf("%s: %d passed, %d failed, %d assertions (PHP %s)\n", $failed === [] ? 'OK' : 'FAILURES', $passed, count($failed), $assertions, PHP_VERSION);
exit($failed === [] ? 0 : 1);
