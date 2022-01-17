# Packages Configuration in Root

Sometimes when building a _project_ with several dependencies, we might have the need to change the _Composer Assets Compiler_ behavior for installed dependencies.

That might include:

- compile assets for packages that _don't_ have _Composer Assets Compiler_ configuration
- _don't_ compile assets for packages that have _Composer Assets Compiler_ configuration
- change packages' _Composer Assets Compiler_ configuration

All the above can be obtained with configuration in the root package via a `packages` setting.

## Add or change configuration

Let's take the example:

```json
{
  "extra": {
    "composer-asset-compiler": {
      "packages": {
        "acme/something": {
          "script": "build",
          "package-manager": "yarn"
        }
      }
    }
  }
}
```

In the above example, we have provided configuration for the `"acme/something"` package. If that package didn't have any configuration, we've now added it. If that package had any configuration, we've overwritten it.



## Disable packages processing

We can prevent _Composer Assets Compiler_ to process packages that have some configuration by setting to `false` their in the `packages` property.

```json
{
  "extra": {
    "composer-asset-compiler": {
      "packages": {
        "acme/something": false
      }
    }
  }
}
```



## Patterns

Packages' settings might be set also via patterns, for example:

```json
{
  "extra": {
    "composer-asset-compiler": {
      "packages": {
        "acme/*": false
      }
    }
  }
}
```



## Defaults

More often than not, when defining _Composer Assets Compiler_ configuration in the root package, the configuration is the same for all packages. That means we're going to have a lot of duplication.

For example:

```json
{
  "extra": {
    "composer-asset-compiler": {
      "packages": {
        "acme/foo": {
          "script": "build",
          "package-manager": "yarn"
        },
        "acme/bar": {
          "script": "build",
          "package-manager": "yarn"
        },
        "acme/baz": {
          "script": "build",
          "package-manager": "yarn"
        }
      }
    }
  }
}
```

In such cases, _Composer Assets Compiler_ allow us to have a more compact configuration via the usage of defaults.

For example:

```json
{
  "extra": {
    "composer-asset-compiler": {
      "defaults": {
        "script": "build",
        "package-manager": "yarn"
      },
      "packages": {
        "acme/*": "$force-defaults",
        "foo/bar": true
      }
    }
  }
}
```

Above, we have defined the configuration once in the `"defaults"` property, and then instructed _Composer Assets Compiler_ to use that configuration for the listed packages.

To do that, for each package we can use either `"$force-defaults"` or `true`.

- `true` means: _"if the package has a configuration, use it, otherwise use the defaults"_
- `$force-defaults` means: _"process the assets for this package always using defaults"_



## Root package is a package

Please note that the root package is a package. Which means it might have assets to compile, and so it supports "normal" _Composer Assets Compiler_ such as `"script"`, `"dependencies"`, and such.

```json
{
  "extra": {
    "composer-asset-compiler": {
      "script": "build",
      "package-manager": "yarn",
      "defaults": {
        "script": "build",
        "package-manager": "yarn"
      },
      "packages": {
        "acme/foo": "$force-defaults",
        "acme/bar": "$force-defaults"
      }
    }
  }
}
```



## Root-only

By default, when the root package have dependencies listed in `packages` property, it will process them _in addition_ to packages that have configuration defined at package level.

It might be desirable to compile _only_ packages listed in the `packages` property (if any), beside the root package's assets.

To obtain that, it is possible to use the `auto-discover` setting with a value of `false`.



## Root "default-env"

In the ["Script"](./003-Script.md#default-environment) chapter, we have seen how it is possible to define default values for environment variables used in `script` via the `default-env` setting.

Root package is a package, so we can have a `default-env` setting also in root package.

However, `default-env` defined in the root package has a "special" meaning, because it would provide a default also for dependencies, in the case dependencies don't define `default-env` for missing environment variables.



------

| [← Configuration File](./009-Configuration_File.md) | [Verbosity →](./011-Verbosity.md) |
|:----------------------------------------------------|----------------------------------:|
