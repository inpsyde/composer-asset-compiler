# Pre-compilation

By default, _Composer Asset Compiler_ loops installed Composer packages, and for each of them installs frontend dependencies and run a compiling script.

Doing that can can take *several minutes per package*. For a project with 20 or more packages to compile assets for, that is *a lot*.

To solve that problem, _Composer Asset Compiler_ implements the so-called "pre-compilation".

It works as follows:

1. The packages' assets are compiled separately and provided to a storage service supported by the plugin. At the time of writing, the plugin supports _GitHub Artifacts_, _GitHub Release Binary_, and "generic" archive URLs.
2. When the Composer plugin finds a package to process, instead of immediately processing it, it checks if the package supports pre-compilation, and if so, it tries to download the pre-compiled assets.
3. If pre-compiled assets are found, they are downloaded and used; if they are not found, the plugin continues installing dependencies and processing the assets "on the fly", as usual.

_Composer Asset Compiler_ does not care how the assets are compiled (step *1.* above): as long as an archive with pre-compiled assets is found, the plugin can work with it.

Here's an example of how we can configure pre-compilation for a package:

```json
{
  "name": "acme/foo",
  "extra": {
    "composer-asset-compiler": {
      "script": "build",
      "pre-compiled": {
        "adapter": "gh-action-artifact",
        "source": "assets-${ref}",
        "target": "./assets/",
        "config": {
          "repository": "acme/foo"
        }
      }
    }
  }
}
```

The `pre-compiled` object tells us the package supports pre-compilation.

Let's look at the configuration in detail.



## Target

`pre-compiled.target` is the folder where the pre-compiled assets will be placed after download.



## Adapter

`pre-compiled.adapter` tells us where the precompiled assets are stored.

`"gh-action-artifact"` in the above example tells us that the pre-compiled assets will be stored as [GitHub Actions artifacts](https://docs.github.com/en/actions/guides/storing-workflow-data-as-artifacts).

Other supported adapters are:

- `"gh-release-zip"` (assets save as [GitHub release binary](https://docs.github.com/en/github/administering-a-repository/releasing-projects-on-github/managing-releases-in-a-repository#creating-a-release))
- `"archive"` (assets saved in an archive accessible via a URL).



## Source and Configuration

`pre-compiled.source` and `pre-compiled.config` have different meanings depending on the `adapter`.



### GitHub adapters configuration

The `"gh-action-artifact"` and the `"gh-release-zip"` adapters share the same values for `pre-compiled.source` and `pre-compiled.config`.

For both adapters, `pre-compiled.source` is the name of the archive.

It may contain dynamic placeholders (like the `${ref}` in the snippet above). More on this below.

`pre-compiled.config` is a configuration object for the adapter, and its only mandatory property is `pre-compiled.config.repository` that is the owner and name of the GitHub repository.

For example, `"repository": "acme/some-theme"` means the repository URL is `https://github.com/acme/some-theme.git`.

If the repository is private, we need to specify the username of a GitHub user who has access to the repository, and an [access token](https://docs.github.com/en/github/authenticating-to-github/keeping-your-account-and-data-secure/creating-a-personal-access-token) for that user.

The username can be set via the `pre-compiled.config.user` property or via the `GITHUB_USER_NAME` environment variable in the system running the _Composer Asset Compiler_.

The access token could be set via the `pre-compiled.config.token` property, but this is discouraged. A safer approach is to use the `GITHUB_USER_TOKEN` environment variable or rely on Composer authentication using the [`github-oauth` method](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#github-oauth). In the latter case, configuring the username is not necessary.



### Archive adapter configuration

When using the `archive` adapter, `pre-compiled.source` is the full archive URL. It may contain dynamic placeholders (more on this below).

`pre-compiled.config` is an object, and it is completely optional.

It can have a `pre-compiled.config.type` to configure the archive type (supported: "zip", "rar", "tar" and "xz"). If it is not specified, it is guessed based on the file extension in the source URL, and if it has no file extension, it defaults to `zip`.

An additional `pre-compiled.config.auth` property can be used to set up authentication for the archive. It might be an object with `user` and `password` properties, in which case those will be used for HTTP Basic authentication. Alternatively, it can be a string holding the content of an `Authorization` header.

Please note that any authentication set for Composer will apply.



## Placeholders

Regardless of the adapter, `pre-compiled.source` can contain one or more placeholders that are dynamically replaced when the assets are compiled.

The supported placeholders are:

- **`${ref}`** - replaced by the "reference" of the Composer package (which for GitHub hosted packages is the SHA1 of the commit installed).
- **`${version}`** - replaced by the version of the Composer package.
- **`${mode}`** - replaced by the _Composer Asset Compiler_ mode (see ["Execution Mode"](./008-Execution_Mode.md))
- **`${hash}`** - replaced by the hash of the package (see ["Hash and Lock"](./007-Hash_and_Lock.md))



## Pre-compilation by stability

Sometimes we would like to have a different mechanism for pre-compilation, depending on whether the installed package has a `stable` stability or not.

For example, when a package is required from a version (e. g. `"~1.1.0"`), we might want to use the `"gh-release-zip"` adapter, whereas when it is required from a branch (e. g. `"dev-main"`) we might want to use the `"gh-action-artifact"`.

That can be achieved by using two sets of `pre-compiled` configuration, one specifying `"stable"` and another `"dev"` for `stability`.

For example:

```json
{
   "name": "acme/some-theme",
   "extra": {
      "composer-asset-compiler": {
         "script": "build",
         "pre-compiled": [
            {
               "stability": "stable",
               "adapter": "gh-release-zip",
               "source": "assets-${version}",
               "target": "./assets/",
               "config": {
                  "repository": "acme/some-theme"
               }
            },
            {
               "stability": "dev",
               "adapter": "gh-action-artifact",
               "source": "assets-${ref}",
               "target": "./assets/",
               "config": {
                  "repository": "acme/some-theme"
               }
            }
         ]
      }
   }
}
```




------

| [← Package Manager](./005-Package_Manager.md) | [Hash and Lock →](./007-Hash_and_Lock.md) |
|:----------------------------------------------|------------------------------------------:|