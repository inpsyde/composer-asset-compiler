---
title: CLI parameters
nav_order: 16
---

# CLI parameters


## Command: `compile-assets`

| Parameter                    | Description                                                                                                                                                   |
|------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--ignore-lock[=<packages>]` | Ignores locks for all or specific packages. Multiple packets must be comma-separated.                                                                         |
| `--mode=<mode>`              | Specifies the "execution mode" which is used to select between multiple configuration variants.                                                               |
| `--no-dev`                   | Simulate auto-run on Composer installation/update with the `--no-dev` flag.<br />This causes the plugin to check for `$default-no-dev` as the default "mode". |



## Command: `assets-hash`

| Parameter       | Description                                                                                                                                                   |
|-----------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--mode=<mode>` | Sets the "execution mode" which might affect the hash of the assets.                                                                                          |
| `--no-dev`      | Simulate auto-run on Composer installation/update with the `--no-dev` flag.<br />This causes the plugin to check for `$default-no-dev` as the default "mode". |
