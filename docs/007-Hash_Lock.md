---
title: Hash and Lock
nav_order: 8
---

# Hash and Lock

Each time the assets for a package are processed, a lock file named **`.composer_compiled_assets`** is saved in the root directory of the package.

**That file should be git-ignored if the compiled assets are git-ignored**.

The lock file contains a hash, referenced as "*Composer Asset Compiler package's hash*", which is created based on _Composer Assets Compiler_ configuration and the contents of certain files such as `package.json`, `package-lock.json`, `npm-shrinkwrap.json`, and `yarn.lock`.

If a lock file is found, and its content matches the hash calculated for the current package, the package will not be processed.

That is especially important when _Composer Assets Compiler_ auto-executes on Composer install, otherwise _Composer Assets Compiler_ would process assets for all packages, even if none changed.



## Custom source files

Very likely, the compiled assets will change when the assets "sources" are changed. _Composer Assets Compiler_ is not aware of where sources are located, so it can't take those into account when calculating the hash.

That's why there's a `src-paths` configuration, that can be used to instruct _Composer Assets Compiler_ about the source files to use as a base for the package's hash.

```json
{
  "name": "acme/some-theme",
  "extra": {
    "composer-asset-compiler": {
      "script": "build",
      "src-paths": [
        "./js/*.js",
        "./sass/*.scss"
      ]
    }
  }
}
```

Please note _Composer Asset Compiler_ uses [Symfony Finder](https://symfony.com/doc/current/components/finder.html#location) component behind the scenes, which means the wildcard to indicate "one or more folders" is `*`, not `**`.



## What affect hash generation

- The content of `package.json`, `package-lock.json`, `npm-shrinkwrap.json`, and `yarn.lock` files (if exising)
- The content of any file found in the paths in the `src-paths` config (if any)
- The "evaluated" `script`, which might depend on: environment variables, `default-env` configuration, and execution mode.
- The "dependencies" configuration.



## Ignoring lock

When compiling assets via the `compile-assets` command, it is possible to pass a `--ignore-lock` flag to always build assets, regardless the presence and the content of the lock file.

````shell
composer compile-assets --ignore-lock=*
````



## Calculate package's hash on demand

The hash of the package can also be calculated programmatically using the command:

```shell
composer asset-hash
```

That can be useful to generate the filename of the archive during pre-compilation, in case we want to use the `${hash}` placeholder.

The command accept a `--mode` flag because [mode](./008-Execution_Mode.md) might affect the calculated hash.
