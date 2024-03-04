---
title: CLI commands and parameters
nav_order: 16
---

# CLI commands and parameters


## Command: `compile-assets`

| Parameter                  | Description                                                                                                                                                   |
|----------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--ignore-lock=<packages>` | Ignores locks of packages specified as a comma-separated list. If a wildcard is passed instead (`*`), ALL locks are ignored.                                  |
| `--mode=<mode>`            | Specifies the "execution mode" which is used to select between multiple configuration variants.                                                               |
| `--no-dev`                 | Simulate auto-run on Composer installation/update with the `--no-dev` flag.<br />This causes the plugin to check for `$default-no-dev` as the default "mode". |



## Command: `asset-hash`

| Parameter       | Description                                                                                                                                                   |
|-----------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--mode=<mode>` | Sets the "execution mode" which might affect the hash of the assets.                                                                                          |
| `--no-dev`      | Simulate auto-run on Composer installation/update with the `--no-dev` flag.<br />This causes the plugin to check for `$default-no-dev` as the default "mode". |

