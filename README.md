# Composer Asset Compiler

_Composer plugin that install dependencies and compile assets based on configuration._

![PHP Quality Assurance](https://github.com/inpsyde/composer-asset-compiler/workflows/PHP%20Quality%20Assurance/badge.svg)

----

## What is this / Why bother

When working with WordPress and Composer for the whole site, we use Composer to pull together
plugins an themes. Those will often have **frontend assets** (as in: javascript, CSS...), and many
of them will use **frontend assets processing** (an is: Webpack, Grunt, Gulp...).

Because Composer pulls packages from plugin/themes version control repositories, it is usually
required that packages keep under version control the _processed_ assets, ready to be used in the
browser when we visit the website.

Keeping _processed_ assets under version control is something we wanted to avoid, because we
consider those "compiled artifacts" that don't belong together with the source code under VCS. And
that's the scope of this Composer plugin.

The themes/plugins will include in their `composer.json` a little configuration that declares what
is needed to process the package's assets, and then when packages are required at website level
alongside this plugin, the packages' asset will be processed "on the fly" after Composer has
finished installing them.

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

And then let's assume that `acme/some-plugin` has a `composer.json` that looks like this:

```json
{
    "name": "acme/some-plugin",
    "type": "wordpress-plugin",
    "extra": {
        "composer-asset-compiler": "gulp"
    }
}
```

and `acme/some-theme` has a `composer.json` that looks like this:

```json
{
    "name": "acme/some-theme",
    "type": "wordpress-theme",
    "extra": {
        "composer-asset-compiler": "build"
    }
}
```

When we would install the project with Composer via `composer install`, what will happen is:

1. Composer will install the three required packages
2. Immediately after that, the plugin will kick-in and will:
    1. Search among all installed packages (including transitive dependencies) searching for those
       that have a `composer-asset-compiler` configuration, and will find "acme/some-plugin" and "
       acme/some-theme"
    2. move to the "acme/some-plugin" installation folder and
       execute `npm install && npm run gulp` (or `yarn && yarn gulp`)
    3. move to the "acme/some-theme" installation folder and
       execute `npm install && npm run build` (or `yarn && yarn build`)

That means that at the end of the process we have a project with the plugin and the theme installed,
and with processed assets for both.

Please note for this to work the two packages must declare, respectively, a `gulp` and a `build`
script in their `package.json`.

## Root vs Dependency configuration

The first thing to note in the "***quick example***" above, is that we're using different
configuration for the "root" package (the one we're actually installing via Composer).

Just like Composer itself, the plugin support a different set of configuration for the root package
and the packages that are required as dependency.

Generally speaking, in the root package we can tweak how the plugin works, in the dependency we tell
what is needed to process the package's assets.

In the previous section's example at the root level we have `"auto-run": true` which instructs the
plugin to process assets immediately after `composer install|update`. That is a root-only config
because we execute Composer install/update at root level. If that setting is false, for example, we
need to run:

````bash
composer install
composer compile-assets
````

to obtain the same result.

However, it is important to note that every dependency can be a "root" package, and every "root"
package is also a package. Which means that we can have both kind of configurations in every
Composer package, but root-only configuration will be took into account only when the package is
installed as root (think of `require-dev` in Composer configuration).

## Pre-compilation

In the "***quick example***" above we have two packages whose assets are processed "on the fly". In
some real-world project the number of packages might be much more higher than that.

Installing frontend dependencies and processing them is something that might take *several minutes
per package*. In a project with 20 or such packages that is *a lot*.

To solve the issue, this plugin implements what is called "pre-compilation". It work like this:

1. the packages asset's are compiled separately, and made available to a storage service supported
   by the plugin. At the moment of writing the plugin supports: GitHub Artifacts, GitHub Release
   Binary, and "generic" archive URLs, optionally protected via basic auth.
2. when the Composer plugin finds a package to compile assets for, instead of immediately installing
   dependencies and processing the assets, it checks if the package supports pre-compilation, and if
   so, attempt to download the pre-compiled assets
3. if pre-compiled assets are found they are downloaded and used, if their are not found the plugin
   proceed in installing dependencies and processing assets as usual.

The assets compile plugin is agnostic about the mode assets are compiled (step *1.* above): as long
as an archive containing pre-compiled assets is found, the plugin can work with that.

Here's an example on how we could configure pre-compilation for a package:

```json
{
    "name": "acme/some-theme",
    "type": "wordpress-theme",
    "extra": {
        "composer-asset-compiler": {
            "script": "build",
            "pre-compiled": {
                "target": "./assets/",
                "adapter": "gh-action-artifact",
                "source": "assets-${ref}",
                "config": {
                    "repository": "acme/some-theme"
                }
            }
        }
    }
}
```

In the snippet above we notice how the configuration is now an object, and the script to be
executed (`"build"`) has been moved under the `script` property.

The `pre-compiled` property tell us that the package is supporting pre-compilation. Let's see the
configuration in detail.

`pre-compiled.target` is the folder where the pre-compiled assets will be placed after being
downloaded.

`pre-compiled.adapter` tell us where the pre-compiled assets are stored. `"gh-action-artifact"` in
the example above tell us that the pre-compiled assets are stored
as [GitHub Actions artifacts](https://docs.github.com/en/actions/guides/storing-workflow-data-as-artifacts)
. Other supported adapters are `"gh-release-zip"` (assets stored as a zip saved
as [GitHub release binary](https://docs.github.com/en/github/administering-a-repository/releasing-projects-on-github/managing-releases-in-a-repository#creating-a-release))
, and `"archive"` (assets stored in an archive reachable via URL, optionally protected via basic
auth).

`pre-compiled.source` and `pre-compiled.config` have a different meaning depending on the `adapter`.

### GitHub adapters config

The `"gh-action-artifact"` and the `"gh-release-zip"` adapters share the same values
for `pre-compiled.source` and  `pre-compiled.config`.

For both adapters `pre-compiled.source` is the archive name. It might contain dynamic placeholders (
like the `${ref}` in the snippet above). More on this below.

`pre-compiled.config` is a configuration object for the adapter, and its only mandatory property
is `pre-compiled.config.repository` which is the owner and name of the GitHub repository. For
example, `"repository": "acme/some-theme"` means the repository URL
is `https://github.com/acme/some-theme.git`.

If the repository is private, to be able to download the artifact/release binary we need to
configure the username of a GitHub user who has access to the repository and
an [access token](https://docs.github.com/en/github/authenticating-to-github/keeping-your-account-and-data-secure/creating-a-personal-access-token)
for that user.

The username can be set via the `pre-compiled.config.user` property or, alternatively, via the
environment variable `GITHUB_USER_NAME` in the system that executes the asset compiler plugin.

The access token could be set via the `pre-compiled.config.token` property, but that is very
discouraged. A more safe approach is to use the `GITHUB_USER_TOKEN` environment variable, or rely on
the Composer authentication with
the [`github-oauth` method](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#github-oauth)
. In this latter case the username configuration is not necessary.

### Archive adapter config

When using the `archive` adapter, `pre-compiled.source` is the full archive URL. It might contain
dynamic placeholders (more on this below).

`pre-compiled.config` is an object, and it is entirely optional. It might have
a `pre-compiled.config.type` to configure the archive type (supported: "zip", "rar", "tar", and "
xz"). If not provided it will be guessed from the file extension in the source URL, and if that does
not have any file extension, it will default to `zip`.

And additional `pre-compiled.config.auth` property can be used to setup basic authentication for
archive (via the two properties `pre-compiled.config.auth.user`
and `pre-compiled.config.auth.password`), however that is discouraged. Prefer Composer
authentication via
the [`http-basic` method.](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#http-basic)

### Placeholders

No matter the adapter, `pre-compiled.source` can contain one or more placeholders that will be
dynamically replaced when assets are compiled.

The supported placeholders are:

- **`${ref}`** - replaced with the "reference" of the Composer package (which for GitHub hosted
  packages is the SHA1 of the commit being installed)
- **`${version}`** - replaced with the Composer package version being installed
- **`${env}`** - replaced with the Composer Asset Compiler env (more on this below)
- **`${hash}`** - replaced with the Composer Asset Compiler package's hash (more on this below)

### Pre-compilation by stability

Sometimes we want to have a different mechanism for pre-compilation depending on the fact that the
package we are installing has a stability "stable" or not.

For example, we might want to use the `"gh-release-zip"` adapter only when using "stable" version,
because if we require a package from things like "dev-master" there's no release for it.

That can be accomplished by using two set of `pre-compiled`, one indicating a stability "stable"
and one indicating a stability "dev". For example:

```json
{
    "script": "build",
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
    ]
}
```

## The "lock file” and the "package hash"

Everytime the assets are processed for a package, a lock file named **`.composer_compiled_assets`**
is saved in the package's root.

That file should be git-ignored if the compiled assets are git-ignored.

The lock file contains an hash, referenced as the "*Composer Asset Compiler package's hash*", which
is created from the Composer Assets Compiler configuration and the content of specific files
like `package.json`, `package-lock.json`, `npm-shrinkwrap.json`, `yarn.lock`.

When a lock file is found, and the content of the hash in it matches the current package's hash, the
Composer Assets Compiler plugin will not process the package's assets.

Considering the Composer Asset Compiler package's hash does *not* depend from the actual assets
source file, it might be possible that Composer Assets Compiler skip processing a package's assets
even if the assets source have changed.

To prevent that, it is possible to use the `--ignore-lock` flag when executing the compile assets
command:

````shell
composer compile-assets --ignore-lock
````

The Composer Asset Compiler package's hash can also be calculated programmatically via the command:

```shell
composer assets-hash
```

This might be useful to generate the archive file name during pre-compilation, in the case we would
like to use the `${hash}` placeholder.

## Asset Compiler env

More often than not, the way assets are processed depends on the current environment. Composer Asset
Compiler has a native way to deal with this issue.

The "*Asset Compiler env*" is an arbitrary string that can be set in two ways:

- passing the `--env` flag to the `compile-assets` command
- setting the `COMPOSER_ASSETS_COMPILER` environment variable

When the "env" is configured, it can be used in pre-compilation `${env}` placeholder, and also to
have different configuration per-environment.

For example:

```json
{
    "name": "acme/some-theme",
    "type": "wordpress-theme",
    "extra": {
        "composer-asset-compiler": {
            "env": {
                "production": "build-prod",
                "$default": "build"
            }
        }
    }
}
```

The `env` property is the entrypoint for environment-specific configuration. It has to contain an
object where the keys are the environment names, and the values are the related configuration.

The `$default` key is a special key used when no Composer Asset Compiler env is available, that is
when `COMPOSER_ASSETS_COMPILER` environment variable is not set and the `--env` flag is not passed
to the `compile-assets` command.

When used as "root item", like in the snippet above, the `env` key forces us to have the entire set
of configuration per environment. When we have more than just the script (e.g. pre-compilation
config), we could end up in very verbose configuration and duplications.

In such cases it is possible to use the `env` key only for the config properties that needs to be
env-specific, in the example above the `script` property:

```json
{
    "name": "acme/some-theme",
    "extra": {
        "composer-asset-compiler": {
            "script": {
                "env": {
                    "production": "build-prod",
                    "$default": "build"
                }
            },
            "pre-compiled": {
                "target": "./assets/",
                "adapter": "gh-action-artifact",
                "source": "assets-${ref}",
                "config": {
                    "repository": "acme/some-theme"
                }
            }
        }
    }
}
```

## Advanced "script" configuration

`script` is the main configuration for the plugin, and it benefits from a "special treatment": it
can make use of environment variables.

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

With the configuration above, if we set the `GULP_ASSETS_TASK` environment variable to `"build"`,
what Composer Assets Compiler will execute for the package will
be `npm install && npm run gulp build`.

When using environment variables for script in that way, we probably rely on the fact those
environment variables are set, and in case they are not, some error might occur.

To define default for non-defined environment variables, we can use the `default-env` config
property:

```json
{
    "name": "acme/some-plugin",
    "extra": {
        "composer-asset-compiler": {
            "script": "gulp -- ${GULP_ASSETS_TASK}",
            "default-env": {
                "GULP_ASSETS_TASK": "build"
            }
        }
    }
}
```

Even if might be confusing, please mind that `default-env` config property is very different from
the `env` config property: `default-env` is used to define fallback for *environment variables*
which might be used in the `script` config, while `env` property is a way to differentiate
configurations based on "*Composer Asset Compiler env*", which is an arbitrary value defined either
via `--env` command flag, or via the `COMPOSER_ASSETS_COMPILER` environment variable.

## Configuration file

In some cases, especially when using adding env-specific pre-compilation settings and default
environment, the configuration object might become verbose and "pollute" the `composer.json`.

In such cases it is possible to place the same configuration that goes
in `extra.composer-asset-compiler` in a **separate file named `assets-compiler.json`**, in package's
root folder. Adding that file there's no need to add anything in `composer.json`.

Having _both_ `assets-compiler.json` and `extra.composer-asset-compiler` in `composer.json`, the
latter will be ignored and only the content of `assets-compiler.json` will be took into account.

## Package managers

In theory, the requirements in a `package.json` can be installed via either npm or Yarn without any
difference. However, the experience tell us that is not always the truth, more so when there's a
package manager-specific lock file (e.g. `yarn.lock` or `npm-shrinkwrap.json`).

Composer Asset Compiler supports both npm and Yarn. When a package has a lock file, the plugin will
execute the package manager that generated that lock file.

Even if there's no lock file, it is possible to instruct Composer Asset Compiler to use one specific
package manager via the `package-manager` configuration. An example `assets-compiler.json`:

```json
{
    "script": "build",
    "package-manager": "npm"
}
```

When no package manager is configured, and there's no lock file, Composer Asset Compiler will use
for that package the first available in the system between Yarn and npm (in that order).

In the case a package manager is configured (or a package manager-specific lock file is present),
but the chosen package manager is not available in the system, Composer Asset Compiler will *not*
fail, but will attempt to process assets using the available package manager.

For example, processing a package having the configuration in the snippet above, if npm is not
available on the system, Composer Asset Compiler will attempt to use Yarn.

## Configuration cheat sheet

| Name                | Description                                                  | Root-only | Default   |
| ------------------- | ------------------------------------------------------------ | --------- | --------- |
| `auto-discover`     | When false the plugin will only process root package and ignore any dependency. | Yes       | true      |
| `auto-run`          | When true the plugin will execute immediately after any Composer update or install. | Yes       | false     |
| `defaults`          | Provides default configuration for packages defined at root level. | Yes       | --        |
| `default-env`       | Provides default for environment variables.                  | No        | --        |
| `dependencies`      | Either "install", "update", or "none". Determine if/how the package manager install dependencies. | No        | "install" |
| `isolated-cache`    | When true, package manager will use a separate cache for each package. | Yes       | false     |
| `max-processes`     | Number of max parallel access processing.                    | Yes       | 4         |
| `package-manager`   | Which package manager to use.                                | No        | --        |
| `packages`          | Can be used at root level to override dependency configuration. | Yes       | --        |
| `pre-compiled`      | Pre-compilation settings.                                    | No        | --        |
| `processes-poll`    | Interval in microseconds for parallel processing status refresh. | Yes       | 100000    |
| `script`            | The script used to process assets.                           | No        | --        |
| `stop-on-failure`   | When false, assets processing will continue for other packages when a failure happen for a package. The exit code will still be different than zero in case of any failure. | Yes       | true      |
| `wipe-node-modules` | Whether to delete packages' `node_modules` folder after processing. Only apply to folders created because of the plugin: pre-existent folders will be always kept. | Yes       | false     |

## CLI parameters

### Command: `compile-assets`

| Param                        | Description                                                  |
| ---------------------------- | ------------------------------------------------------------ |
| `--ignore-lock[=<packages>]` | Ignore lock for either all or specific packages. Multiple packages must be separated by a comma. |
| `--env=<env>`                | Set the Asset Compiler "env" used to choose among multiple configuration variants. |
| `--hash-seed=<seed>`         | Uses the provided seed when generating packages hash.<br />Must match the seed passed to `assets-hash --seed`. |
| `--no-dev`                   | Simulate Assets Compiler auto-run after a Composer install/update with a `--no-dev` flag.<br />That causes the plugin to check for `$default-no-dev` as default "env" before fallback to `$default`. |

### Command: `assets-hash`

| Param           | Description                                                  |
| --------------- | ------------------------------------------------------------ |
| `--seed=<seed>` | Causes generated packages' hash to change based on the seed.<br />Must match the seed passed to `compile-assets --hash-seed`. |
| `--env`         | Set the Asset Compiler "env" which affects assets' hash.     |
| `--no-dev`      | Simulate Assets Compiler auto-run after a Composer install/update with a `--no-dev` flag.<br />That affects assets' hash if package `script` configuration makes use of `$default-no-dev` as default "env". |

## Advanced topics

### Packages definitions in root

The "usual" plugin's workflow expect each dependency to define what is needed to process its assets.
However it is possible to add/remove/edit configuration for a dependency in the root package. An
example `assets-compiler.json`:

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

In the example above, the `packages` property is used to change the configuration of some
dependencies, and it show all the possible configuration types:

- when using `true` it means: "*if the packages has any configuration use it, otherwise process
  anyway using defaults*"
- when using `false` it means: "*do not process assets for this package*"
- when using `force-defaults` it means: "*process the assets for this package using the defaults*"
- when using a valid array of configuration it means: "*process the assets for this package using
  this configuration*"

When using `true` or `force-defaults`, defaults has to be also configured via the `defaults`
property.

Please note that with the configuration in the example above, Composer Asset Compiler will not only
process the packages listen in `packages` but will search in installed dependencies to find other
packages to process. That can be prevented setting the `auto-discover` config to `false`: doing that
only the listed packages will be processed (besides the root package itself, if it has valid
configuration).

### Verbosity

`compile-assets` is a command added to Composer, which means all the Composer
command ["global options"](https://getcomposer.org/doc/03-cli.md#global-options) are valid. Among
those, the ones that control the "verbosity" have a multiple effects on Composer Asset Compiler:

- Composer's functionalities used by the plugin (such as the "HTTP Downloader") will adjust their
  verbosity
- Composer Asset Compiler itself will emit more or less output based on those options
- Composer verbosity is "mapped" to package managers' verbosity flags, according to the following
  table:

| Composer flag      | [npm](https://docs.npmjs.com/cli/v7/commands/npm) | [Yarn](https://classic.yarnpkg.com/en/docs/cli/) |
| ------------------ | ------------------------------------------------- | ------------------------------------------------ |
| `-v` / `--verbose` | `-d`                                              |                                                  |
| `-vv`              | `-dd`                                             |                                                  |
| `-vvv`             | `-ddd`                                            | `--verbose`                                      |
| `-q` / `--quite`   | `--silent`                                        | `--silent`                                       |

It is worth noting here that when using Composer with the `--non-interactive` flag, Yarn will
receive the flag `--non-interactive`, but npm doesn't have a correspondent flag.

### Isolated cache

Composer Assets Compiler normally doesn't change the behavior of package manager, it only move
current working directory to the package path and execute whichever processing script was
configured. We have experienced that in bigger projects with multiple packages to process sometimes
package manager's cache gets "bungled" and processing fails. If that happens a possible solution is
to set `isolated-cache` to true. That ensures each package is processed making use of a different
cache folder (under the system's temp folder), and usually that solves the issue.

Activating `isolated-cache` has a performance cost, but could be useful is some situations. In any
case, the preferred way to solve *both* "bungled cache" and performance issues is using pre-compiled
assets.

### Parallel assets processing

The plugin processes multiple packages in parallel to speed up the process. Note that only the
assets' _script_ are executed in parallel, dependencies installation is executed in series, because
package manager fails in parallel installation due to multiple processes simultaneously attempting
write the same file in cache. The plugin implementation for parallel execution is pretty basic and
works by in spinning up different processes to be started at same time, and then check their status
(completed, failed, running) at regular intervals. The number of processes that are executed at same
time can be controlled via the `max-processes`
configuration, and the interval via the `processes-poll` configuration. Using a `max-processes`
value that matches the number of system CPUs might increase performance, assuming there's also
enough memory.
