---
title: Script
nav_order: 3
---

# Script

The most important configuration for packages is **`script`**: it points to a script defined in package's `package.json`.

For example, let's take a package which has a `composer.json` like the following:

```json
{
   "name": "acme/some-plugin",
   "extra": {
      "composer-asset-compiler": {
         "script": "build"
      }
   }
}
```

and a `package.json` like the following:

```json
{
   "name": "acme/some-plugin",
   "devDependencies": {
      "gulp": "^4"
   },
   "scripts": {
      "build": "gulp build"
   }
}
```

When _Composer Assets Compiler_ will execute it will find the configuration in the `composer.json`, and then look for a script named `build` in the `package.json`.

Founding it, _Composer Assets Compiler_  will first install the dependencies (either via _npm_ or _Yarn_) and then will execute the script (either via `npm run build` or `yarn build`).



## Multiple scripts

It is possible to define multiple scripts which will be executed in series. Like:

```json
{
   "name": "acme/some-plugin",
   "extra": {
      "composer-asset-compiler": {
         "script": ["prepare", "build"]
      }
   }
}
```



## Short form

When `script` is the _only_ configuration needed, it is possible to use a shorter form, something like this:

```json
{
   "name": "acme/some-plugin",
   "extra": {
      "composer-asset-compiler": "build"
   }
}
```

that works with multiple scripts as well:

```json
{
   "name": "acme/some-plugin",
   "extra": {
      "composer-asset-compiler": ["prepare", "build"]
   }
}
```



## Parameters

Sometimes we need to pass parameters to the script. For example, having a `package.json` like the following:

```json
{
   "name": "acme/some-plugin",
   "devDependencies": {
      "gulp": "^4"
   },
   "scripts": {
      "gulp": "gulp"
   }
}
```

we might want to pass the Gulp task name.

Manually, we would do that via `npm run gulp -- task-name` or `yarn gulp task-name`.

Since _Composer Assets Compiler_ is agnostic about the package manager in use, it requires the _npm_ syntax, converting it correctly if the used package manager is _Yarn_.

That means we can have a _Composer Assets Compiler_ configuration like this:

```json
{
   "name": "acme/some-plugin",
   "extra": {
      "composer-asset-compiler": "build -- task-name"
   }
}
```

and it will work with both _npm_ and _Yarn_.



### Environment variables

Many times, it is required to make the script more "dynamic", and we can do that via environment variables.

For example:

```json
{
   "name": "acme/some-plugin",
   "extra": {
      "composer-asset-compiler": "gulp -- ${COMPILE_TASK}"
   }
}
```

With that, we could set the `COMPILE_TASK` environment variable to, for example, `"build"` and the plugin will end up executing `gulp build`.

It is important to note that in the case the interpolated environment variables are not defined, the script might result invalid.

To prevent that, it is possible to use a `default-env` configuration.



## Default environment

We could have a configuration like:

```json
{
   "name": "acme/some-plugin",
   "extra": {
      "composer-asset-compiler": {
        "default-env": {
          "COMPILE_TASK": "build"
        },
        "script": "gulp -- ${COMPILE_TASK}"
      }
   }
}
```

Thanks to that, in the case the `COMPILE_TASK` environment variable is not defined, its value will default to `build`, and the script will stay valid.

A special mention has to be made in the regard of `default-env` when used in the *root* package.

In that case, defaults defined for the root package might also apply for dependencies, in the case dependencies don't define `default-env` for missing environment variables.
