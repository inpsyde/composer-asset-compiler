---
title: Dependencies
nav_order: 5
---

# Dependencies

Before the script is executed, package's dependencies are installed. By default, that means that executing either `npm install` or `yarn`.

This behavior can be customized with the `dependencies` configuration. It can take 3 values:

- `"install"`, which is the default
- `"update"`
- `"none"`

When `"none"` is used, no dependencies are installed at all, and the `script` is executed right away.

When `"update"` is used, dependencies are updated using either `npm update --no-save` or `yarn upgrade`.
