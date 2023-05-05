---
title: Environment Variables
nav_order: 16
---

# Environment Variables

| Name                                        | Description                                                                                                                                  |
|---------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------|
| `COMPOSER_ASSETS_COMPILER`                  | Set the ["Execution mode"](./008-Execution_Mode.md).                                                                                         |
| `COMPOSER_ASSET_COMPILER_PRECOMPILING`      | When non-empty tells no dependency is compiled. Same effect than setting `auto-discover` setting to false.                                   |
| `COMPOSER_ASSET_COMPILER_PACKAGE_MANAGER`   | Set the package manager. Alternative to [`package-manager` setting](005-Package_Manager.md). Can be used to set the default package manager. |
| `COMPOSER_ASSET_COMPILER_ISOLATED_CACHE`    | Allow "isolate cache" mode. Alternative to [`isolated-cache` setting](./012-Isolated_Cache.md).                                              |
| `COMPOSER_ASSET_COMPILER_STOP_ON_FAILURE`   | Alternative to `stop-on-failure` setting.                                                                                                    |
| `COMPOSER_ASSET_COMPILER_WIPE_NODE_MODULES` | Alternative to `wipe-node-modules` setting.                                                                                                  |
| `COMPOSER_ASSET_COMPILER_AUTO_DISCOVER`     | Alternative to `auto-discover` setting.                                                                                                      |
| `COMPOSER_ASSET_COMPILER_MAX_PROCESSES`     | Alternative to `max-processes` setting.                                                                                                      |
| `COMPOSER_ASSET_COMPILER_PROCESSES_POLL`    | Alternative to `processes-poll` setting.                                                                                                     |
| `COMPOSER_ASSET_COMPILER_TIMEOUT_INCR`      | Alternative to `timeout-increment` setting.                                                                                                  |
| `GITHUB_USER_NAME`                          | Set the GitHub user name to be used for GitHub pre-compilation adapters.                                                                     |
| `GITHUB_API_USER`                           | Alternative to `GITHUB_USER_NAME`.                                                                                                           |
| `GITHUB_ACTOR`                              | Alternative to `GITHUB_USER_NAME`.                                                                                                           |
| `GITHUB_USER_TOKEN`                         | Set the GitHub Personal Access Token to be used for GitHub pre-compilation adapters.                                                         |
| `GITHUB_API_TOKEN`                          | Alternative to `GITHUB_API_TOKEN`.                                                                                                           |
| `GITHUB_TOKEN`                              | Alternative to `GITHUB_API_TOKEN`.                                                                                                           |
| `GITHUB_API_REPOSITORY`                     | Set the GitHub repository to be used for GitHub pre-compilation adapters. Alternative to `pre-compiled.config.repository` setting.           |
| `GITHUB_REPOSITORY`                         | Alternative to `GITHUB_API_REPOSITORY`.                                                                                                      |

## Default env

All variables **but** `COMPOSER_ASSETS_COMPILER` can have a default value set via `default-env` setting.
