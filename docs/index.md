---
title: Introduction
nav_order: 1
---

# Introduction

## What is this

A Composer plugin that automatically "compiles" frontend assets (js, css, etc.) for packages installed via Composer.


## A quick example

Let's assume we have a website project having a `composer.json` that looks like this:

```json
{
    "name": "acme/my-project",
    "require": {
        "acme/foo": "^1",
        "acme/bar": "^2",
        "inpsyde/composer-assets-compiler": "^4"
    },
    "extra": {
        "composer-asset-compiler": { "auto-run": true }
    }
}
```

And then suppose that `acme/foo`'s `composer.json` looks like this:

```json
{
    "name": "acme/foo",
    "extra": {
        "composer-asset-compiler": "gulp"
    }
}
```

and `acme/bar`'s `composer.json` looks like this:

```json
{
    "name": "acme/bar",
    "extra": {
        "composer-asset-compiler": "build"
    }
}
```

When we'll install the project with Composer, the following happens:

1. Composer installs the three required packages
2. Immediately after that, Composer Assets Compiler executes and:
    1. looks for all installed packages (including transitive dependencies) that have a `composer-asset-compiler` configuration, finding `"acme/foo"`and `"acme/bar"`
    2. moves to `"acme/foo"` installation folder, and executes `npm install && npm run gulp`
    3. moves to `"acme/bar"` installation folder, and executes `npm install && npm run build`

At the end of the process, we have a project with the dependencies installed, and their assets processed.

The example above is the simplest use case, but the Composer Assets Compiler has many possible configurations and advanced use cases.


## Documentation

- [Why bother](./001-Why_Bother.md)
- [Compiling Assets](./002-Compiling_Assets.md)
- [Script](./003-Script.md)
- [Dependencies](./004-Dependencies.md)
- [Package Manager](./005-Package_Manager.md)
- [Pre-compilation](./006-Pre-compilation.md)
- [Hash and Lock](./007-Hash_Lock.md)
- [Execution Mode](./008-Execution_Mode.md)
- [Configuration File](./009-Configuration_File.md)
- [Packages Configuration in Root](./010-Packages_Configuration_Root.md)
- [Verbosity](./011-Verbosity.md)
- [Isolated Cache](./012-Isolated_Cache.md)
- [Parallel Assets Processing](./013-Parallel_Assets_Processing.md)
- [Configuration Cheat-Sheet](./014-Configuration_Cheat-Sheet.md)
- [CLI Commands and Parameters](./015-CLI_Commands_Parameters.md)
- [Environment Variables](./016-Environment_Variables.md)
