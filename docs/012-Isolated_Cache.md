# Isolated cache

On larger projects, with many packages to process, the package manager cache sometimes  gets "messed up" and processing fails.

When that happens, one possible solution is to set `isolated-cache` _Composer Assets Compiler_ setting to true.

That ensures that each package is processed in a different cache folder (under the system temp folder), and that usually fixes cache-related problem.

It works by passing `--cache` command flag to _npm_ or `--cache-folder` flag to _Yarn_.

It might occur that the custom cache folder is not writable, in that case _Composer Assets Compiler_ will clean the cache before installing targeting packages to ensure isolated cache.

For obvious reasons, enabling `isolated-cache` comes at the cost of performance, but can be useful in some situations.

That is why the preferred way to solve *both* "botched cache" and performance problems is to use pre-compiled assets.
