---
title: Parallel Assets Processing
nav_order: 14
---

# Parallel Assets Processing

_Composer Assets Compiler_ processes multiple packages in parallel to speed up the process.

Note that only the packages' `script` is executed in parallel, the _installation_ of the dependencies is executed in series, because package managers fail doing parallel installation due to multiple processes trying to write the same cache files at the same time.

_Composer Assets Compiler_ parallel execution implementation is quite simple and works by starting different processes, whose status (completed, failed, running) is then checked at regular intervals.

The number of processes that run concurrently can be controlled by the `max-processes` configuration and the interval by the `processes-poll` configuration.

Using a `max-processes` value that matches the number of system CPUs can increase performance, provided there is also sufficient memory.
