---
name: architecture
description: Understand Labdoo entities, custom module boundaries, repositories and services when planning or changing application behavior.
---

# Labdoo architecture

Labdoo coordinates donated devices (dootronics), volunteer transport (dootrips), collection and repair centers (hubs), recipient educational projects (edoovillages), teams and users.

Start with [TECHNICAL.md](../../../TECHNICAL.md), then inspect the affected module's services, routing and entity definitions. Most domain records are node bundles; `mini_wiki` also defines its own entity. Do not infer storage or access rules from a domain name alone.

Custom modules live in `web/modules/custom`; the Labdoo theme lives in `web/themes/custom/labdoo`. Typical domain modules use:

- `src/Service/Repository`: entity queries and persistence.
- `src/Service/Compute`: derived values and business computations.
- `src/Service/Queue/Feeder` and `src/Plugin/QueueWorker`: background work.
- `src/Commands`: Drush operations, including migration tooling.

Some older modules use `src/Services` or other layouts. Follow the affected module's actual contracts rather than moving unrelated code to fit this outline. Inspect service definitions before adding another shared abstraction.

For entity changes, trace presave/insert/update hooks, notifications, queues, search tracking and cache invalidation. Preserve access checks, revision history, translations and relationships. For rendered output, preserve cache tags, contexts and max-age; verify both anonymous and privileged users when visibility differs.
