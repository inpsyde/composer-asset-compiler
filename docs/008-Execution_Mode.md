# Execution mode

Sometimes developers might desire to have a different configuration based on conditions, for example, _where_ the plugin is executed.

For example, having some behavior when running locally, or on a CI pipeline, and so on.

To satisfy that requirement, _Composer Assets Compiler_ introduces the concept of "execution mode".

The "execution mode" is an arbitrary string, which can be set in two ways:

- via the `COMPOSER_ASSET_COMPILER` environment variable
- via the `--mode` flag passed to the `composer compile-assets` command.



## Default mode

When no mode is set explicitly, _Composer Assets Compiler_ will default to the string `"$default"`.



## Configuration by mode

No matter how the mode is determined, it can be used to differentiate configuration per mode.



## Root-level mode

For example:

```json
{
  "name": "acme/some-theme",
  "extra": {
    "composer-asset-compiler": {
      "$mode": {
        "ci": {
          "package-manager": "npm",
          "script": "build -- ci",
          "dependencies": "install"
        },
        "local": {
          "package-manager": "yarn",
          "script": "build -- local",
          "dependencies": "update"
        },
        "$default": {
          "package-manager": "yarn",
          "script": "build",
          "dependencies": "install"
        }
      }
    }
  }
}
```

The `"$mode"` property is the entry point for the mode-specific configuration.

It must contain an object where the keys are the mode names, and the values are the associated configuration.

In the example above, having the `COMPOSER_ASSET_COMPILER` env variable set to `"local"` the package will be processed by updating dependencies with _Yarn_ and then executing `yarn build -- local`.



## Default and Default "no-dev"

If there's no mode defined in the system, or the mode defined in the system does not have a relative configuration entry, _Composer Assets Compiler_ will use the configuration for `"$default"`, if neither that is found there's nothing the plugin can do. That is why is highly suggested to always have a `"$default"` configuration when using mode.

When _Composer Assets Compiler_ runs automatically on Composer install or update, and those commands are executed with the `--no-dev` flag, before looking for the `"$default"` configuration, _Composer Assets Compiler_ will look for a `"$default-no-dev"` configuration.



## Property-level mode

In the previous example, the `"$mode"` key is used "root item", forcing us to have a complete set of configuration per mode. For example, if we would like to have a `pre-compiled` configuration that is the same for all the "modes" we would need to copy and paste it in each mode configuration.

That ends up being very verbose with duplicates.

In such cases, it is possible to use the `"$mode"` key at property level, only for the properties that must be mode-specific.

For example:

```json
{
  "name": "acme/some-theme",
  "extra": {
    "composer-asset-compiler": {
      "script": {
        "$mode": {
          "ci": "build -- ci",
          "local": "build -- local",
          "$default": "build"
        }
      },
      "pre-compiled": {
        "adapter": "gh-action-artifact",
        "config": {
          "repository": "acme/some-theme"
        },
        "source": "assets-${ref}",
        "target": "./assets/"
      }
    }
  }
}
```



## Deprecated "env"

In _Composer Assets Compiler_ < 3.0, the `"$mode"` property was called `"env"`.

That generated confusion because it has nothing to do with _environment_ variables nor with the `default-env` configuration. That's why it was replaced by "$mode"`in _Composer Assets Compiler_ 3.

However, to facilitate migration for packages previously using a lower version, the `"env"` key is still supported in version 3, but support might be removed in future versions.



------

| [← Hash and Lock](./007-Hash_and_Lock.md) | [Configuration File →](./009-Configuration_File.md) |
|:------------------------------------------|----------------------------------------------------:|