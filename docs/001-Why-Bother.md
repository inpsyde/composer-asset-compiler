---
title: Why Bother
nav_order: 2
---

# Why bother

When installing Composer project's dependencies that have both PHP and frontend code we have to make a choice:

- put all packages' frontend assets under version control
- "compile" assets after packages are pulled by Composer

Both the options are **not** ideal.

In the first case, we have to put care that the pushed compiled assets are actually the compiled version of the pushed "sources".

In the second case, a lot of manual work is required, and in the case of transitive dependencies we might even don't know that there are assets to compile.

_Composer Assets Compiler_ solves the problem by using the following workflow:

1. Each package contains a configuration, e. g. the _npm_ or _Yarn_ script to run to compile assets.
2. After the "project" is installed via Composer, the plugin loops all the installed dependencies, looking for that configuration.
3. All found packages are "compiled", by first installing the frontend dependencies (with *npm* or *Yarn*) and then executing the script.

That means the first step to enable the workflow is to add _Composer Assets Compiler_ configuration in the packages.
