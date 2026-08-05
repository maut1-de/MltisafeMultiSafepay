<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\TestBootstrapper;

$loader = require __DIR__ . '/../../../../vendor/autoload.php';

// Suppress PHP 8.4 deprecations from scssphp (implicit nullable params) until Shopware updates the dependency
$scssphpDeprecationHandler = static function (int $severity, string $message, string $file, int $line): bool {
    if ($severity === E_DEPRECATED && str_contains($file, 'scssphp' . \DIRECTORY_SEPARATOR)) {
        return true; // suppress
    }
    return false;
};
set_error_handler($scssphpDeprecationHandler, E_DEPRECATED);

// Initialize the test environment
$bootstrapper = new TestBootstrapper();
$bootstrapper
    ->addActivePlugins('MltisafeMultiSafepay')
    ->setForceInstallPlugins(false)
    ->bootstrap();

$loader->addPsr4('Http\\Client\\', __DIR__ . '/../vendor/php-http/discovery/src/');
$loader->addPsr4("Psr\\Http\\Client\\", __DIR__ . '/../vendor/psr/http-client/src/');
$loader->addPsr4('MultiSafepay\\Shopware6\\', __DIR__ . '/../src/');
$loader->addPsr4('MultiSafepay\\Shopware6\\Test\\', __DIR__ . '/../src/tests/');
$loader->addPsr4('MultiSafepay\\', __DIR__ . '/../vendor/multisafepay/php-sdk/src/');

// Ensure the kernel is available for all test cases
KernelLifecycleManager::ensureKernelShutdown();
KernelLifecycleManager::bootKernel();
