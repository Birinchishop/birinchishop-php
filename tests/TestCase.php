<?php

declare(strict_types=1);

namespace Birinchishop\Tests;

// The same test classes run under PHPUnit (vendor/bin/phpunit) and under the dependency-free
// runner (php tests/run.php), which defines BSK_STANDALONE_RUNNER before loading this file.
if (!defined('BSK_STANDALONE_RUNNER') && class_exists(\PHPUnit\Framework\TestCase::class)) {
    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        use TestHelpers;

        /**
         * @template T of \Throwable
         * @param class-string<T> $class
         * @return T
         */
        protected function assertThrows(string $class, callable $fn): \Throwable
        {
            try {
                $fn();
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e, 'Unexpected exception: ' . $e::class . ': ' . $e->getMessage());
                return $e;
            }
            $this->fail("Expected {$class} to be thrown");
        }
    }
} else {
    abstract class TestCase
    {
        use TestHelpers;

        public int $assertions = 0;

        protected function setUp(): void
        {
        }

        protected function tearDown(): void
        {
        }

        /** @internal used by tests/run.php */
        public function runTest(string $method): void
        {
            $this->setUp();
            try {
                $this->{$method}();
            } finally {
                $this->tearDown();
            }
        }

        private function check(bool $ok, string $message): void
        {
            $this->assertions++;
            if (!$ok) {
                throw new AssertionFailed($message);
            }
        }

        private static function export(mixed $v): string
        {
            return str_replace("\n", ' ', var_export($v, true));
        }

        public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
        {
            $this->check($expected === $actual, ($message !== '' ? $message . ': ' : '') . 'expected ' . self::export($expected) . ', got ' . self::export($actual));
        }

        public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
        {
            $this->check($expected == $actual, ($message !== '' ? $message . ': ' : '') . 'expected ' . self::export($expected) . ', got ' . self::export($actual));
        }

        public function assertTrue(mixed $actual, string $message = ''): void
        {
            $this->check($actual === true, $message !== '' ? $message : 'expected true, got ' . self::export($actual));
        }

        public function assertFalse(mixed $actual, string $message = ''): void
        {
            $this->check($actual === false, $message !== '' ? $message : 'expected false, got ' . self::export($actual));
        }

        public function assertNull(mixed $actual, string $message = ''): void
        {
            $this->check($actual === null, $message !== '' ? $message : 'expected null, got ' . self::export($actual));
        }

        public function assertCount(int $expected, \Countable|array $actual, string $message = ''): void
        {
            $this->check(count($actual) === $expected, ($message !== '' ? $message . ': ' : '') . "expected count {$expected}, got " . count($actual));
        }

        public function assertInstanceOf(string $class, mixed $actual, string $message = ''): void
        {
            $this->check($actual instanceof $class, $message !== '' ? $message : 'expected instance of ' . $class . ', got ' . get_debug_type($actual));
        }

        public function assertMatchesRegularExpression(string $pattern, string $actual, string $message = ''): void
        {
            $this->check(preg_match($pattern, $actual) === 1, $message !== '' ? $message : "'{$actual}' does not match {$pattern}");
        }

        public function assertArrayHasKey(int|string $key, array $array, string $message = ''): void
        {
            $this->check(array_key_exists($key, $array), $message !== '' ? $message : "missing key '{$key}'");
        }

        public function assertArrayNotHasKey(int|string $key, array $array, string $message = ''): void
        {
            $this->check(!array_key_exists($key, $array), $message !== '' ? $message : "unexpected key '{$key}'");
        }

        public function assertGreaterThanOrEqual(int|float $min, int|float $actual, string $message = ''): void
        {
            $this->check($actual >= $min, $message !== '' ? $message : "expected >= {$min}, got {$actual}");
        }

        public function assertLessThanOrEqual(int|float $max, int|float $actual, string $message = ''): void
        {
            $this->check($actual <= $max, $message !== '' ? $message : "expected <= {$max}, got {$actual}");
        }

        public function fail(string $message = ''): never
        {
            throw new AssertionFailed($message !== '' ? $message : 'failed');
        }

        /**
         * @template T of \Throwable
         * @param class-string<T> $class
         * @return T
         */
        protected function assertThrows(string $class, callable $fn): \Throwable
        {
            try {
                $fn();
            } catch (AssertionFailed $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e, 'Unexpected exception: ' . $e::class . ': ' . $e->getMessage());
                return $e;
            }
            $this->fail("Expected {$class} to be thrown");
        }
    }
}
