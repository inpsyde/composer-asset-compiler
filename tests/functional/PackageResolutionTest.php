<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Tests;

use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\PreCompilation\GithubActionArtifactAdapter;
use Inpsyde\AssetsCompiler\PreCompilation\Placeholders;
use Inpsyde\AssetsCompiler\Util\Env;

/**
 * @runTestsInSeparateProcesses
 */
class PackageResolutionTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function testEnvironmentResolved(): void
    {
        putenv('CAC_FROM=env_var');
        $this->composerUpdate(getenv('RESOURCES_DIR') . '/05');

        /** @var Asset $asset */
        $asset = $this->factoryFactory()->assets()->current();
        $env = $asset->env();

        static::assertSame('composer-asset-compiler/test-repo', $asset->name());

        static::assertSame('env_var', Env::readEnv('CAC_FROM', $env));
        static::assertSame('library', Env::readEnv('CAC_WHERE', $env));
        static::assertSame('world', Env::readEnv('CAC_HELLO', $env));
        static::assertSame('Foo', Env::readEnv('CAC_FOO', $env));
    }

    /**
     * @test
     */
    public function testPrecompiled(): void
    {
        $token = bin2hex(random_bytes(16));
        putenv('GITHUB_API_USER=test');
        putenv("GITHUB_API_TOKEN={$token}");
        $this->composerUpdate(getenv('RESOURCES_DIR') . '/05');

        /** @var Asset $asset */
        $factory = $this->factoryFactory('functional-tests');
        $asset = $factory->assets()->current();

        $hash = $factory->hashBuilder()->forAsset($asset) ?? '';
        $placeholders = Placeholders::new($asset, $factory->modeResolver()->mode(), $hash);
        $env = $asset->env();

        $config = $asset->preCompilationConfig();

        $source = $config->source($placeholders, $env);
        $adapterId = $config->adapter($placeholders);
        $target = $config->target($placeholders);
        $adapterConfig = $config->config($placeholders, $env);
        $adapter = $factory->preCompilationHandler()->findAdapter($config, $placeholders);

        static::assertTrue($config->isValid());
        static::assertSame('assets-functional-tests-1.0.0-Foo', $source);
        static::assertSame('gh-action-artifact', $adapterId);
        static::assertSame('./assets/', $target);
        static::assertSame('acme/foo', $adapterConfig['repository']);
        static::assertTrue($adapter instanceof GithubActionArtifactAdapter);
    }
}