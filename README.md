# Composer Asset Compiler

> Composer plugin that install dependencies and compile assets based on configuration.

---

## What is this

This is a Composer plugin, that once part of the dependencies will look into a set of packages that 
have frontend assets to compile and or dependencies to install and will do just that.

It works with both npm and yarn.

---

## Configuration

Without configuration this package does nothing.

Configuration might happen at dependency level or at root package level.

### Package configuration

For each package it is required a configuration object with two keys: `dependencies` and `script`:

```json
{
	"dependencies": "install",
	"script": "setup"
}
```

The value of **`dependencies`** can be:

- `"install"`, which means "install dependencies" (`npm install` or `yarn`)
- `"update"`, which means "update dependencies" (`npm update --no-save` or `yarn upgrade`)
- anything else, means do not install dependencies

The value of **`script`** tells what to do _after_ dependencies are installed or updated
and anything set in the config will be passed to either `npm run` or `yarn`.

For example, the configuration snipped above tells the plugin to run either `npm run setup` or
`yarn setup`, which also means that `"setup"` must be a script named like that in `package.json`.

`script` can also be an array of commands, which will be run one after the other in the same order
they are written.


#### Environments

Very often we want to perform different operations based on the environment (local, staging, production...).

This plugin has support to that via an environment-based configuration, which could look like this:

```json
{
    "env": {
        "ci": {
            "dependencies": "install",
            "script": [
                "setup",
                "tests"
            ]
        },
        "local": {
            "dependencies": "update",
            "script": "setup"
        },
        "$default": {
            "dependencies": "install",
            "script": "setup"
        },
        "$default-no-dev": {
            "dependencies": "install",
            "script": [
                "setup",
                "optimize"
            ]
        }
    }
}
```

When such configuration is used, the first thing the plugin does is to recognize the environment.

That is done by looking into an environment variable named **`COMPOSER_ASSETS_COMPILER`.**

If this variable is not found, or its value does not match any key of the value in `"env"` object,
the plugin fallback to either `"$default-no-dev"`, only if Composer is running with `--no-dev` flag, and then to ``"$default"`.

Basically, if running as `--no-dev`, the plugin will look for, in order, a key that is:

- the value of `COMPOSER_ASSETS_COMPILER`
-  `"$default-no-dev"`
-  `"$default"`

if Composer is running *without*  `--no-dev`, the plugin will look for, in order, a key that is:

- the value of `COMPOSER_ASSETS_COMPILER`
-  `"$default"`

### Where to place configuration

The configuration shown above goes into `extra.composer-asset-compiler`.

So for a package we can have a `composer.json` that contains:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "env": {
                "$default": {
                    "dependencies": "update",
                    "script": "encore dev"
                },
                "$default-no-dev": {
                    "dependencies": "install",
                    "script": "encore prod"
                }
            }
        }
    }
}
```

or maybe just:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "dependencies": "install",
            "script": "gulp"
        }
    }
}
```

so:

- `dependencies`
- `script`
- `env`

are the only top-level keys that are took into account  at **package level**.

### Root configuration

The reason why this plugin exists is to be able to install frontend dependencies and run scripts for
Composer _dependencies_, otherwise Composer scripts would have probably be enough.

At root level, we can define which Composer dependencies we want to process.

That is done via a configuration object, that resides in **the same `extra.composer-asset-compiler`** 
**used at package level, but in the root `composer.json`.**

There are a few top-level keys that are took into consideration only for root package:

- `packages` (object, no default)
- `defaults` (object, no default)
- `auto-discover` (boolean, default `true`)
- `auto-run` (boolean, default `true`)
- `commands` (string|object, no default)
- `wipe-node-modules` (boolean|string|object, default `true`)
- `stop-on-failure` (boolean, default `false`)

**None of this is required**, the plugin can work without any configuration, assuming that
dependencies have package-level configuration.

### Root configuration: `packages`

`packages` is what tells the plugin which Composer dependencies to process.

It is an object where **keys** are packages names, e. g. `"some-vendor/some-package"`, but can make use of "wildcard" to actually refer to multiple packages, e. g. `"some-vendor/*"` or `"some-vendor/foo-*"`.

The `packages` object **values** can contain different things. In fact, it can be:

- a set of package-level setting (so either an object with `"dependencies"` and `"script"` or an object with `"env"` key and a series of objects in it with `dependencies` and `script`)
- a boolean `true`, that means *"process this dependencies using their package-level setting"*
  (will use defaults, if provided, if no package-level setting is found)
- a boolean `false`, that means *"do not process this dependencies"*
- the string `"force-defaults"`, that means *"process this dependencies using default setting"*,
  so ignoring any package-level setting. Will make plugin fail if no defaults are provided.

An example that contains all the above could be:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "packages": {
                "my-company/some-package": {
                    "env": {
                        "$default": {
                            "dependencies": "update",
                            "script": "encore dev"
                        },
                        "$default-no-dev": {
                            "dependencies": "install",
                            "script": "encore prod"
                        }
                    }
                },
                "my-company/some-plugin": {
                    "dependencies": "install",
                    "script": "gulp"
                },
                "my-company/client-*": true,
                "my-company/client-foo-package": false,
                "my-company/my-framework-*": "force-defaults"
            }
        }
    }
}
```

Please note that a `package.json` file is required for the dependencies to be processed, if that file is missing, the package is skipped.

#### Why `false`

It probably worth to expand on the reason why the `false` value is important.

First of all, it takes precedence over other values. In the example above, there's: `"my-company/client-*": true` meaning that all packages whose name starts with _"my-company/client-*"_ will be processed.

However, for the specific package _"my-company/client-foo-package"_ we are using `false`, meaning that this package will be an exception and will not be processed.

Please note that it is also possible to use `false` with wildcard keys, and not only with exact names.

Another reason for the `false` value, is that by default, the plugin processes all dependencies that
have a package-level configuration (more on this below). Using `false` it is possible to instruct the
plugin to skip some packages even if they have package-level configuration.


### Root configuration: `defaults` 

A set of package-level setting, so either an object `"dependencies"` and `"script"` or an object
with `"env"` key and a series of objects in it with `dependencies` and `script`.

This is used for packages where defaults are needed, that is packages listed in `packages` config 

- with a value of `"force-defaults"`
- with a value of `true`, but don't have any package level-configuration

An example:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "packages": {
                "my-company/client-*": true,
                "my-company/my-framework-*": "force-defaults"
            },
            "defaults": {
                "env": {
                    "$default": {
                        "dependencies": "update",
                        "script": "encore dev"
                    },
                    "$default-no-dev": {
                        "dependencies": "install",
                        "script": "encore prod"
                    }
                }
            }
        }
    }
}
```

In the snippet above, all the package whose name starts with `my-company/client-` will be processed,
and if they define package-level configuration, that will be used, otherwise what is defined in
`"defaults"` will be used.

For all the package whose name starts with `my-company/my-framework-`, no matter what package-level configuration contains, what is defined in `"defaults"` will be used.

### Root configuration: `auto-discover`

The plugin is capable of "scanning" all the required dependencies as see which among them:

- have `"composer-asset-compiler"` package-level configuration
- have a `package.json`
- have no (or outdated) `.composer_compiled_assets` file (more on this below)

and just process them, even if they are not listed in `packages`.

This means that **if settings are provided at package level, it is possible to have completely no**
**configuration at root level**, and still have dependencies processed.

In case this is not a desired behavior, `auto-discover` can be set to `false` and in that case only
packages listed in `packages` will be processed.

### Root configuration: `auto-run`

By default this plugin starts its job right after either `composer install` or `composer update`
have been executed.

However, the plugin work can be triggered manually via command (more on this below) and it might be desirable to _only_ run manually.

If that's the case, by setting `auto-run` to `false` the plugin will do nothing when either 
`composer install` or `composer update` have been executed, and will run only if manually called.

### Root configuration: `commands`

It has been said that this plugin can install dependencies and run scripts via either Yarn or NPM.
Which means that it has to decide which one to use.

By default, this decision is made automatically, checking the system for the availability of one of
them.

The `commands` configuration allows to enforce which one to use, by setting it to `"yarn"` or `"npm"`.

For very deep customization, `commands` allows to customize what should be executed, allowing in theory, to use a custom dependency management tool (or, more likely, to set special flags on the commands being run).

For example the setting:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "commands": "yarn"
        }
    }
}
```

is equivalent to:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "commands": {
                "dependencies": {
                    "install": "yarn",
                    "update": "yarn upgrade"
                },
                "script": "yarn %s"
            }
        }
    }
}
```

An interesting feature is the possibility to set commands by env:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "commands": {
                "env": {
                    "$default": "npm",
                    "local": "yarn",
                    "meh": {
                        "dependencies": {
                            "install": "yarn",
                            "update": "yarn upgrade"
                        },
                        "script": "npm run %s"
                    }
                }
            }
        }
    }
}
```

### Root configuration: `wipe-node-modules`

The plugin install frontend dependencies for each of the processed Composer packages, which means a `/node-modules` folder will be created for each processed Composer  package, so the possible impact on disk space can be quite huge.

However, is true that after assets have been compiled `/node-modules` folder is very likely not necessary anymore, and so could be deleted, and this is exactly what the plugin does by default.

By setting `wipe-node-modules` to `false` this is not done, and all `/node-modules` folder will be kept.

When `wipe-node-modules` is set to `true` (which is the default) `/node-modules` folder is deleted, but only if it is created by the plugin: in the case the folder was already there when the plugin started its work, then it is not deleted.

By setting `wipe-node-modules` to the string `"force"` all `/node-modules` folders for processed packages are always deleted, no matter if they existed when plugin started its work.

`wipe-node-modules` can also be configured per environment, as already seen in for other settings:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "wipe-node-modules": {
                "env": {
                    "$default": true,
                    "local": false,
                    "prod": "force"
                }
            }
        }
    }
}
```

### Root configuration: `stop-on-failure`

Packages are processed one by one, and if something fails for one of them, the plugin is very often capable to continue its work for other packages.

By setting `stop-on-failure` to `true` it is possible to instruct the plugin to stop processing when the first fail happen.

Just like different other settings, this is also configurable by environment:

```json
{
    "extra": {
        "composer-asset-compiler": {
            "stop-on-failure": {
                "env": {
                    "$default": true,
                    "local": false
                }
            }
        }
    }
}
```

### Package level configuration for root

Root package is still a package. Which means that the configuration that usually go at package level,
(`"dependencies"`, `"script"` or `"env"`) can also be set at root level and it will be used as expected.


### Root configuration at package level

Dependencies can be installed at root level, e. g. when running unit tests.

Which means that is possible to use root configuration at package level.

Considering that development and unit tests is most likely the reason why a package is installed as root,
it makes sense in most cases to set `auto-discover` to `false` for packages.

---

## Processed lock file

After a package has been successfully processed by the plugin, a file named `.composer_compiled_assets` is created in package root folder.

This file contains an hash calculated from:

- the content of dependency `package.json`
- the dependency plugin configuration
- the current environment

Before the plugin starts to process the dependency it checks if this file is there.
If so, the has is re-calculated and if it matches what is saved in the file the process of this
dependency is skipped.

This avoid to process dependency when not needed.

For example, imagine following scenario:

1. `composer install` is ran, and automatically all dependencies are processed
2. Few minutes later, a single Composer dependency is updated, the plugin starts again...

In this scenario only one Composer dependency have changed, but without `.composer_compiled_assets`
file all the dependencies would be processed again.

---

## Plugin command

This plugin ships with a command, **`compile-assets`**, that can be run via Composer, like:

```shell
$ composer compile-assets
```

That allow to run the plugin manually, without triggering a Composer update or install.

When the root configuration `auto-run` is `false`, using the command is the only way to execute the plugin.

Many plugin configurations rely on environment. And when no (or wrong) environment is set via environment
variable, the plugin fallback to either `$default` or `$default-no-dev` based on the `--no-dev` flag
which is a flag used for `composer install` or `update`.

### Plugin command environment

A way to overcome this issue when running the command is possible to use the same **`--no-dev`** flag
that Composer install and update commands use.

The plugin command also provide a way to set the running environment, ignoring the environment variable.

That is the option: **`--env`** option. It can also be used in combination with flag, for example,
running the command like this:

```shell
$ composer compile-assets --env=ci --no-dev
```

we are telling the plugin to run using `"ci"` environment, and if no setting can be found for that
environment, then fallback to settings for `"$default-no-dev"` environment if available.

---

## Installation

### Requirements

* PHP 7.2 or higher
* Composer 1.8+
* either Yarn or NPM (and compatible Node.js version)

### Installation

Via Composer, package name is `inpsyde/composer-assets-compiler`.

---

## License

Copyright (c) 2019 Inpsyde GmbH

This code is licensed under the [MIT License](LICENSE).

The team at [Inpsyde](https://inpsyde.com) is engineering the web since 2006.