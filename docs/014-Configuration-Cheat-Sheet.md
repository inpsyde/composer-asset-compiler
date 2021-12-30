# Configuration Cheat-Sheet

| Name                | Description                                                                                                                      | Root-only | Default   |
|---------------------|----------------------------------------------------------------------------------------------------------------------------------|-----------|-----------|
| `auto-discover`     | If false, the plugin processes only the root package and ignores all dependencies.                                               | YES       | true      |
| `auto-run`          | If true, the plugin will run immediately after each Composer update or installation.                                             | YES       | false     |
| `defaults`          | Provides a default configuration for packages defined at root level.                                                             | YES       | --        |
| `default-env`       | Provide default values for environment variables.                                                                                | NO        | --        |
| `dependencies`      | Either "install", "update", or "none". Specifies if/how the package manager installs dependencies.                               | NO        | "install" |
| `isolated-cache`    | If "true", the package manager uses a separate cache for each package.                                                           | NO        | false     |
| `max-processes`     | Number of maximum parallel access processing.                                                                                    | YES       | 4         |
| `package-manager`   | Which package manager to use.                                                                                                    | NO        | --        |
| `packages`          | Can be used at in the root package to override dependencies configuration.                                                       | YES       | --        |
| `pre-compiled`      | Pre-compilation settings.                                                                                                        | NO        | --        |
| `processes-poll`    | Interval in microseconds for updating the parallel processing status.                                                            | YES       | 100000    |
| `script`            | The script used to process assets.                                                                                               | NO        | --        |
| `src-paths`         | Source paths patterns used to calculate the package's hash used for lock.                                                        | NO        | --        |
| `stop-on-failure`   | If false, assets processing will continue for other packages if an error occurs for one package.                                 | YES       | true      |
| `timeout-increment` | The increment in seconds to add to assets processing timeout for each package to process.                                        | YES       | false     |
| `wipe-node-modules` | Whether the `node_modules` folder of packages should be deleted after processing. Only applies to folders created by the plugin. | YES       | false     |




------

| [← Parallel Assets Processing](./013-Parallel_Assets_Processing.md) | [CLI-Parameters →](./015-CLI-Parameters.md) |
|:--------------------------------------------------------------------|--------------------------------------------:|