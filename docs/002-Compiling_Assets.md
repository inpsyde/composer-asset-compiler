# Compiling Assets

The assets compiling process can start in two ways:

- automatically, after each `composer install` / `composer update`
- manually, via the custom `composer compile-assets` command the plugin provides.



## Auto-run

To enable "auto-run", it is necessary to use in package configuration an `auto-run` setting:

```json
{
   "name": "acme/some-theme",
   "extra": {
      "composer-asset-compiler": {
         "auto-run": true
      }
   }
}
```

The configuration only takes effect when used in the _root_ Composer package.



## Enable the plugin

_Composer Assets Compiler_ is a Composer plugin. As of Composer 2.2, plugins must be enabled to be used. Please remember to enable `inpsyde/composer-assets-compiler` plugin in root package's `composer.json`:

```json
{
  "config": {
    "allow-plugins": {
      "inpsyde/composer-assets-compiler": true
    }
  }
}
```
