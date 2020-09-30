<?php

declare(strict_types=1);

namespace Inpsyde\AssetsCompiler\Util;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\RemoteFilesystem;
use Inpsyde\AssetsCompiler\Commands\Commands;
use Inpsyde\AssetsCompiler\Commands\Finder as CommandsFinder;
use Inpsyde\AssetsCompiler\Asset\Config;
use Inpsyde\AssetsCompiler\Asset\Defaults;
use Inpsyde\AssetsCompiler\Asset\Factory as AssetFactory;
use Inpsyde\AssetsCompiler\Asset\Finder as AssetFinder;
use Inpsyde\AssetsCompiler\Asset\HashBuilder;
use Inpsyde\AssetsCompiler\Asset\Locker;
use Inpsyde\AssetsCompiler\Asset\Asset;
use Inpsyde\AssetsCompiler\Asset\Processor;
use Inpsyde\AssetsCompiler\Asset\RootConfig;
use Inpsyde\AssetsCompiler\PreCompilation\ArchiveDownloaderAdapter;
use Inpsyde\AssetsCompiler\PreCompilation\GithubActionArtifactAdapter;
use Inpsyde\AssetsCompiler\PreCompilation\GithubReleaseZipAdapter;
use Inpsyde\AssetsCompiler\PreCompilation\Handler;
use Inpsyde\AssetsCompiler\Process\Factory as ProcessFactory;
use Inpsyde\AssetsCompiler\Process\ParallelManager;
use Symfony\Component\Process\Process;

final class Factory
{

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var string|null
     */
    private $env;

    /**
     * @var bool
     */
    private $isDev;

    /**
     * @var array<string, object>
     */
    private $objects = [];

    /**
     * @param Composer $composer
     * @param IOInterface $io
     * @return Factory
     */
    public static function new(
        Composer $composer,
        IOInterface $io,
        ?string $env,
        bool $isDev
    ): Factory {

        return new static($composer, $io, $env, $isDev);
    }

    /**
     * @param Composer $composer
     * @param IOInterface $io
     * @param RootConfig $rootConfig
     */
    private function __construct(
        Composer $composer,
        IOInterface $io,
        ?string $env,
        bool $isDev
    ) {

        $this->composer = $composer;
        $this->io = $io;
        $this->env = $env ?? EnvResolver::assetsCompilerEnv();
        $this->isDev = $isDev;
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
        if (empty($this->objects[__FUNCTION__])) {
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
        if (empty($this->objects[__FUNCTION__])) {
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
        if (empty($this->objects[__FUNCTION__])) {
            /** @var RepositoryManager $manager */
            $manager = $this->composer->getRepositoryManager();
            $this->objects[__FUNCTION__] = $manager->getLocalRepository();
        }

        /** @var RepositoryInterface $repo */
        $repo = $this->objects[__FUNCTION__];

        return $repo;
    }

    /**
     * @return Filesystem
     */
    public function filesystem(): Filesystem
    {
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = new Filesystem();
        }

        /** @var Filesystem $filesystem */
        $filesystem = $this->objects[__FUNCTION__];

        return $filesystem;
    }

    /**
     * @return RemoteFilesystem
     */
    public function remoteFilesystem(): RemoteFilesystem
    {
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = \Composer\Factory::createRemoteFilesystem(
                $this->composerIo(),
                $this->composerConfig()
            );
        }

        /** @var RemoteFilesystem $filesystem */
        $filesystem = $this->objects[__FUNCTION__];

        return $filesystem;
    }

    /**
     * @return Io
     */
    public function io(): Io
    {
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = Io::new($this->io);
        }

        /** @var Io $io */
        $io = $this->objects[__FUNCTION__];

        return $io;
    }

    /**
     * @return EnvResolver
     */
    public function envResolver(): EnvResolver
    {
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = EnvResolver::new($this->env, $this->isDev);
        }

        /** @var EnvResolver $resolver */
        $resolver = $this->objects[__FUNCTION__];

        return $resolver;
    }

    /**
     * @return RootConfig
     */
    public function config(): RootConfig
    {
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = RootConfig::new(
                $this->composerRootPackage(),
                $this->envResolver(),
                $this->filesystem(),
                $this->rootFolder()
            );
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
        if (empty($this->objects[__FUNCTION__])) {
            $config = $this->config()->defaults();
            $this->objects[__FUNCTION__] = $config
                ? Defaults::new(Config::forAssetConfigInRoot($config, $this->envResolver()))
                : Defaults::empty();
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
        if (empty($this->objects[__FUNCTION__])) {
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
        if (empty($this->objects[__FUNCTION__])) {
            /** @var array<string, Asset> $assets */
            $assets = $this->assetsFinder()->find(
                $this->composerRepository(),
                $this->composerRootPackage(),
                $this->assetFactory(),
                $this->config()->autoDiscover()
            );

            $this->objects[__FUNCTION__] = new \ArrayIterator(array_values($assets));
        }

        /** @var \Iterator<Asset> $assets */
        $assets = $this->objects[__FUNCTION__];
        $assets->rewind();

        return $assets;
    }

    /**
     * @return CommandsFinder
     */
    public function commandsFinder(): CommandsFinder
    {
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = CommandsFinder::new(
                $this->config(),
                $this->processExecutor(),
                $this->io()
            );
        }

        /** @var CommandsFinder $finder */
        $finder = $this->objects[__FUNCTION__];

        return $finder;
    }

    /**
     * @return Commands
     */
    public function commands(): Commands
    {
        if (empty($this->objects[__FUNCTION__])) {
            /** @var Asset|null $asset */
            $asset = $this->assets()->current();
            $path = ($asset instanceof Asset) ? $asset->path() : null;
            $this->objects[__FUNCTION__] = $path
                ? $this->commandsFinder()->find($path)
                : Commands::new([], []);
        }

        /** @var Commands $commands */
        $commands = $this->objects[__FUNCTION__];
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->commandsFinder()->assertValid($commands);

        return $commands;
    }

    /**
     * @return AssetFinder
     */
    public function assetsFinder(): AssetFinder
    {
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = AssetFinder::new(
                $this->config()->packagesData(),
                $this->envResolver(),
                $this->defaults(),
                $this->rootFolder(),
                $this->config()->stopOnFailure()
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
        /** @var \Composer\Config\ConfigSourceInterface $source */
        $source = $this->composerConfig()->getConfigSource();

        return $this->filesystem()->normalizePath(dirname((string)$source->getName()));
    }

    /**
     * @return AssetFactory
     */
    public function assetFactory(): AssetFactory
    {
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = AssetFactory::new(
                $this->envResolver(),
                $this->filesystem(),
                $this->composer->getInstallationManager(),
                $this->rootFolder()
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
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = HashBuilder::new(
                $this->envResolver()->env(),
                $this->config()->defaultEnv()
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
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = HttpClient::new($this->io(), $this->composer());
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
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = ArchiveDownloaderFactory::new(
                $this->io(),
                $this->composer(),
                $this->processExecutor(),
                $this->filesystem()
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
        if (empty($this->objects[__FUNCTION__])) {
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
        if (empty($this->objects[__FUNCTION__])) {
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
        if (empty($this->objects[__FUNCTION__])) {
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
        if (empty($this->objects[__FUNCTION__])) {
            $handler = Handler::new($this->hashBuilder(), $this->io(), $this->filesystem());
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
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = new Locker(
                $this->io(),
                $this->hashBuilder()
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
        if (!empty($this->objects[__FUNCTION__])) {
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
        if (empty($this->objects[__FUNCTION__])) {
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
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = ParallelManager::new(
                $this->processOutputHandler(),
                $this->processFactory(),
                $this->config()->maxProcesses(),
                $this->config()->processesPoll()
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
        if (empty($this->objects[__FUNCTION__])) {
            $this->objects[__FUNCTION__] = Processor::new(
                $this->io(),
                $this->config(),
                $this->commands(),
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
}
