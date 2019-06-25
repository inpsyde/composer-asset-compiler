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
		"default": {
			"dependencies": "install",
			"script": "setup"
		},
		"default-no-dev": {
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

That is done by looking into an environment variable named `COMPOSER_ASSETS_COMPILER`.

If this variable is not found, or its value does not match any of the value in `"env"` object,
the plugin fallback to either `"default"` or `"default-no-dev"`, based on the fact that Composer
is running, respectively, without or with the `--no-dev` flag.

If `"default"` and `"default-no-dev"` entries are both missing, and no environment is found,
the plugin will do nothing.

If only `"default-no-dev"` is provided, and `"default"` is not, the `"default-no-dev"` operations
and script are performed only if Composer is being ran using `--no-dev`, nothing will be done
if that flag is not used and no environment is found.

If only `"default"` is provided, and `"default-no-dev"` is not, the `"default"` operations
and script are performed no matter if Composer is being ran using `--no-dev` or not.

### Where to place configuration

The configuration shown above goes into `extra.composer-asset-compiler`.

So for a package we can have a `composer.json` that contains:

```json
{
	"extra": {
		"composer-asset-compiler": {
			"env": {
				"default": {
					"dependencies": "update",
					"script": "encore dev"
				},
				"default-no-dev": {
					"dependencies": "install",
					"script": "encore prod"
				}
			}
		}
	}
}
```

The configuration discussed above has to be used at **package level**.

### Root configuration

The reason why this plugin exists is to be able to install frontend dependencies and run scripts for
Composer _dependencies_, otherwise Composer scripts will probably do.

At root level, we can define which Composer dependencies we want to process.

That is done via a configuration object, that resides in the same `extra.composer-asset-compiler` 
used at package level, in the root `composer.json`.

There are 5 top-level root configuration keys:

- `include` (object, no default)
- `exclude` (array, no default)
- `defaults` (object, no default)
- `auto-discover` (boolean, default `true`)
- `auto-run` (boolean, default `true`)
- `commands` (string|object, no default)
- `wipe-node-modules` (boolean|string|object, default `true`)

**None of this is required**, the plugin can work without any configuration, assuming that
dependencies have package-level configuration.

### Root configuration: `include`

`include` is what tells the plugin which Composer dependencies to process.

It is an object where **keys** are packages names, e. g. `"some-vendor/some-package"`, but can make use
of "wildcard" to actually refer to multiple packages, e.g. `"some-vendor/*"` or `"some-vendor/foo-*"`.

The `include` object **values** can contain different things. In fact, it can be:

- a set of package-level setting (so either an object `"dependencies"` and `"script"` or an object
  with `"env"` key and a series of objects in it with `dependencies` and `script`)
- a boolean `true`, that means "process this dependency(ies) using its (their) package-level setting"
  (will use defaults if no package-level setting is found)
- the string `"force-defaults"`, that means "process this dependency(ies) using default setting"
  (so ignoring any package-level setting)
  
An example that contains all the above could be:

```json
{
	"include": {
		"my-company/some-package": {
			"env": {
				"default": {
					"dependencies": "update",
					"script": "encore dev"
				},
				"default-no-dev": {
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
		"my-company/my-framework-*": "force-defaults"
	}
}
```

Please note that besides the configuration is of course required that a `package.json` file is
available for the dependencies to process, if that file is missing, the package is skipped.

### Root configuration: `exclude` 

The `exclude` array is a list of packages to don't process.

This is useful when using `include` with wildcard, to exclude some specific package from the
wildcard matching.

An example:

```json
{
	"include": {
		"my-company/*": true
	},
	"exclude": [
		"my-company/client-*",
		"my-company/exclude-me"
	]
}
```

### Root configuration: `defaults` 

A set of package-level setting, so either an object `"dependencies"` and `"script"` or an object
with `"env"` key and a series of objects in it with `dependencies` and `script`.

This is used for packages listed in `include` with a value of either `"force-defaults"` or `true`,
but don't have any package level-configuration.

When defaults are needed (a package is included with `"force-defaults"` or a package without
package-level configuration is included with `true`) and `defaults` config is not provided
than nothing will be done for that package.

An example:

```json
{
	"include": {
		"my-company/client-*": true,
		"my-company/my-framework-*": "force-defaults"
	},
	"defaults": {
		"env": {
			"default": {
				"dependencies": "update",
				"script": "encore dev"
			},
			"default-no-dev": {
				"dependencies": "install",
				"script": "encore prod"
			}
		}
	}
}
```

In the snippet above, all the package whose name starts with `my-company/client-` will be processed,
and if they define package-level configuration, that will be used, otherwise what's defined in
`"defaults"` will be used.

For all the package whose name starts with `my-company/my-framework-`, no matter what package-level
configuration contains, what's defined in `"defaults"` will be used.

### Root configuration: `auto-discover`

The plugin is capable of "scanning" all the required dependencies as see which among them:

- have `"composer-asset-compiler"` package-level configuration
- have a `package.json`
- have no (or outdated) `.composer_compiled_assets` file (more on this below)

and just process them, even if they are not listed in `include`.

This means that if settings are provided at package level, it is possible to have completely no
configuration at root level, and still have dependencies processed.

In case this is not a desired behavior, `auto-discover` can be set to `false` and in that case only
packages listed in `included` (and not listed in `excluded`) will be processed.

### Root configuration: `auto-run`

By default this plugin starts its job right after either `composer install` or `composer update`
have been executed.

However, the plugin work can be triggered manually via command (more on this below) and it might
be desirable to _only_ run manually.

If that's the case, by setting `auto-run` to `false` the plugin will do nothing when either 
`composer install` or `composer update` have been executed, and will run only if manually called.

### Root configuration: `commands`

It has been said that this plugin can install dependencies and run scripts via either Yarn or NPM.
Which means that it has to decide which one to use.

By default, this decision is made automatically, checking the system for the availability of one of
them.

The `commands` configuration allows to enforce which one to use, by setting it to `"yarn"` or `"npm"`.

For very deep customization, `commands` allows to customize what should be executed, allowing in theory
to use a custom dependency management tool (or, more likely, to set special flags on the commands being run).

For example the setting:

```json
{
	"commands": "yarn"
}
```

is equivalent to:

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

An interesting feature is the possibility to set commands by env:

```json
{
	"commands": {
		"env": {
			"default": "npm",
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
```

### Root configuration: `wipe-node-modules`

With a lot of packages to install, and frontend dependencies installed for each of them, it means that
a `/node-modules` folder will be created for each processed dependency, meaning that the possible
impact on disk space can be quite huge.

It is also true that after assets have been compiled `/node-modules` folder is very likely not necessary anymore,
and so could be deleted, and this is exactly what the plugin does by default.

By setting `wipe-node-modules` to `false` this is not done, and all `/node-modules` folder will be kept.

When `wipe-node-modules` is set to `true` (which is the default) `/node-modules` folder is deleted
whn it is created by the plugin, but if it was already existing when the plugin started its work, then
it is kept.

By setting `wipe-node-modules` to the string `"force"` all `/node-modules` folders for processed packages
are always deleted, no matter if they existed when plugin started its work.

`wipe-node-modules` can also be configured per environment, as already seen in for other settings:

```json
{
	"wipe-node-modules": {
		"env": {
			"default": true,
			"local": false,
			"prod": "force"
		}
	}
}
```


### Package level configuration for root

Root package is still a package. Which means that the configuration that usually go at package level,
(`dependencies`, `scripts` or `env`) can also be set at root level and it will be used as expected.


### Root configuration at package level

Dependencies can be installed at root level, e.g. when running unit tests.

Which means that is possible to use root configuration at package level.

Considering that development and unit tests is most likely the reason why a package is installed as root,
it makes sense in most cases to set `auto-discover` to `false` for packages.

---

## Processed lock file

After a dependency has been successfully processed by the plugin, a file named `.composer_compiled_assets`
is created in dependency root folder.

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
variable, the plugin fallback to either `default` or `default-no-dev` based on the `--no-dev` flag
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
environment, then fallback to settings for `"default-no-dev"` environment if available.

---

## Installation

### Requirements

* PHP 7.2 or higher
* Composer 1.8+
* either Yarn or NPM (and compatible Node.js version)

### Installation

Via Composer, package name is `inpsyde/composer-assets-compiler`.

## License

Copyright (c) 2019 Inpsyde GmbH

This code is licensed under the [GPLv2+ License](LICENSE).

The team at [Inpsyde](https://inpsyde.com) is engineering the web since 2006.