# Configuration file

Sometimes the _Composer Assets Compiler_ configuration object can become very large and "pollute" the `composer.json`.

In such cases, it is possible to use the same configuration that is stored in the `extra.composer-asset-compiler` in a **separate file named `assets-compiler.json`** located in the package root directory.

When adding that file, it is not necessary to also add anything in `composer.json`.

If one package has _both_ `assets-compiler.json` file *and* the `extra.composer-asset-compiler` in `composer.json`, the latter will be ignored and only the contents of the `assets-compiler.json` file will be taken into account.
