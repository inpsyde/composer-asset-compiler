# Composer Asset Compiler

_Composer plugin that installs dependencies and compiles assets based on configuration._

![PHP Quality Assurance](https://github.com/inpsyde/composer-asset-compiler/workflows/PHP%20Quality%20Assurance/badge.svg)

----

## What is this / Why bother

When working with WordPress and Composer for the whole site, we use Composer to pull together
plugins and themes. These often have **frontend assets** (like JavaScript, CSS, etc.), and many of
them use **frontend assets processing** (like webpack, Grunt, gulp, etc.).

Since Composer installs packages from version control repositories, it is usually required that the
packages keep the processed assets under version control so that they can be used in the browser
when we visit the site.

However, keeping processed assets under version control is something we want to avoid because we
consider them "compiled artifacts" that don't belong under version control along with the source
code. And that is the scope of this Composer plugin.

The themes/plugins will have a small configuration in their `composer.json` files that specifies
what is needed to process the package assets. Then when packages are required at the website level
along with this plugin, the assets will be processed "on the fly" after Composer completes the
installation.

## A quick example

Let's assume we have a website project whose `composer.json` looks like this:

```json
{
    "name": "acme/my-project",
    "type": "project",
    "require": {
        "acme/some-plugin": "^1",
        "acme/some-theme": "^2",
        "inpsyde/composer-assets-compiler": "^2.4"
    },
    "extra": {
        "composer-asset-compiler": {
            "auto-run": true
        }
    }
}
```

And then suppose that `acme/some-plugin` has a `composer.json` that looks like this:

```json
{
    "name": "acme/some-plugin",
    "type": "wordpress-plugin",
    "extra": {
        "composer-asset-compiler": "gulp"
    }
}
```

And `acme/some-theme` has a `composer.json` that looks like this:

```json
{
    "name": "acme/some-theme",
    "type": "wordpress-theme",
    "extra": {
        "composer-asset-compiler": "build"
    }
}
```

When we install the project with Composer via `composer install`, the following happens:

1. Composer installs the three required packages
2. immediately after that, the plugin starts and
    1. searches among all installed packages (including transitive dependencies) for those that have
       a `composer-asset-compiler` configuration, and finds "acme/some-plugin"
       and "acme/some-theme"
    2. changes to the "acme/some-plugin" installation folder and
       executes `npm install && npm run gulp` (
       or `yarn && yarn gulp`)
    3. changes to the "acme/some-theme" installation folder and
       executes `npm install && npm run build` (
       or `yarn && yarn build`)

This means that at the end of the process we have a project with the plugin and theme installed, and
the assets for both processed.

Please note that the two packages must each declare a `gulp` and a `build` script in
their `package.json` for this to work.

## Root vs Dependency configuration

The first thing to note in the "**quick example**" above is that we are using a different
configuration for the "root" package (the package we are installing through Composer).

Like Composer itself, the plugin supports different configurations for the root package and the
packages that are required as dependencies.

In general, in the root package, we can set how the plugin works, in the dependency, we specify what
is needed to process the assets of the package.

In the example of the previous section, at the root level we have `"auto-run": true`, which
instructs the plugin to process the assets immediately after installing `composer install|update`.
This is a root-only configuration, as we run `composer install|update` at the root level. For
example, if this setting is `false`, we need to run `composer install && composer compile-assets` to
get the same result.

However, it is important to note that each dependency can be a "root" package, and each "
root" package is also a package. This means that we can have both types of configurations in each
Composer package, but the root configuration is only considered when the package is installed as
root ( think of it as `require-dev` in the Composer configuration.

## Pre-compilation

In the "**quick example**" above, we have two packages whose assets are processed "on the fly". In a
real project, the number of packages could be much higher.

Installing frontend dependencies and processing them can take *several minutes per package*. For a
project with 20 or more packages, this is *a lot*.

To solve this problem, this plugin implements a so-called "pre-compilation". It works as follows:

1. The assets of the packages are compiled separately and provided to a storage service supported by
   the plugin. At the time of writing, the plugin supports GitHub Artifacts, GitHub Release Binary,
   and "generic" archive URLs, optionally protected via Basic Auth.
2. When the Composer plugin finds a package for which assets should be compiled, instead of
   immediately installing dependencies and processing the assets, it checks if the package supports
   pre-compilation, and if so, it tries to download the pre-compiled assets.
3. If pre-compiled assets are found, they are downloaded and used; if they are not found, the plugin
   continues installing dependencies and processing the assets as usual.

The asset compilation plugin is independent of how the assets are compiled (step *1.*
above): as long as an archive with pre-compiled assets is found, the plugin can work with it.

Here is an example of how we can configure pre-compilation for a package:

```json
{
    "name": "acme/some-theme",
    "type": "wordpress-theme",
    "extra": {
        "composer-asset-compiler": {
            "pre-compiled": {
                "adapter": "gh-action-artifact",
                "config": {
                    "repository": "acme/some-theme"
                },
                "source": "assets-${ref}",
                "target": "./assets/"
            },
            "script": "build"
        }
    }
}
```

In the snippet above we see that the configuration is now an object and the script to be
executed (`"build"`) has been moved under the `script` property.

The `pre-compiled` property tells us that the package supports pre-compilation. Let's look at the
configuration in detail.

`pre-compiled.target` is the folder where the precompiled assets will be placed after download.

`pre-compiled.adapter` tells us where the precompiled assets will be stored. `"gh-action-artifact"`
in the above example tells us that the precompiled assets will be stored
as [GitHub Actions artifacts](https://docs.github.com/en/actions/guides/storing-workflow-data-as-artifacts)
. Other supported adapters are `"gh-release-zip"` (assets saved as a zip are saved
as [GitHub release binary](https://docs.github.com/en/github/administering-a-repository/releasing-projects-on-github/managing-releases-in-a-repository#creating-a-release))
, and `"archive"` (assets saved in an archive accessible via a URL, optionally protected via Basic
Auth).

`pre-compiled.source` and `pre-compiled.config` have different meanings depending on the `adapter`.

### GitHub adapters config

The `"gh-action-artifact"` and the `"gh-release-zip"` adapters share the same values
for `pre-compiled.source` and `pre-compiled.config`.

For both adapters, `pre-compiled.source` is the name of the archive. It may contain dynamic
placeholders (like the `${ref}` in the snippet above). More on this below.

`pre-compiled.config` is a configuration object for the adapter, and its only mandatory property
is `pre-compiled.config.repository` which is the owner and name of the GitHub repository. For
example, `"repository": "acme/some-theme"` means that the repository URL
is `https://github.com/acme/some-theme.git`.

If the repository is private, we need to specify the username of a GitHub user who has access to the
repository, and
an [access token](https://docs.github.com/en/github/authenticating-to-github/keeping-your-account-and-data-secure/creating-a-personal-access-token)
for that user.

The username can be set via the `pre-compiled.config.user` property or via the `GITHUB_USER_NAME`
environment variable in the system running the asset compiler plugin.

The access token could be set via the `pre-compiled.config.token` property, but this is strongly
discouraged. A safer approach is to use the `GITHUB_USER_TOKEN` environment variable or rely on
Composer authentication using
the [`github-oauth` method](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#github-oauth)
. In the latter case, configuring the username is not necessary.

### Archive adapter configuration

When using the `archive` adapter, `pre-compiled.source` is the full archive URL. It may contain
dynamic placeholders (
more on this below).

`pre-compiled.config` is an object, and it is completely optional. It can have
a `pre-compiled.config.type` to configure the archive type (supported: "zip", "rar", "tar"
and "xz"). If it is not specified, it is guessed based on the file extension in the source URL, and
if it has no file extension, it defaults to `zip`.

The additional `pre-compiled.config.auth` property can be used to set up basic authentication for
the archive (via both `pre-compiled.config.auth.user`
and `pre-compiled.config.auth.password` properties), but this is not recommended. Prefer Composer
authentication via
the [`http-basic` method](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#http-basic).

### Placeholders

Regardless of the adapter, `pre-compiled.source` can contain one or more placeholders that are
dynamically replaced when the assets are compiled.

The supported placeholders are:

- **`${ref}`** - replaced by the "reference" of the Composer package (which for GitHub hosted
  packages is the SHA1 of the commit to be installed).
- **`${version}`** - replaced by the version of the Composer package to be install.
- **`${env}`** - replaced by the Composer Asset Compiler environment (more on this below)
- **`${hash}`** - replaced by the hash of the package (more on this below)

### Pre-compilation by stability

Sometimes we would like to have a different mechanism for pre-compilation, depending on whether the
package being installed has a stability `stable` or not.

For example, we might want to use the `"gh-release-zip"` adapter only if we are using a "
stable" version, because if we need a package from branches like "dev-main", there is no release for
it.

This can be achieved by using two sets of `pre-compiled`, one specifying "stable"
stability and one specifying "dev"
stability. For example:

```json
{
    "pre-compiled": [
        {
            "stability": "stable",
            "adapter": "gh-release-zip",
            "source": "assets-${version}",
            "target": "./assets/",
            "config": {
                "repository": "acme/some-theme"
            }
        },
        {
            "stability": "dev",
            "adapter": "gh-action-artifact",
            "source": "assets-${ref}",
            "target": "./assets/",
            "config": {
                "repository": "acme/some-theme"
            }
        }
    ],
    "script": "build"
}
```

## The "lock file" and the "package hash"

Each time the assets for a package are processed, a lock file named **`.composer_compiled_assets`**
is saved in the root directory of the package.

That file should be git-ignored if the compiled assets are git-ignored.

The lock file contains a hash, referenced as "*Composer Asset Compiler package's hash*", which is
created from the Composer Assets Compiler configuration and the contents of certain files such
as `package.json`, `package-lock.json`
, `npm-shrinkwrap.json`, and `yarn.lock`.

If a lock file is found and the content of the hash in it matches the hash of the current package,
the Composer Assets Compiler plugin will not process the assets of the package.

Since the hash of the Composer Assets Compiler package does *not* depend on the actual asset source
file, the Composer Assets Compiler might skip processing the assets of a package even if the asset
source has changed.

To prevent this, it is possible to use the `--ignore-lock` flag when running the compile assets
command command:

````shell
composer compile-assets --ignore-lock
````

The hash of the package can also be calculated programmatically using the command:

```shell
composer assets-hash
```

This can be useful to generate the filename of the archive during pre-compilation, in case we want
to use the `${hash}`
placeholder.

## Asset Compiler env

In most cases, the way assets are processed depends on the current environment. The Composer Asset
Compiler has a native method to deal with this problem.

The "*Asset Compiler env*" is an arbitrary string that can be set in two ways:

- passing the `--env` flag to the `compile-assets` command
- setting the `COMPOSER_ASSETS_COMPILER` environment variable

If the `env` is configured, it can be used in the `${env}` placeholder before compilation, and also
to have different configurations per environment.

For example:

```json
{
    "name": "acme/some-theme",
    "type": "wordpress-theme",
    "extra": {
        "composer-asset-compiler": {
            "env": {
                "$default": "build",
                "production": "build-prod"
            }
        }
    }
}
```

The "env" property is the entry point for the environment-specific configuration. It must contain an
object where the keys are the environment names and the values are the associated configuration.

The key `$default` is a special key used when no Composer Asset Compiler environment is available,
i.e. when the environment variable `COMPOSER_ASSETS_COMPILER` is not set and the flag `--env` is not
passed to the command is passed to the `compile-assets` command.

When used as a "root item", as in the above snippet, the `env` key forces us to have the entire set
of configurations per environment. If we have more than just the script (e.g. the configuration
before compilation), we may end up with a very verbose configuration and duplicates.

In such cases it is possible to use the "env" key only for the configuration properties that need to
be environment-specific, in the above example the "script" property:

```json
{
    "name": "acme/some-theme",
    "extra": {
        "composer-asset-compiler": {
            "pre-compiled": {
                "adapter": "gh-action-artifact",
                "config": {
                    "repository": "acme/some-theme"
                },
                "source": "assets-${ref}",
                "target": "./assets/"
            },
            "script": {
                "env": {
                    "$default": "build",
                    "production": "build-prod"
                }
            }
        }
    }
}

```

## Advanced "script" configuration

`script` is the main configuration for the plugin and benefits from a "special treatment":
it can use environment variables.

For example, it is possible to write a configuration like this:

```json
{
    "name": "acme/some-plugin",
    "extra": {
        "composer-asset-compiler": {
            "script": "gulp -- ${GULP_ASSETS_TASK}"
        }
    }
}
```

With the above configuration, if we set the environment variable `GULP_ASSETS_TASK`
to `"build"`, the Composer Assets compiler will run `npm install && npm run gulp build`
for the package.

When we use environment variables for scripts in this way, we are probably relying on these
environment variables being set, and if they are not, an error could occur.

To define a default for undefined environment variables, we can use the `default-env`
configuration property:

```json
{
    "name": "acme/some-plugin",
    "extra": {
        "composer-asset-compiler": {
            "default-env": {
                "GULP_ASSETS_TASK": "build"
            },
            "script": "gulp -- ${GULP_ASSETS_TASK}"
        }
    }
}

```

Although it may be confusing, please note that the `default-env` property is very different from
the `env`
property: `default-env` is used to define a fallback for *environment variables* which could be used
in the `script`
configuration, while the `env` property is a possibility, distinguish configurations based on "*
Composer Asset Compiler env*", which is an arbitrary value defined either by the `--env` command
flag or by the `COMPOSER_ASSETS_COMPILER`
environment variable.

## Configuration file

In some cases, especially when using additional env-specific pre-compilation settings and the
default environment, the configuration object can become very large and "
pollute" `composer.json`.

In such cases, it is possible to use the same configuration that is stored in
in `extra.composer-asset-compiler` in a **
separate file named `assets-compiler.json`** in the root directory of the package. When adding this
file, it is not necessary to add anything in `composer.json`.

If one has _both_ `assets-compiler.json` and `extra.composer-asset-compiler`
in `composer.json`, the latter will be ignored and only the contents of `assets-compiler.json` will
be considered.

## Package managers

In theory, the requirements in a `package.json` can be installed using either npm or yarn, with no
difference. However, experience shows that this is not always the case, especially if there is a
package manager-specific lock file (
e.g. `yarn.lock` or `npm-shrinkwrap.json`).

Composer Asset Compiler supports both npm and Yarn. If a package has a lock file, the plugin runs
the package manager that created that lock file.

Even if there is no lock file, it is possible to tell Composer Asset Compiler to use a specific
package manager via the `package-manager` configuration. An example `assets-compiler.json`:

```json
{
    "script": "build",
    "package-manager": "npm"
}
```

If no package manager is configured and no lock file is present, Composer Asset Compiler will use
either Yarn and npm for this package (in that order and if installed).

If a package manager is configured (or a package manager-specific lock file exists), but the
selected package manager is not available in the system, Composer Asset Compiler will *not* fail,
but will attempt to process assets with the available package manager.

For example, if npm is not available in the system, Composer Asset Compiler will attempt to use Yarn
when processing a package with the configuration in the snippet above.

## Configuration cheat sheet

| Name | Description | Root-only | Default |
| ------------------- | ------------------------------------------------------------ | --------- | --------- |
| `auto-discover` | If false, the plugin processes only the root package and ignores all dependencies. | Yes | true |
| `auto-run` | If true, the plugin will run immediately after each Composer update or installation. | Yes | false |
| `defaults` | Provides a default configuration for packages defined at root level. | Yes | -- |
| `default-env` | Provide default values for environment variables. | No | -- |
| `dependencies` | Either "install", "update", or "none". Specifies if/how the package manager installs dependencies. | No | "install" |
| `isolated-cache` | If "true", the package manager uses a separate cache for each package. | Yes | false |
| `max-processes` | Number of maximum parallel access processing. | Yes | 4 |
| `package-manager` | Which package manager to use. | No | -- |
| `packages` | Can be used at the root level to override dependency configuration. | Yes | -- |
| `pre-compiled` | Pre-compilation settings. | No | -- |
| `processes-poll` | Interval in microseconds for updating the parallel processing status. | Yes | 100000 |
| `script` | The script used to process assets. | No | -- |
| `stop-on-failure` | If false, asset processing will continue for other packages if an error occurs for one package. The exit code will still be non-zero in case of an error. | Yes | true |
| `wipe-node-modules` | Whether the `node_modules` folder of packages should be deleted after processing. Only applies to folders created by the plugin: existing folders are always kept. | Yes | false |

## CLI parameters

### Command: `compile-assets`

| Param | Description |
| ---------------------------- | ------------------------------------------------------------ |
| `--ignore-lock[=<packages>]` | Ignores locks for all or specific packages. Multiple packets must be comma-separated. |
| `--env=<env>` | Specifies the asset compiler "env" which is used to select between multiple configuration variants. |
| `--hash-seed=<seed>` | Uses the provided seed when generating the package hash.<br />Must match the seed passed to `assets-hash --seed`. |
| `--no-dev` | Simulate auto-run of the Assets Compiler after a Composer installation/update with the `--no-dev` flag.<br />This causes the plugin to check for `$default-no-dev` as the default `env` before falling back to `$default`. |

### Command: `assets-hash`

| Param | Description |
| --------------- | ------------------------------------------------------------ |
| `--seed=<seed>` | Causes the hash of generated packages to change based on the seed.<br />Must match the seed passed to `compile-assets --hash-seed`. |
| `--env` | Sets the asset compiler "env" which affects the hash of the assets. |
| `--no-dev` | Simulate Assets Compiler auto-run after a Composer install/update with the `--no-dev` flag.<br />This affects the hash of the assets if the configuration of the `script` package uses `$default-no-dev` as the default "env". |

## Advanced topics

### Package definitions in the root package

The "normal" workflow of the plugin expects each dependency to define what is needed to process its
assets. However, it is possible to add/remove/edit the configuration for a dependency in the root
package. An example `assets-compiler.json`:

```json
{
    "auto-run": true,
    "defaults": {
        "script": "gulp",
        "package-manager": "npm"
    },
    "packages": {
        "acme/plugin-one": true,
        "acme/plugin-two": false,
        "acme/plugin-three": "force-defaults",
        "acme/theme-*": {
            "script": "build",
            "package-manager": "yarn"
        }
    }
}
```

In the above example, the `packages` property is used to change the configuration of some
dependencies, and all possible configuration types are shown:

- if `true` is used, it means: "*if the package has a configuration, use it, otherwise use the
  defaults*"
- if `false` is used, it means: "*do not process assets for this package*"
- if `force-defaults` is used, it means: "*Process the assets for this package using the default
  settings*"
- if `force-defaults` is used, it means "*process the assets for this package using this
  configuration*"

If `true` or `force-defaults` are used, the defaults must also be configured using the `defaults`
property.

Please note that with the configuration in the above example, the Composer Asset Compiler will not
only process the packages listed in `packages`, but will also search the installed dependencies for
other packages to process. This can be prevented by setting the `auto-discover` configuration
to `false`: this will only process the listed packages (
besides the root package itself if it has a valid configuration).

### Verbosity

`compile-assets` is a command added to Composer, which means that all Composer
commands ["global options"](https://getcomposer.org/doc/03-cli.md#global-options) are valid. The
options that control `verbosity` have multiple effects on Composer Asset Compiler:

- Composer functions used by the plugin (e.g. the "HTTP Downloader") are customized in their
  verbosity
- Composer Asset Compiler itself gives more or less output depending on these options
- Composer verbosity is "mapped" to package manager verbosity flags according to the following
  table:

| Composer flag | [npm](https://docs.npmjs.com/cli/v7/commands/npm) | [Yarn](https://classic.yarnpkg.com/en/docs/cli/) |
| ------------------ | ------------------------------------------------- | ------------------------------------------------ |
| `-v` / `--verbose` | `-d` |
| `-vv` | `-dd` |
| `-vvv` | `-ddd` | `--verbose` |
| `-q` / `-quite` | `--silent` | `--silent` |

It is worth noting that when using Composer with the `--non-interactive` flag, Yarn gets
the `--non-interactive` flag, but npm has no corresponding flag.

### Isolated cache

Composer Assets Compiler does not normally change the behavior of the package manager. It simply
moves the current working directory to the package path and executes the configured processing
script. It has been our experience that on larger projects with multiple packages to process, the
package manager cache sometimes "gets messed up" and processing fails. When this happens, one
possible solution is to set `isolated-cache` to true. This ensures that each package is processed in
a different cache folder (under the system temp folder), and usually fixes the problem.

Enabling `isolated-cache` comes at the cost of performance, but can be useful in some situations. In
any case, the preferred way to solve *both* "botched cache" and performance problems is to use
precompiled assets.

### Parallel asset processing

The plugin processes multiple packages in parallel to speed up the process. Note that only the _
script_ of the assets is executed in parallel, the installation of the dependencies is executed
serially, as the package manager fails during parallel installation because multiple processes try
to write the same file to the cache at the same time. The plugin implementation for parallel
execution is quite simple and works by starting different processes at the same time, whose status (
completed, failed, running) is then checked at regular intervals. The number of processes that run
concurrently can be controlled by the `max-processes` configuration and the interval by
the `processes-poll` configuration. Using a `max-processes` value that matches the number of system
CPUs can increase performance, provided there is also sufficient memory.
