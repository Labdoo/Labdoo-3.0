---
name: operations
description: Choose and verify Labdoo deployment, runtime permissions, configuration and operational changes when the user requests them.
---

# Operations and deployment

First identify the checkout, target environment, runtime user and requested scope. Follow [the operations guidance](../../../docs/agent-workflow.md#operations). A push does not authorize deployment, database updates or configuration import.

On branches containing `docs/dev-process/V3CodeDeployment.md`, read it and the corresponding `scripts/deploy-v3-code.sh` before a V3 code-only deployment. That wrapper intentionally does not install dependencies, import configuration or update the database. If the guide or wrapper is absent, do not copy a command from a different branch and assume compatibility.

Public runtime code must be readable by the web runtime user. The V3 permission incident showed that checks as the deployment user can pass while PHP-FPM fails. Validate through the actual runtime when deploying, following the maintained wrapper's checks.

Keep private backup permissions separate from code checkout permissions. Preserve `settings.php`, credentials and private/uploads access controls. The generic `make fix-permissions` recursively changes file modes and is not a safe substitute for diagnosing a production permission failure.

For authorized configuration or schema changes, inspect the exact diff/update hooks and deployment order, establish rollback/backup expectations and validate the affected flow afterwards. Stop on unexpected target state or a failed prerequisite; report the failure before broadening the operation.
