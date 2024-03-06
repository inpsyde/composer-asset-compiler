<?php

/*
 * This file is part of the "Composer Asset Compiler" package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\Defaults;
use Inpsyde\AssetsCompiler\Asset\Factory as AssetFactory;
use Inpsyde\AssetsCompiler\Asset\Finder as AssetFinder;
use Inpsyde\AssetsCompiler\Asset\HashBuilder;
use Inpsyde\AssetsCompiler\Asset\Locker;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\Info;
use Inpsyde\AssetsCompiler\Asset\PathsFinder;
use Inpsyde\AssetsCompiler\Asset\Processor;
use Inpsyde\AssetsCompiler\Asset\RootConfig;
use Inpsyde\AssetsCompiler\PackageManager;
use Inpsyde\AssetsCompiler\PreCompilation\ArchiveDownloaderAdapter;
use Inpsyde\AssetsCompiler\PreCompilation\GithubActionArtifactAdapter;
use Inpsyde\AssetsCompiler\PreCompilation\GithubReleaseZipAdapter;
use Inpsyde\AssetsCompiler\PreCompilation\Handler;
use Inpsyde\AssetsCompiler\Process\Factory as ProcessFactory;
use Inpsyde\AssetsCompiler\Process\ParallelManager;
use Symfony\Component\Process\Process;

final class Factory
{
    /** var array<string, object> */
    private array $objects = [];

    /**
     * @param Composer $composer
     * @param IOInterface $io
     * @param string|null $mode
     * @param bool $isDev
     * @param string $ignoreLock
     * @return Factory
     */
    public static function new(
        Composer $composer,
        IOInterface $io,
        ?string $mode,
        bool $isDev,
        string $ignoreLock = ''
    ): Factory {

        return new self($composer, $io, $mode ?? Env::assetsCompilerMode(), $isDev, $ignoreLock);
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     * @param string|null $mode
     * @param bool $isDev
     * @param string $ignoreLock
     */
    private function __construct(
        private Composer $composer,
        private IOInterface $io,
        private ?string $mode,
        private bool $isDev,
        private string $ignoreLock
    ) {
    }

    /**
     * @return Composer
     */
    public function composer(): Composer
    {
        return $this->composer;
    }

    /**
     * @return IOInterface
     */
    public function composerIo(): IOInterface
    {
        return $this->io;
    }

    /**
     * @return \Composer\Config
     */
    public function composerConfig(): \Composer\Config
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = $this->composer->getConfig();
        }

        /** @var \Composer\Config $config */
        $config = $this->objects[__FUNCTION__];

        return $config;
    }

    /**
     * @return RootPackageInterface
     */
    public function composerRootPackage(): RootPackageInterface
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = $this->composer->getPackage();
        }

        /** @var RootPackageInterface $root */
        $root = $this->objects[__FUNCTION__];

        return $root;
    }

    /**
     * @return RepositoryInterface
     */
    public function composerRepository(): RepositoryInterface
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $manager = $this->composer->getRepositoryManager();
            $this->objects[__FUNCTION__] = $manager->getLocalRepository();
        }

        /** @var RepositoryInterface $repo */
        $repo = $this->objects[__FUNCTION__];

        return $repo;
    }

    /**
     * @return HttpDownloader
     */
    public function composerHttpDownloader(): HttpDownloader
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = \Composer\Factory::createHttpDownloader(
                $this->composerIo(),
                $this->composerConfig()
            );
        }
        /** @var HttpDownloader */
        return $this->objects[__FUNCTION__];
    }

    /**
     * @return Filesystem
     */
    public function filesystem(): Filesystem
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = new Filesystem();
        }

        /** @var Filesystem $filesystem */
        $filesystem = $this->objects[__FUNCTION__];

        return $filesystem;
    }

    /**
     * @return Io
     */
    public function io(): Io
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = Io::new($this->io);
        }

        /** @var Io $io */
        $io = $this->objects[__FUNCTION__];

        return $io;
    }

    /**
     * @return ModeResolver
     */
    public function modeResolver(): ModeResolver
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = ModeResolver::new($this->mode, $this->isDev);
        }

        /** @var ModeResolver $resolver */
        $resolver = $this->objects[__FUNCTION__];

        return $resolver;
    }

    /**
     * @return Config
     */
    public function config(): Config
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = Config::forComposerPackage(
                $this->composerRootPackage(),
                $this->rootFolder(),
                $this->modeResolver(),
                $this->filesystem()
            );
        }

        /** @var Config $config */
        $config = $this->objects[__FUNCTION__];

        return $config;
    }

    /**
     * @return RootConfig
     */
    public function rootConfig(): RootConfig
    {
        if (!isset($this->objects[__FUNCTION__])) {
            /** @var RootConfig $root */
            $root = $this->config()->rootConfig();
            $this->objects[__FUNCTION__] = $root;
        }

        /** @var RootConfig $config */
        $config = $this->objects[__FUNCTION__];

        return $config;
    }

    /**
     * @return Defaults
     */
    public function defaults(): Defaults
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $defaults = $this->rootConfig()->defaults();
            $this->objects[__FUNCTION__] = $defaults
                ? Defaults::new($defaults)
                : Defaults::newEmpty();
        }

        /** @var Defaults $defaults */
        $defaults = $this->objects[__FUNCTION__];

        return $defaults;
    }

    /**
     * @return ProcessExecutor
     */
    public function processExecutor(): ProcessExecutor
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = new ProcessExecutor($this->io);
        }

        /** @var ProcessExecutor $executor */
        $executor = $this->objects[__FUNCTION__];

        return $executor;
    }

    /**
     * @return \Iterator<Asset>
     */
    public function assets(): \Iterator
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $assets = $this->assetsFinder()->find(
                $this->composerRepository(),
                $this->composerRootPackage(),
                $this->assetFactory(),
                $this->rootConfig()->autoDiscover()
            );

            $this->objects[__FUNCTION__] = new \ArrayIterator(array_values($assets));
        }

        /** @var \Iterator<Asset> $assets */
        $assets = $this->objects[__FUNCTION__];
        $assets->rewind();

        return $assets;
    }

    /**
     * @return PackageManager\Finder
     */
    public function commandsFinder(): PackageManager\Finder
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = PackageManager\Finder::new(
                $this->processExecutor(),
                $this->filesystem(),
                $this->io(),
                $this->config()->defaultEnv()
            );
        }

        /** @var PackageManager\Finder $finder */
        $finder = $this->objects[__FUNCTION__];

        return $finder;
    }

    /**
     * @return AssetFinder
     */
    public function assetsFinder(): AssetFinder
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $config = $this->rootConfig();
            $this->objects[__FUNCTION__] = AssetFinder::new(
                $config->packagesData(),
                $this->modeResolver(),
                $this->defaults(),
                $this->config(),
                $config->stopOnFailure()
            );
        }

        /** @var AssetFinder $finder */
        $finder = $this->objects[__FUNCTION__];

        return $finder;
    }

    /**
     * @return string
     */
    public function rootFolder(): string
    {
        $source = $this->composerConfig()->getConfigSource();

        return $this->filesystem()->normalizePath(dirname($source->getName()));
    }

    /**
     * @return AssetFactory
     */
    public function assetFactory(): AssetFactory
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = AssetFactory::new(
                $this->modeResolver(),
                $this->filesystem(),
                $this->composer->getInstallationManager(),
                $this->rootFolder(),
                $this->config()->defaultEnv()
            );
        }

        /** @var AssetFactory $factory */
        $factory = $this->objects[__FUNCTION__];

        return $factory;
    }

    /**
     * @return HashBuilder
     */
    public function hashBuilder(): HashBuilder
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = HashBuilder::new(
                $this->assetsPathsFinder(),
                $this->io()
            );
        }

        /** @var HashBuilder $builder */
        $builder = $this->objects[__FUNCTION__];

        return $builder;
    }

    /**
     * @return HttpClient
     */
    public function httpClient(): HttpClient
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = HttpClient::new(
                $this->composerHttpDownloader(),
                $this->io()
            );
        }

        /** @var HttpClient $client */
        $client = $this->objects[__FUNCTION__];

        return $client;
    }

    /**
     * @return ArchiveDownloaderFactory
     */
    public function archiveDownloaderFactory(): ArchiveDownloaderFactory
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = ArchiveDownloaderFactory::new(
                $this->composer()->getLoop(),
                $this->composer()->getDownloadManager(),
                $this->filesystem(),
                $this->io()
            );
        }

        /** @var ArchiveDownloaderFactory $factory */
        $factory = $this->objects[__FUNCTION__];

        return $factory;
    }

    /**
     * @return ArchiveDownloaderAdapter
     */
    public function archiveDownloaderAdapter(): ArchiveDownloaderAdapter
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = ArchiveDownloaderAdapter::new(
                $this->io(),
                $this->archiveDownloaderFactory()
            );
        }

        /** @var ArchiveDownloaderAdapter $adapter */
        $adapter = $this->objects[__FUNCTION__];

        return $adapter;
    }

    /**
     * @return GithubActionArtifactAdapter
     */
    public function githubArtifactAdapter(): GithubActionArtifactAdapter
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = GithubActionArtifactAdapter::new(
                $this->io(),
                $this->httpClient(),
                $this->archiveDownloaderFactory()
            );
        }

        /** @var GithubActionArtifactAdapter $adapter */
        $adapter = $this->objects[__FUNCTION__];

        return $adapter;
    }

    /**
     * @return GithubReleaseZipAdapter
     */
    public function githubReleaseZipAdapter(): GithubReleaseZipAdapter
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = GithubReleaseZipAdapter::new(
                $this->io(),
                $this->httpClient(),
                $this->archiveDownloaderFactory()
            );
        }

        /** @var GithubReleaseZipAdapter $adapter */
        $adapter = $this->objects[__FUNCTION__];

        return $adapter;
    }

    /**
     * @return Handler
     */
    public function preCompilationHandler(): Handler
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $handler = Handler::new(
                $this->hashBuilder(),
                $this->io(),
                $this->filesystem(),
                $this->modeResolver()
            );
            $handler = $handler
                ->registerAdapter($this->archiveDownloaderAdapter())
                ->registerAdapter($this->githubArtifactAdapter())
                ->registerAdapter($this->githubReleaseZipAdapter());
            $this->objects[__FUNCTION__] = $handler;
        }

        /** @var Handler $handler */
        $handler = $handler ?? $this->objects[__FUNCTION__];

        return $handler;
    }

    /**
     * @return Locker
     */
    public function locker(): Locker
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = new Locker(
                $this->io(),
                $this->hashBuilder(),
                $this->ignoreLock
            );
        }

        /** @var Locker $locker */
        $locker = $this->objects[__FUNCTION__];

        return $locker;
    }

    /**
     * @return callable
     */
    public function processOutputHandler(): callable
    {
        if (!!isset($this->objects[__FUNCTION__])) {
            /** @var callable(string,string):void $handler */
            $handler = $this->objects[__FUNCTION__];

            return $handler;
        }

        $io = $this->io();
        $handler = static function (string $type, string $buffer) use ($io): void {
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                Process::ERR === $type
                    ? $io->writeVeryVerboseError('   ' . trim($line))
                    : $io->writeVeryVerbose('   ' . trim($line));
            }
        };

        $this->objects[__FUNCTION__] = $handler;

        return $handler;
    }

    /**
     * @return ProcessFactory
     */
    public function processFactory(): ProcessFactory
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = ProcessFactory::new();
        }

        /** @var ProcessFactory $factory */
        $factory = $this->objects[__FUNCTION__];

        return $factory;
    }

    /**
     * @return ParallelManager
     */
    public function processManager(): ParallelManager
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $config = $this->rootConfig();
            $this->objects[__FUNCTION__] = ParallelManager::new(
                $this->processOutputHandler(),
                $this->processFactory(),
                $config->maxProcesses(),
                $config->processesPoll(),
                $config->timeoutIncrement()
            );
        }

        /** @var ParallelManager $parallelManager */
        $parallelManager = $this->objects[__FUNCTION__];

        return $parallelManager;
    }

    /**
     * @return Processor
     */
    public function assetsProcessor(): Processor
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = Processor::new(
                $this->io(),
                $this->config(),
                $this->commandsFinder(),
                $this->processExecutor(),
                $this->processManager(),
                $this->locker(),
                $this->preCompilationHandler(),
                $this->processOutputHandler(),
                $this->filesystem()
            );
        }

        /** @var Processor $processor */
        $processor = $this->objects[__FUNCTION__];

        return $processor;
    }

    /**
     * @return PathsFinder
     */
    public function assetsPathsFinder(): PathsFinder
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = PathsFinder::new(
                $this->assets(),
                $this->filesystem(),
                $this->io(),
                $this->rootConfig()->path()
            );
        }

        /** @var PathsFinder $finder */
        $finder = $this->objects[__FUNCTION__];

        return $finder;
    }

    /**
     * @return Info
     */
    public function assetsInfo(): Info
    {
        if (!isset($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = Info::new(
                $this->assets(),
                $this->hashBuilder(),
                $this->preCompilationHandler(),
                $this->assetsPathsFinder(),
                $this->modeResolver(),
                $this->rootConfig(),
                $this->io()
            );
        }

        /** @var Info $info */
        $info = $this->objects[__FUNCTION__];

        return $info;
    }
}
