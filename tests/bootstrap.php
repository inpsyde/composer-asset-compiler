<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

$testsDir = str_replace('\\', '/', __DIR__);
$libDir = dirname($testsDir);
$vendorDir = "{$libDir}/vendor";
$autoload = "{$vendorDir}/autoload.php";

if (!is_file($autoload)) {
    die('Please install via Composer before running tests.');
}

putenv('TESTS_DIR=' . $testsDir);
putenv('LIBRARY_PATH=' . $libDir);
putenv('VENDOR_DIR=' . $vendorDir);
putenv('RESOURCES_DIR=' . "{$testsDir}/resources");

error_reporting(E_ALL);

if (!defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', $autoload);
    require_once $autoload;
}

unset($testsDir, $libDir, $vendorDir, $autoload);
