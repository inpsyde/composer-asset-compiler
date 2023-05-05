---
title: Dependencies
nav_order: 6
---

# Package Manager

_Composer Asset Compiler_ can work with both _npm_ and _Yarn_. Nevertheless, some packages might require the usage of a specific package manager.



## Auto-discovery

For that reason, _Composer Asset Compiler_ tries to determine the best package manager for each package using the following criteria:

- if a file `package-lock.json` or `npm-shrinkwrap.json` is found in the package's root folder, the chosen package manager will be _npm_.
- if a file `yarn.lock` is found in the package's root folder, the chosen package manager will be _Yarn_.



## Configuration

Instead of letting _Composer Asset Compiler_ determine the best package manager for the package, it is possible to manually configure it via the `package-manager` configuration. For example:

```json
{
  "name": "acme/some-plugin",
  "extra": {
    "composer-asset-compiler": {
      "package-manager": "yarn",
      "script": "gulp build"
    }
  }
}
```



## Fall-back

It might happen that _Composer Asset Compiler_ determines a package manager is the best choice for a package, but it is not installed in the system.

In that case, _Composer Asset Compiler_ will default to the other package manager.

If neither _npm_ nor _Yarn_ are installed _Composer Asset Compiler_ execution will fail.
