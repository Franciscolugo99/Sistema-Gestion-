<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$repoRoot = dirname(__DIR__);
$runtimeRoot = $repoRoot . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'test_runtime';

foreach ([
    $runtimeRoot,
    $runtimeRoot . DIRECTORY_SEPARATOR . 'storage',
    $runtimeRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs',
    $runtimeRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups',
    $runtimeRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'diagnostics',
] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

if (!defined('FLUS_ROOT')) {
    define('FLUS_ROOT', $runtimeRoot);
}

require_once $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'backup_lib.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'diagnostics_lib.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'helpers.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'productos_helpers.php';

final class FlusSkippedTest extends RuntimeException
{
}

function flus_test_reset_runtime(): void
{
    $restoreLock = FLUS_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'restore.lock';
    $maintenance = FLUS_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'maintenance.flag';

    if (is_file($restoreLock)) {
        @unlink($restoreLock);
    }
    if (is_file($maintenance)) {
        @unlink($maintenance);
    }
}

function flus_assert_true(bool $condition, string $message = 'Expected condition to be true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function flus_assert_false(bool $condition, string $message = 'Expected condition to be false'): void
{
    if ($condition) {
        throw new RuntimeException($message);
    }
}

function flus_assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message !== '' ? $message . ' - ' : '';
        throw new RuntimeException($prefix . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function flus_assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        $prefix = $message !== '' ? $message . ' - ' : '';
        throw new RuntimeException($prefix . 'Did not find ' . var_export($needle, true));
    }
}

function flus_assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        $prefix = $message !== '' ? $message . ' - ' : '';
        throw new RuntimeException($prefix . 'Unexpectedly found ' . var_export($needle, true));
    }
}

function flus_skip(string $message): never
{
    throw new FlusSkippedTest($message);
}

function flus_run_test(string $name, callable $test): array
{
    flus_test_reset_runtime();

    try {
        $test();
        return ['name' => $name, 'ok' => true, 'skipped' => false, 'message' => 'OK'];
    } catch (FlusSkippedTest $e) {
        return ['name' => $name, 'ok' => true, 'skipped' => true, 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        return ['name' => $name, 'ok' => false, 'skipped' => false, 'message' => $e->getMessage()];
    } finally {
        flus_test_reset_runtime();
    }
}
