---
name: queue-philosophy
description: Change Labdoo queue feeders, payloads and workers while preserving retry behavior, deduplication and cache invalidation.
---

# Queue contracts

Heavy or asynchronous derived work commonly uses feeders and Drupal queue workers. Inspect the producer, payload format, consumer and runner together before changing a queue.

The `queue_manager` helper uses `QueueDataModel` for its envelope. Other workers use raw arrays, including cache invalidation queues. Preserve the existing contract; do not require an envelope for every queue or change payload format without handling already queued items.

Use `QueueHelper::enqueueData` where that module's helper is already the contract. Inspect its actual deduplication behavior; do not assume enqueueing is atomic or duplicates are impossible.

Workers must tolerate repeated delivery, missing/deleted entities and stale items. Distinguish retryable provider failures from terminal payload errors; follow the runner's actual retry and lease semantics. Avoid uncontrolled re-enqueue loops and preserve provider quotas.

For cache recomputation, identify affected entity/list/neighbor tags and ensure follow-up invalidation remains consistent. Test a repeated item and the relevant failure path in an isolated queue. Processing queues can send email, call providers and save entities: apply the [environment rules](../../../docs/agent-workflow.md#environment-and-side-effects).
