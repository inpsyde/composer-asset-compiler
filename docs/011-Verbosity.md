---
title: Verbosity
nav_order: 12
---

# Verbosity

_Composer Assets Compiler_ is a Composer plugin, which means that all Composer commands' ["global options"](https://getcomposer.org/doc/03-cli.md#global-options) apply.

Composer options that control `verbosity` have multiple effects on _Composer Assets Compiler_:

- Composer functions used by the plugin (e. g. the "HTTP Downloader") are customized in their verbosity
- _Composer Assets Compiler_ itself gives more or less output depending on these options
- Composer verbosity is "mapped" to package manager verbosity flags according to the following table:

| Composer flag      | _npm_      | _Yarn_      |
|--------------------|------------|-------------|
| `-v` / `--verbose` | `-d`       |             |
| `-vv`              | `-dd`      |             |
| `-vvv`             | `-ddd`     | `--verbose` |
| `-q` / `-quite`    | `--silent` | `--silent`  |

Moreover, when using Composer with the `--no-interaction` flag, _Yarn_ gets the `--non-interactive` flag, but _npm_ has no corresponding flag.
