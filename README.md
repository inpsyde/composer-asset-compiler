# Composer Asset Compiler

_Composer plugin that install dependencies and compile assets based on configuration._

![PHP Quality Assurance](https://github.com/inpsyde/composer-asset-compiler/workflows/PHP%20Quality%20Assurance/badge.svg)

-----


## Preface

Working with WordPress we, more often than not, deal with _packages_ (as in *Composer packages*) that contain both PHP code and "frontend assets" (as in javascript, styles, images...).

Using Composer we can put the packages together, but then we need either that:

- frontend assets are already built when the Composer package is pulled (so built assets are kept under version control)
- a way to build assets after the packages are pulled via Composer.

This package is a Composer plugin that has the scope to pursuit the latter approach.

It means that **in version control we *don't* have to keep compiled frontend assets**, but they are built after the package that contains them is pulled via Composer, no matter how "deep" in the dependency tree the packages are.




## Build is done via javascript tools

Even if the technology we use to collect the packages is Composer, so it is PHP, the only sane way to "build" frontend assets is to use frontend technology.

What we do is to use Composer to run shell commands that will execute the frontend tools used to build assets.

In other words, we assume that the running system has a frontend dependency manager (_npm_, _Yarn_) and from Composer we execute commands like `npm install` (or `yarn`) to resolve the dependencies.

Resolving dependencies will likely not be enough. In fact, normally we need to also execute a task runner like _gulp.js_ or _Grunt_, or some builder like _webpack_ to *build* the installed dependencies.

To solve the issue we decided to leverage `package.json` scripts.

Just like Composer has support for ["scripts"](https://getcomposer.org/doc/articles/scripts.md), both _npm_ and *Yarn* support "scripts" that are defined in `package.json`. (Here's the documentation for [npm](https://docs.npmjs.com/misc/scripts) and for [Yarn](https://classic.yarnpkg.com/en/docs/package-json#toc-scripts)).

So the entire workflow can be summarized like this:

1. Composer is used to pull dependencies from their repositories
2. After Composer finishes its work, this plugin loops all the installed packages to find those that needs to be "built"
3. For each package found, it moves the current directory to the root of the package and:
    1. installs the dependencies via either _npm_ or *Yarn* 
    2. uses either _npm_ or *Yarn* to execute one or more "scripts" defined in `package.json`.



## Which packages?

At point *2.* of the list at the end of previous section it is said: "*to find those that needs to be built*".

But how can this Composer plugin programmatically determine which packages have assets to be compiled and which do not?

It uses two strategies, that can be combined:

- via **configuration in Composer root package** `composer.json` it is possible to list the packages that need processing (no matter how deep in the dependencies tree). For the rest of this README this kind of configuration is named "**root-level configuration**".
- via **configuration in each package** `composer.json` it is possible to "signal" to the outer root package that the assets the current package ships needs building. For the rest of this README this kind of configuration is named "**package-level configuration**".

Both *root-level* and *package-level* configuration are not only used to determine which packages as to be compiled, but also what is needed to compile them.

It worth noting that the Composer root package is still a package, that might need processing of frontend assets it ships. In fact, Composer root package can contain both root-level and package-level configuration.



## Configuration basics

Both root-level and package-level configuration are done in the same way: by adding a JSON configuration object to the package `composer.json`, under the [`"extra"` property](https://getcomposer.org/doc/04-schema.md#extra) using as root key a property named **`composer-asset-compiler`**. For example:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "key": "value"
        }
    }
}
```

Alternatively it is possible to create a file named **`assets-compiler.json`** in the package root and Composer Asset Compiler will get configuration from there.



## Package-level configuration

The possible configuration at package level are based on following properties:

| Configuration property | Description                                                  |
| ---------------------- | ------------------------------------------------------------ |
| `default-env`          | A JSON object used to provide fallback to environment variables |
| `dependencies`         | Determine how to install / update package dependencies       |
| `script`               | Determine which script(s) to execute to build the package assets |
| `env`                  | Provides advanced environment-based configuration for `dependencies` and `script` configuration. |
| `pre-compiled`         | An object with information on where to store "pre-compiled" assets to speed up deployments. |

In the next section there will be detailed documentation of each of them.



### Environment variables and `default-env`

Very often it is needed to execute different commands or command parameters based on the _environment_.

For example, if we use webpack we could make use of its [environment-options](https://webpack.js.org/api/cli/#environment-options) and in *production* run something like:

```shell
webpack --env.production
```

and in staging something like:

```shell
webpack --env.staging
```

A good way to deal with this issue is to use **environment variables**.

So we could, for example, set the variable `WEBPACK_ENV` to `"production"` in the production system and to `"staging"` in the staging system.

The **`default-env`** configuration key is used by this Composer plugin to provide a *fallback* in case an environment variable is not found.

Let's assume, for example, that we need the `WEBPACK_ENV` environment to be defined (we'll see shorty how variables can be used for the plugin) we could do:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "default-env": {
                "WEBPACK_ENV": "development"
            }
        }
    }
}
```

With the configuration above, when the plugin will compile the package, in the case the `WEBPACK_ENV` variable is not defined in the current environment its value will default to  `"development"`.



### Dependencies install and update

As said in the introductory sections of this README, the "building" of assets is done in two steps: first the package dependencies are installed and only after that one or more *scripts* are executed.

The **`dependencies`** configuration property defines how to proceed for the first step. 

The value for this property can be:

- `"install"`, which means *"install frontend dependencies"* (this is the default)
- `"update"`, which means *"update frontend dependencies"*
- `"none"`, which means *"do not install nor update frontend dependencies"*

This plugins support both *npm* and *Yarn*. The table below summarizes the actual command executed when using the two different configuration values, depending on the dependency manager in use:

|                             | npm*                   | Yarn*          |
| --------------------------- | ---------------------- | -------------- |
| `"dependencies": "install"` | `npm install`          | `yarn`         |
| `"dependencies": "update"`  | `npm update --no-save` | `yarn upgrade` |

**the "map" between `dependencies` configuration value and actual command shown in the table above is the default when using the two most popular package managers. However, via configuration at root level it is possible to map the two configuration values to a different command.*



#### Contextual command parameters

It has been said how the command executed is launched from Composer. The PHP package manager uses the [Symfony Console](https://symfony.com/doc/current/components/console.html) component under the hood.

As part of that component there's the  `IO` object that carries some information that are set from the user input.

Among all possible parameters that is possible to pass to Composer command, there are two groups of parameters that also affect how the frontend building commands are executed. Those are:

- "interactivity" parameter `--no-interaction` / `-n`
- "verbosity" parameters: `--verbose` / `-v` and `--quiet` / `-q`

When such parameters are used to run the Composer command, they will also affect how the *npm* or *Yarn* commands are run.

The following table summarizes how these Composer parameters are "mapped" to parameters of *npm* and *Yarn*

| Composer                  | npm        | Yarn                |
| ------------------------- | ---------- | ------------------- |
| `--no-interaction` / `-n` |            | `--non-interactive` |
| `--verbose` / `-v`        | `-d`       |                     |
| `-vv`                     | `-dd`      |                     |
| `-vvv`                    | `-ddd`     | `--verbose`         |
| `--quiet` / `-q`          | `--silent` | `--silent`          |



### Assets building scripts

The second step in the "building" of assets, after dependencies are being pulled, is to execute one ore more *scripts* that are defined in `package.json`.

The scripts to be executed are configured in the **`script`** configuration value.

For example, assuming a configuration in `composer.json` that looks like this:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "dependencies": "install",
            "script": "build"
        }
    }
}
```

After the package has been installed via Composer, the plugin will install dependencies with either *npm* or *Yarn* and after that, it will execute the `"build"` script, that should be defined in `package.json`.

The actual command being run will depend on the package manager used:

| Asset compiled config | npm             | Yarn         |
| --------------------- | --------------- | ------------ |
| `{"script": "build"}` | `npm run build` | `yarn build` |

It is possible to instruct the asset compiler to execute _multiple_ scripts, by using an array. In that case, scripts will be executed in sequence.

| Asset compiled config          | npm                             | Yarn                      |
| ------------------------------ | ------------------------------- | ------------------------- |
| `{"script": ["test", build"]}` | `npm run test && npm run build` | `yarn test && yarn build` |



#### Leveraging environment variables for scripts

Many tools that we can use to build assets can use a different behavior per environment. As a simple example, we can take [Symfony Encore](https://symfony.com/doc/current/frontend.html) that can be executed to use defaults tailored for development environments like this:

```shell
yarn encore dev
# or `npm run encore dev`
```

or for production optimization like this:

```shell
yarn encore prod
# or `npm run encore prod`
```

The script configuration has support for environment variables placeholders, that are replaced with the value of actual variables before being ran.

Which means that we can have a configuration like this:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "dependencies": "install",
            "script": "encore ${ENCORE_ENV}",
            "default-env": {
                "ENCORE_ENV": "dev"
            }
        }
    }
}
```

The placeholder `${ENCORE_ENV}` will be replaced with the value of the `ENCORE_ENV` environment variable before the script is executed, meaning that we can set it to `"prod"` in the production environment, ending up in a command that will be:

```shell
yarn encore prod
# or `npm run encore prod`
```

and we can set to `"dev"` in development environments.

When the variable is not defined at all, thanks to the `"default-env"` configuration, the script will default to `"dev"` (as documented above in this README) which means that we always run the command in a meaningful way even if the system has no environment variable defined.



#### Passing parameters to `package.json` scripts

Let's assume a package has a `package.json` that contains:

```json
{
    "scripts": {
        "tasks": "gulp"
    }
}
```

Using *npm* we could do:

```shell
npm run tasks -- build
```

and that would actually execute:

```shell
gulp build
```

because all the parameters after ` -- ` are passed by *npm* to the script defined in `package.json` .

However, if we would use *Yarn* instead of *npm*, to obtain the same result we would type:

```shell
yarn tasks build
```

because *Yarn* passes everything after the script name to the script itself, without the need for ` -- ` .

Composer asset compiler configuration is agnostic in the regard of the usage of *Yarn* or *npm*. This is why, when there's the need to pass arguments to `package.json` script, those must be written using the *npm* syntax (with ` -- `) so that the asset compiler will recognize and remove the not needed  ` -- ` when running *Yarn*.

Back to previous example, assuming the same `package.json` on top of this section, we could have a configuration as follows:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "dependencies": "install",
            "script": "tasks -- build"
        }
    }
}
```

and it will work as expected with both *Yarn* and *npm*.

This approach can be combined with the environment variables replacement for increased power.

Given the same `package.json`, but an assets compiler configuration like this:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "dependencies": "install",
            "script": "tasks -- build:${GULP_ENV}",
            "default-env": {
                "GULP_ENV": "dev"
            }
        }
    }
}
```

when the value for `GULP_ENV` environment variable is "dev" (or is not defined, thanks to `"default-env"`) the compiler will execute either:

```shell
yarn tasks build:dev
```

or:

```shell
npm run tasks -- build:dev
```

that in both cases will end up executing:

```shell
gulp build:dev
```

By setting `GULP_ENV` to different values would be possible to run different tasks, targeting different environments.



### Advanced environment-based configuration

The possibility to pass arguments to scripts and to use placeholders for environment variables in JSON configuration provides already a lot of flexibility in defining how a package has to be "built".

But even that flexibility sometimes is not enough.

In fact, by using those functionalities, it is possible to configure a script to be executed in a very flexible way depending on the environment, but what about executing different scripts depending on the environment?

For example, it might be desirable to build assets and then run tests in staging, but only build assets in production.

These cases are achievable thanks to the **`env`** configuration.

Both `dependencies` and `script` configurations, instead of being set as own properties of the `composer-asset-compiler` object, can be placed inside an `env` object, so that it is possible to have a different configuration for each environment.

For example:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "dependencies": "install",
            "env": {
                "staging": {
                    "script": [
                        "build",
                        "tests"
                    ]
                },
                "production": {
                    "script": "build"
                }
            }
        }
    }
}
```

With such a configuration, when inside a *staging* environment, the compiler will execute both `"build"` and `"tests"`, but will execute only the `"build"` script when in *production* environment.

But how does the assets compiler determine the environment it is running on?



#### The asset compiler environment

The environment the compiler is running on is determined by the value of the **`COMPOSER_ASSETS_COMPILER`** environment variable. 

For the previous snippet to work, an environment variable named `COMPOSER_ASSETS_COMPILER` has to be set to either `"staging"` or `"production"` so that the compiler knows which environment `script` configuration will apply.

It is important to note that `COMPOSER_ASSETS_COMPILER` is the only environment variable that **can't** have a default value assigned via `"default-env"` configuration.

When it is not defined in the real environment the default value will be either **`"$default"`** or **`"$default-no-dev"`**, depending if Composer is being run with the `--no-dev` flag or not.

More details on this will be provided later in this README when documenting the root-level configuration, for now it is important to say that when using this kind of configuration it is a good idea to provide a set of configuration for the `"$default"` environment, otherwise if `COMPOSER_ASSETS_COMPILER` environment variable is not defined, the compiler will not know which configuration to use (and will do nothing).

For example, the previous snippet would be better written like this:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "dependencies": "install",
            "env": {
                "staging": {
                    "script": [
                        "build",
                        "tests"
                    ]
                },
                "$default": {
                    "script": "build"
                }
            }
        }
    }
}
```

Finally, it worth noting that advanced environment-based configuration can be combined with the other techniques previously documented, like passing arguments to scripts and the usage of placeholders for environment variables.

For example, the following example leverages all of them:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "dependencies": "install",
            "default-env": {
                "GULP_ENV": "dev"
            },
            "env": {
                "staging": {
                    "script": [
                        "tasks -- build:${GULP_ENV}",
                        "tests"
                    ]
                },
                "$default": {
                    "script": "tasks -- build:${GULP_ENV}"
                }
            }
        }
    }
}
```



### Pre-compilation

Installing and "compiling" assets for a package can be a time-consuming task.

When this operation needs to be done per each package required in a project, the deployment time might suffer a lot from that.

To speed-up deployment, Composer Asset Compiler support the *"pre-compilation" workflow*, which can be summarized like this:

- When a change happens in a package that needs asset compiling, an automated process (for example GitHub action, Travis, etc...) uses Composer Asset Compiler to compile assets for that package and stores them somewhere accessible later
- When a project requires that package, it recognizes that the assets for that package are already compiled. Instead of installing and compiling dependencies, it will download the pre-compiled assets and make use of them.

#### Assets hash

The first issue with this workflow is to ensure that the pre-compiled assets are up-to-date with the changes in the package.

One way to do that is to use release versions: when the version *x.y* of a package is created, assets could be compiled and saved as "release attachments" (see for example the documentation for GitHub).

Another way is to make use of "assets hash". This is a hash that Composer Asset Compiler can create based on:

- the Composer Asset Compiler configuration
- `package.json` content
- Content of any of the file `package-lock.json`, `npm-shrinkwrap.json`, and `yarn.lock` assuming they are found.

The asset can be calculated with a custom command shipped by Composer Asset compiler, that is: `composer assets-hash`.

#### Adapters

The second issue with this workflow is that we need a reliable place where to store compiled assets.

Composer Asset Compiler has the concept of "pre-compilation adapters": each adapter can retrieve compiled assets from different sources.

At the moment, the supported adapters are the following:

| ID                   | Description                                                  |
| -------------------- | ------------------------------------------------------------ |
| "archive"            | Downloads assets from archives at arbitrary URLs             |
| "gh-action-artifact" | Downloads assets from [GitHub Actions artifacts](https://docs.github.com/en/free-pro-team@latest/actions/guides/storing-workflow-data-as-artifacts) |
| "gh-release-zip"     | Downloads assets stored in [GitHub releases assets](https://docs.github.com/en/free-pro-team@latest/rest/reference/repos#get-a-release-asset) |

Each of the adapters has the following configuration options:

- `source`: the name or the URL of the file used as source for pre-compiled assets. can include placeholders like `${hash}`, `${ref}` or `${version}` to dynamically refer to, respectively, the hash, the Git commit reference, or the version of the package. What this config has to contain depends on the adapter in use.
- `target`: the relative path where to save the precompiled assets
- `adapter`: the ID of the chosen adapter
- `config`: an object of settings that are specific for each adapter

#### Usage example

For example, let's assume the following configuration is placed in the `assets-compiler.json` file in a package root folder:

```json
{
    "script": "gulp",
    "pre-compiled": {
        "env": {
            "root": {
                "adapter": false
            },
            "$default": {
                "source": "assets-${ref}",
                "target": "./assets/",
                "adapter": "gh-action-artifact",
                "config": {
                    "user": "john-doe",
                    "token": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
                    "repository": "john-doe/some-package"
                }
            }
        }
    }
}
```

When the Composer Asset Compiler environment will be `root`, there's no pre-compilation adapter, so Composer Asset Compiler will install and compile assets as usual (in this case executing `gulp`).

In all the other cases, before compiling assets, Composer Asset Compiler will attempt using pre-compiled assets stored in a GitHub action artifact whose name is `assets-${ref}.zip` (where `${ref}` is replaced by the Git SHA reference of the installed commit) in the repository `john-doe/some-package`, and using GitHub API credentials stored in `config.user` and `config.token`.

If the artifact  is found, the adapter will download it and unzip in the `./assets/` folder and will do nothing else for the package.

#### Config by env variables

Both the artifact that deal with GitHub, also support configuration via environment variables, to avoid placing secrets in JSON files.

For example:

- the token can be stored in the env variable `GITHUB_API_TOKEN`.
- the user can be stored in the env variable `GITHUB_USER_NAME`.
- the repository can be  stored in the env variable `GITHUB_API_REPOSITORY`.



## Root-level configuration

The root Composer package is, at any effect, a Composer package, meaning that all the package-level configuration described above will apply to root package as well.

**An additional note has to be made in the regard of `"default-env"` though**.

In fact, `"default-env"` configuration in the root package will apply to *all* the packages, *merging* its value with any package-specific value that could be defined at package level.

In case the same value is defined *both* at root and package level, the one at package level "wins".



### Configuration properties cheat-sheet

Besides the four package-level configuration properties there are several other configuration properties that can be set at root level. 

The following table summarize them:

| Configuration property | By env | Default  | Description                                                  |
| ---------------------- | ------ | -------- | ------------------------------------------------------------ |
| `auto-run`             | *yes*  | `false`  | Whether to start compilation of assets automatically after `composer install` / `composer update` are completed. |
| `packages`             | *no*   | `{}`     | The packages to process.                                     |
| `auto-discover`        | *no*   | `true`   | Determine whether to search installed Composer dependencies for packages to find packages to process. |
| `defaults`             | *yes*  | `{}`     | Default values for `dependencies` and `script` configuration for packages that don't provide them. |
| `commands`             | *yes*  | `null`   | Allow to configure the "base commands", e. g. to force the usage of a specific package manager and/or to configure its parameters |
| `stop-on-failure`      | *yes*  | `true`   | Whether to stop processing of packages when the first failure happen. |
| `wipe-node-modules`    | *yes*  | `true`   | Whether to delete the `node_modules` folder of packages after the assets are installed and built. |
| `max-processes`        | *yes*  | `4`      | How many parallel processes to use to execute packages scripts. |
| `processes-poll`       | *yes*  | `100000` | Microseconds (1/1000000) to wait to check the status of processes when executing processes in parallel. |

It must be noted that **all the settings are optional**.

In fact, if the root package has no compiler settings at all, but some of the installed Composer packages have package-level configuration, after Composer installs (or updates) dependencies the asset compiler will still kick-in to recursively search for packages that have a usable configuration and install and build package assets based on that configuration.

Many parts of this default behavior can be customized used the above settings, and the next section will document each of them.



### Configuration by environment

When package-level configuration was documented above, it has been said how the `env` configuration can be used to obtain different configuration values per environment.

In root-level configuration, the `env` configuration property can be used in two ways.

The first comes from the fact that root package is still a package, so anything has been said about package-level configuration applies to root-level configuration as well.

The second way to use `env` is specific for each setting.

For example, we can have a root-level configuration that looks like this:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "env": {
                "$default": {
                    "script": [
                        "build",
                        "test"
                    ]
                },
                "production": {
                    "script": "build"
                }
            },
            "auto-run": {
                "env": {
                    "$default": true,
                    "production": false
                }
            }
        }
    }
}
```

The first `env`, located directly as a property of `composer-asset-compiler` shows the usage that has been described for package-level configuration.

The second usage is shown where `env` is a property of `auto-run`, one of the possible root-level settings, and allows to define per-environment configuration for that specific setting.

This usage is allowed for each of the configuration options listed in the configuration cheat-sheet table in the previous section that has "*yes*" in the "*By env*" column.



### Auto-run VS on-demand asset compiling

By default, running either `composer install` or `composer update` also means the compiling assets workflow will automatically start immediately after the installation finishes, assuming `inpsyde/composer-asset-compiler` is among the dependencies.

This might not be desirable. In that case, the `auto-run` configuration can be used to change this behavior, with a configuration that looks like this:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "auto-run": false
        }
    }
}
```

With this configuration, the only way to start the assets compilation workflow is to use the **`compile-assets`** command that will be documented later in this README.



### Which packages to process: `packages ` and `auto-discover` settings

If some of the Composer dependencies installed ship in their `composer.json` a configuration for the Composer asset compiler, then the compiler knows what to process.

However, there is the possibility that not all the dependencies which _should_ be processed ship such configuration and / or the configuration shipped by the packages is not correct.

In such cases, the **`packages`** and **`auto-discover`** settings serve the purpose of tweaking the compiler behavior.

`auto-discover` can be set to `false` to prevent the compiler to recursively look into installed dependencies to find packages that needs asset compilation.

This is useful when either there's the need to make sure the compilation happen according to the setting in root package, or when it is already known that no installed package ships any compiler configuration so it is possible to avoid wasting time searching.

`packages` is where to define configuration for packages that have no package-level configuration or have package-level configuration that should be overridden.

It is a JSON object where the keys are the names (as in Composer package *vendor/name*) of the packages that we want to target and the values are objects that resemble package-level configuration.

For example:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "auto-discover": false,
            "default-env": {
                "ENCORE_ENV": "dev"
            },
            "packages": {
                "some-vendor/some-package": {
                    "dependencies": "install",
                    "script": "build"
                },
                "another-vendor/another-package": {
                    "dependencies": "install",
                    "script": "encore ${ENCORE_ENV}"
                }
            }
        }
    }
}
```

Every value in the `packages` object is totally comparable with the package-level configuration that can be placed in dependencies `composer.json`.

It should be noted that the `"default-env"` defined at root level will apply to _all_ packages, whether they are defined in `packages` or "discovered" by reading installed dependencies (assuming `"auto-discover"` is `true`).

However, `"packages"` object keys can make use of `*` placeholder to apply to multiple packages, without the need to list them one by one.

For example:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "auto-discover": false,
            "default-env": {
                "ENCORE_ENV": "dev"
            },
            "packages": {
                "inpsyde/client-*": {
                    "dependencies": "install",
                    "script": "encore ${ENCORE_ENV}"
                }
            }
        }
    }
}
```



#### Exclude packages

`"packages"` can also be used to _exclude_ some packages.

When `"auto-discover"` is `true`, the root package has no knowledge over what packages will be compiled, but there might be the need to exclude a particular package (or a particular set of packages).

This can be done by setting the target package(s) to `false`.

For example:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "packages": {
                "some-vendor/foo-*": false
            }
        }
    }
}
```



### Default packages configuration

Even if the asset compiler provides a very flexible way to granularly configure every package (or group of similar packages) in a different way, the reality is that more often than not the `dependencies` and `script` configuration are the same. 

When that's the case, having to set same properties again and again might be quite verbose.

Let's take this example:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "packages": {
                "some-vendor/foo-*": {
                    "dependencies": "install",
                    "script": "build"
                },
                "other-vendor/bar-*": {
                    "dependencies": "install",
                    "script": "build"
                },
                "yet-another/some-name": {
                    "dependencies": "install",
                    "script": "build"
                },
                "last/set-*": {
                    "dependencies": "install",
                    "script": "build"
                }
            }
        }
    }
}
```

This configuration can be improved thanks to the **`"default"`** setting.

For example, the snippet above can be re-written in a less verbose way like this:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "defaults": {
                "dependencies": "install",
                "script": "build"
            },
            "packages": {
                "some-vendor/foo-*": true,
                "other-vendor/bar-*": true,
                "yet-another/some-name": true,
                "last/set-*": true
            }
        }
    }
}
```

By defining a set of defaults and then using `true` for the packages, we are instructing the compiler to use those defaults, without having to copy same settings again and again.

Actually, `true` means that defaults will be used *unless* different settings are provided at package level.

To *force* the settings to be used to be the defaults, overriding any package-level configuration, it is possible to use `"force-default"` instead of `true`.

For example:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "defaults": {
                "dependencies": "install",
                "script": "build"
            },
            "packages": {
                "some-vendor/foo-*": "force-default",
                "other-vendor/bar-*": "force-default"
            }
        }
    }
}
```



### Commands customization

The section on package-level configuration describes how the different values for `dependencies` and `script` are mapped to *npm* or *Yarn* commands.

But it has not been said, among other things, how the asset compiler decides which package manager to use.

#### Package manager discovery

By default, when the asset compiler starts its work, it will check for the availability of the package manager and will use the first found.

It first executes the "version" command for *Yarn* (`yarn --version`) and if that is successful it is assumed Yarn is available and that is used. Subsequently, the same check is done for *npm* and that will be used in case of success.

If neither of the two "version" commands gives a successful result, the compiler will bail with an error.

When that happens, but one knows that one of the two is available or when for any reason one of the two package managers wants to be forced, that is possible via the **`commands`** configuration property.

#### Force one package manager

`commands` is a quite powerful configuration, in its simplest form it allows to force the usage of one of the two supported package managers, like this:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "commands": "npm"
        }
    }
}
```

(of course, `"yarn"` can be used to force the usage of *Yarn*).

`commands` is one of the settings that can be set per-environment, so that it is possible to force a package manager in some systems, without affecting the others.

For example:


```json
{
    "extra": {
        "composer-asset-compiler": {
            "commands": {
                "env": {
                    "docker": "npm"
                }
            }
        }
    }
}
```

Using such a configuration we are instructing the compiler to use `npm` when it is running in the `"docker" ` environment, so that the check for Yarn can be skipped if it is known it is surely not available.

In other environments, the default will apply: the compiler will check for the availability of *Yarn* and *npm*, in this order.



#### Advanced commands configuration

The `commands` configuration property is internally mapped from the strings `"npm"` and `"yarn"` to an object. 

For example, when the setting is  `{ "commands": "npm" }`, the *mapped object* looks like this:

```json
{
    "commands": {
        "dependencies": {
            "install": "npm install",
            "update": "npm update --no-save"
        },
        "script": "npm run %s"
    }
}
```

and when the setting is  `{ "commands": "yarn" }`, it looks like this:

```json
{
    "commands": {
        "dependencies": {
            "install": "yarn",
            "update": "yarn upgrade"
        },
        "script": "yarn %s"
    }
}
```

After the `"commands"` objects is being created according to the package manager that is running, when the compiler encounters a package-level configuration that looks like this:

```json
{
    "dependencies": "install",
    "script": "build"
}
```

the value `{ "dependencies": "install" }` is  "resolved" to the value of `commands.dependencies.install`. In the same way, `{ "script": "build" }` is "resolved" replacing the `%s` in `commands.dependencies.script` with `"build"`.

Knowing this workflow is important to understand how it is possible to customize the `commands` object in a very powerful way.

In fact, it is possible to use a complete `commands` object instead of letting the compiler use the default object based on the package manager in use.

For example, in root `composer.json`, it is possible to write a configuration like this:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "commands": {
                "dependencies": {
                    "install": "yarn install --silent --frozen-lockfile",
                    "update": "yarn install --silent --force"
                },
                "script": "npm run %s --loglevel info"
            }
        }
    }
}
```

Using such a configuration, we are instructing the compiler how to resolve the commands, and we are telling it to always use `yarn install`, for both installing and update dependencies (with slightly different flags), and we tell it to use `npm run %s --loglevel info` to run any of the scripts configured with package-level configuration, where the `%s` is replaced with anything is provided at package-level.



### Stop compilation on failure

By default, when *anything* during building process goes wrong, the processing of packages stops and the compiler exit with an non-zero exit code.

When an error happen for a package, it is possible to instruct the compiler to continue processing other packages. This can be done via the **`stop-on-failure`** property.

```json
{
    "extra": {
        "composer-asset-compiler": {
            "stop-on-failure": false
        }
    }
}
```

It must be noted that when `"stop-on-failure"` is `false`, the compiler will continue processing packages, but at the end of the process it will anyway exist with a non-zero exit code if something went wrong.



### Cleanup of `node_modules`

The `node_modules` folder is where javascript package managers place the files for the dependencies. 

It is "infamous" for being very heavy in size. When using asset compiler, all processed packages will have own `node_modules` folder accounting in total for several, sometimes dozen, hopefully not hundreds, of gigabytes.

In some situations, the same system can contain several versions of the complete built system (e. g. when using deployments with support to immediate rollback to previous version) so the space required to host all these `node_modules` folder can be very expensive for something that is useless after the assets are built.

For this reason, by default, after a package is processed, the compiler deletes the `node_modules` folder.

There are two exceptions:

- if the `node_modules` folder was there _before_ the asset compiler started its work for the package, it was probably *manually* created on purpose by a developer, so the compiler keeps it
- if the package is symlinked, very likely the current system is a development environment that is using a [Composer `path` repository](https://getcomposer.org/doc/05-repositories.md#path), or [Composer Studio](https://github.com/franzliedke/studio), or other similar strategies, which means the `node_modules` is probably being used during the development of such packages. This is why the compiler ignore the  `node_modules` folder found inside packages that are symlinked.

However, this is just the default behavior.

The configuration **`wipe-node-modules`**  can be used to change this behavior.

Specifically, the deletion of `node_modules` can be always avoided by setting its value to `false`, or can be forced by setting it to `"force" ` (`node_modules` for symlinked packages will still ignored, even with `false`).

The other allowed value is `true` and that equals the default.

It worth noting that `wipe-node-modules` is one of those settings that can be set by environment, and considering that deleting `node_modules` might be both time-consuming and "invasive", having the possibility to set it per environment is very important.



### Parallel processing settings

Processing assets might be a quite time consuming task, especially when the number of packages to process grows to more than a few.

One way in which the compiler attempts to reduce the processing time is to run the building of assets in parallel.

Please note that the _installation_ (or *update*) of dependencies is done synchronously, because package managers do not support doing it in parallel. Long story short, package managers use a *shared* cache, and to attempt the installation of multiple packages in parallel ends up in having more processes to attempt writing to same cache at same time, resulting in installation failure.

But the _building_ of assets (that is anything that is defined in the `script` compiler configuration) can normally be done in parallel, and that is what the compiler does.

There are two settings: **`max-processes`** and **`processes-poll`** that control some aspect of this parallel processing.

`max-processes` is the maximum number of processes that will run in parallel. In theory, the higher this number is, the faster the processing should be, but in reality, this depends on the number of CPUs (and their cores) and the memory available.

Because the compiler is written in PHP, after processes are started it is necessary to check every "bit" of time that they are still running or not (so either successful or erroneous). This "bit" can be customized via the `processes-poll` setting. The value is in *microseconds*, that is 1/1000000th of a second, and it defaults to `100000 ` which means packages are checked with intervals of 0.1 seconds.

Setting this configuration to a smaller value means packages are checked for their status more frequently. This makes it possible to recognize earlier when a process is completed, but the act of _checking_ packages takes time too, so a value that's too small could actually have an adverse effect and increase the total time necessary.

On the other hand, using too large a polling value might lead to finished processes not being recognized as soon as they finish, making the compiler realize the job is done later than it _could_ have.

The default value has been *empirically* found to be optimal, but YMMV (your mileage may vary) and the setting is there.

It is worth noting that, with these setting being closely related to the underlying system hardware, the possibility of setting them per environment is crucial for the best optimization.



## Lock file

After a package has been successfully processed by the plugin, a file named **`.composer_compiled_assets`** is created in package root folder.

This file contains an hash calculated from:

- the content of package `package.json`
- the package compiler configuration
- the current environment

Before the plugin starts to process any package, it checks if this file is in the package root folder.

If so, the hash is re-calculated and, in the case it matches what is saved in the file, the processing of the package is skipped.

This is done to avoid to process dependencies when this is not needed.

This is particularly useful when the compiler runs automatically on Composer install/update. In that case every update, even to a single package with no asset to compile would trigger a new compilation of all the packages notably increasing the required time for no reason.

### Version control

With the exception of very special cases it is suggested to do **not keep the lock file under version control**.

The reason is that if built assets for a package are not kept under version control (and that's likely the case, otherwise the compiler makes no sense for that package) and a lock file is found, the building process for that package assets would not take place, and the package at the end of installation process will have no built assets.

### Ignoring lock

When a lock is present, it is possible to ignore it when running the command "on demand" via the `compile-asset` command (see below).

That is possible via the `--ignore-lock` command flag.

The flag can take two forms:

```shell
$ composer compile-assets --ignore-lock
```

```shell
$ composer compile-assets --ignore-lock=some-vendor/package,some-other-vendor/*
```

In the first form it ignores lock for *all* packages, in the second form it ignores lock for specific packages, eventually making use of `*` as wildcard character.



## `compile-asset` command

It has been said how, by default, the compiler starts immediately after each Composer install or update.

It has also been said how the `auto-run` configuration can be used to prevent this, so that the only way to start the compiler would be to start it "manually".

Being a Composer plugin, the asset compiler can add commands to Composer, and it actually adds a command named **`compile-assets`**, which can be run like this:

```shell
$ composer compile-assets
```

This command can be used regardless of the value of the `auto-run` configuration, but when `auto-run` is `false` using the command is *the only* way to start the compiler.



### Command environment

Both package-level and root-level configuration support environment-based settings via `env` configuration property.

When using the `compile-assets` command it is possible to force the value of compiler environment via the **`--env`** flag.

For example:

```shell
$ composer compile-assets --env=production
```

If the flag is not used, the compiler environment will (as usual) depend on the value of the `COMPOSER_ASSETS_COMPILER` environment variable and fall back to `"$default"` if that variable is not defined.

When the compiler runs automatically after composer install/update, and the install/update command is executed with the `--no-dev` flag, the compiler will use `"$default-no-dev"` as fallback.

 **`compile-assets`** command support the `--no-dev` flag as well, so that same behavior can be replicated even when the compiler is started "manually".

It worth nothing that both flags can be used together, for example:

```shell
$ composer compile-assets --env=staging --no-dev
```

Running the command in such a way means the compiler will run in `"staging"` environment, and if packages to process have no setting specific for `"staging"`, then the compiler will use package settings for `"$default-no-dev"`, if available. Otherwise, settings for `"$default"` environment will be tried as last fallback.

This behavior exactly resembles what happens when the compiler "auto starts" and Composer commands use the `--no-dev` flag and the `COMPOSER_ASSETS_COMPILER` env variable is set to ``"staging"`.



## Troubleshooting

In case you run into a process timeout (default is 300s) like this:

```
The process "yarn" exceeded the timeout of 300 seconds.
    
failed!
```

you have to increase (or completely disable) the process timeout in your `composer.json`:


```
"config": {
    "process-timeout": 0
}
```



## Requirements

* PHP 7.2 or higher

* Composer 1.8+

* either Yarn or NPM and compatible Node.js version

    

## Installation

Via Composer, package name is `inpsyde/composer-assets-compiler`.



## License

Copyright (c) 2020 Inpsyde GmbH

This code is licensed under the [MIT License](LICENSE).

The team at [Inpsyde](https://inpsyde.com) is engineering the web since 2006.
