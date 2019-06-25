<?php
declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use MockeryPHPUnitIntegration;
}
