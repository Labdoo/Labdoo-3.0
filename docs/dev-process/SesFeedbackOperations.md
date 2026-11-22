# SES feedback verification and monitoring

AWS-A12, 22 November 2026. The owner explicitly approved real notification
processing after the diagnostic phase. The audit consumer and timer are now
**active on v3**, with scoped permissions and heartbeat supervision.
[Activation evidence](../../tests/performance/ses-feedback-activation-20261122.json)
records the current running state; the
[initial diagnostic snapshot](../../tests/performance/ses-feedback-20261122.json)
retains the earlier observations and its then-inactive consumer status. Raw queue
URLs, ARNs, policies, identities and receipts remain private.

## What this queue represents

The regional SES configuration set publishes Send, Delivery, Bounce, Complaint
and Reject notifications to SNS and CloudWatch. Both verified identities have
that default configuration set and email feedback forwarding enabled. The
account is production-enabled and HEALTHY, with automatic account-level
suppression for BOUNCE and COMPLAINT already enabled. VDM remains enabled.
None of those account, sender or delivery settings was changed by this task.

The SNS subscription's SQS queue contains **event notifications**, not queued
outgoing emails. Drupal's contributed `amazon_ses_mail_queue` worker sends mail
from Drupal's own queue through SES; it is not an SQS feedback consumer. The
existing `scripts/supervisor-report.sh` reads queue attributes and does not drain
this notification queue. Enabling suppression in SES is not the same as updating
Drupal subscriptions or proving a feedback email reached its mailbox.
[AWS documents the notification methods](https://docs.aws.amazon.com/ses/latest/dg/monitor-sending-activity-using-notifications.html)
and [account suppression behavior](https://docs.aws.amazon.com/ses/latest/dg/sending-email-suppression-list.html).

## Findings and limits

Metadata initially showed one SQS queue, one confirmed SNS-to-SQS subscription,
two separate alert-email subscriptions, and no Lambda functions/event-source
mappings, EventBridge rules or Scheduler schedules in the reviewed region.
Read-only checks of 37 candidate systemd/cron/supervisor/local-binary files on
each of v3 and www, plus running-process metadata, found no matching consumer.
Platform guest access remains unavailable, and an external consumer cannot be
ruled out. No universal absence claim is made.

The 30-day window 23 October 18:00–22 November 18:00 UTC contains:

- SNS: 14 published, 14 delivered, zero failed notifications observed in four
  hourly samples.
- SQS: 14 sent, zero received/deleted/empty receives observed in 399 hourly
  samples of each series. This is not 720 samples of continuous monitoring.
- Maximum historical approximate age: 432,013 seconds, versus configured
  retention 345,600 seconds (four days). Approximate ages, aggregation and
  expiration cannot reconstruct individual message histories.
- Current source attributes: zero visible/in-flight/delayed messages. This does
  not prove the former messages were handled, nor prove outgoing mail was lost.

SQS metrics can stop when a queue is inactive. A `Complete` API status applies to
returned data, not every missing hour. Missing metrics are not evidence that the
consumer works. No real payload was received to classify those 14 events.
[AWS describes SQS metric caveats](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/sqs-available-cloudwatch-metrics.html).

## Protections applied

The source queue keeps its original four-day retention, 30-second visibility
and SNS envelope format. SSE-SQS encryption remains enabled. The existing
source-topic/account conditions are retained, with an explicit SNS service
principal replacing the wildcard principal in the reviewed send grant. A scoped
TLS deny excludes AWS service principals whose transport context can be redacted.
The SES topic policy and publishing configuration were preserved.

Two distinct encrypted dead-letter queues (DLQs), each retaining messages for
14 days, cover different failures:

- **Delivery DLQ:** attached to the SNS subscription for notifications that SNS
  cannot deliver to the source queue. Its send grant is scoped to the source
  topic and account.
- **Processing DLQ:** the source SQS redrive target after five receive attempts.
  Its redrive allow policy accepts only this source queue. It does not create a
  consumer or rescue messages that expire without ever being received.

DLQ bodies can contain mail addresses and headers. Encryption does not make
them public or suitable for Git/logs. There is no automatic replay or purge.
Investigate privately, repair the cause, and explicitly authorize replay after
checking duplicate handling and retention.
[AWS SNS DLQ guidance](https://docs.aws.amazon.com/sns/latest/dg/sns-dead-letter-queues.html)
explains the subscription-level distinction.

Eight new standard-resolution CloudWatch alarms notify through the existing
confirmed operational SNS route; the previous 57 alarm configurations were
preserved:

| Alarm | Trigger |
| --- | --- |
| Source age | At least 900 seconds, three one-minute periods |
| Source backlog | At least one visible message, three five-minute periods |
| Delivery DLQ | At least one visible message, one one-minute period |
| Processing DLQ | At least one visible message, one one-minute period |
| SNS delivery failure | At least one failure summed over five minutes |
| SNS DLQ redrive failure | At least one failure summed over five minutes |
| SES reputation bounce rate | At least 0.05, five-minute maximum |
| SES reputation complaint rate | At least 0.001, five-minute maximum |

All eight treat missing data as not breaching because idle traffic is sparse;
`OK` is not a consumer heartbeat. Thresholds apply to the named service metrics,
not a naive ratio of the few observed sends. AWS recommends maintaining
[bounces below 5% and complaints below 0.1%](https://docs.aws.amazon.com/ses/latest/dg/success-metrics.html).
Alarms notify; they neither send application mail nor change recipients.

Eight standard alarms add approximately **USD 0.80/month** before allowances,
tax and variable API/SNS/SQS/storage charges. No new fixed-size compute or paid
mail test was introduced. The native regional price inventory contains the
standard USD 0.10/alarm-month rate;
[CloudWatch pricing](https://aws.amazon.com/cloudwatch/pricing/) is the reference.

## Audit worker

[scripts/ses_feedback_worker.py](../../scripts/ses_feedback_worker.py) is a
standalone operational tool, outside Drupal request handling. It never sends
mail, updates Drupal users, adds/removes suppressions or changes preferences.
The default command reads only attributes:

```sh
python3 -B scripts/ses_feedback_worker.py --config /private/path/config.json
```

A private config binds one account, region, queue URL/ARN, expected SNS topic
and absolute ledger path. No config containing live identifiers is committed.
Receiving and acknowledging requires an explicit **`--apply`** flag. After
owner approval, systemd invokes that mode against the real source queue. Observed
real polls were successful and empty; no live notification was available to
acknowledge. Synthetic notifications were tested separately, without mail sends.

The decoder accepts the configured event types in an SNS notification envelope,
checks its topic, and hashes canonical SES JSON independently of the outer SNS
MessageId. Identical SES events republished by SNS therefore share a key. The
SQLite ledger stores only fingerprint, category and first-seen time, with a
unique key and a committed transaction **before** DeleteMessage. Parsing or
persistence failure leaves the item unacknowledged. Delete failure after commit
replays as a duplicate. No message bodies, mail IDs or addresses are stored or
printed. This provides an audit projection, not per-recipient remediation or
full semantic deduplication of differently represented events.

The transport trust boundary is the protected SQS subscription plus its scoped
IAM and queue policy. This is not an SNS HTTP webhook/signature verifier and
must not be exposed as one. Publishing to the authorized topic remains a
privileged operation. Malformed/unsupported events are left for redrive; the
worker does not silently discard them.

The source retains its global 30-second visibility setting. This worker requests
a **300-second per-receive lease**, validated within SQS limits, longer than the
180-second service timeout. The first real empty poll took about 22.5 seconds.
A failed batch exits unsuccessfully after printing safe counters, preventing
ExecStartPost from publishing a success heartbeat. A process stopped after a
commit can retry after the lease without incrementing the audit projection twice.
[AWS visibility guidance](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/sqs-visibility-timeout.html)
explains the processing-time and retry tradeoff. This does not certify future
production throughput or eliminate SQS at-least-once duplicate delivery.

The [service](../../scripts/runtime/labdoo-ses-feedback.service) and
[timer](../../scripts/runtime/labdoo-ses-feedback.timer) were installed explicitly
under `/etc/systemd/system` after approval and native systemd validation. Their
bytes match the repository. The timer is enabled and active. Code deployment
alone still does not install or activate units. The timer waits one
minute after the previous invocation ends, so it does not overlap its own
oneshot service. The service uses a dedicated user, private config/state,
restricted writable path and a post-run CloudWatch PollHeartbeat metric. Native
v3 inspection confirmed Python 3.12.3 and `/usr/local/bin/aws`; local tests used
Python 3.12.3. No PHP dependency compatibility claim is involved.

## Activation record and rollback

The original task prohibited real ReceiveMessage/DeleteMessage during diagnosis.
The owner subsequently answered **yes** to activating the prepared audit worker
and retiring valid notifications after their non-PII audit record is committed.
That authorization covers the running service below. It does not authorize
changing Drupal preferences or SES suppression rules.

Activation completed with these checks:

1. A dedicated non-login `labdoo-feedback` user, mode-0700 config/state directories
   and mode-0600 config and SQLite ledger were created. Existing unexpected paths
   were checked before creation; no recursive permission change was made.
2. The additional instance-role policy grants GetQueueAttributes, ReceiveMessage
   and DeleteMessage for the source queue, plus namespace-scoped heartbeat writes.
   Native IAM simulation allows those source operations and denies source
   SendMessage/PurgeQueue and processing-DLQ ReceiveMessage/DeleteMessage. The
   original inline policy and managed-policy attachments were retained. The
   actual service user verified its instance-role identity and inspected the
   queue successfully; no static AWS credentials were installed.
3. The reviewed worker was deployed to v3. Systemd verified the service/timer;
   the first actual service invocation completed successfully, created only the
   expected ledger columns and reported no messages. Repeated scheduled polls
   and CloudWatch heartbeat samples were observed. A oneshot service can be
   inactive between polls while its timer remains active; inspect the timer and
   last Result/ExecMainStatus rather than treating this idle state as a failure.
4. The ninth alarm, `LabdooFeedbackPollNoData`, watches PollHeartbeat in
   `Labdoo/SESFeedback`, no dimensions, Minimum below zero, five one-minute
   missing periods treated as breaching. Before the first poll it reached ALARM
   with notification actions disabled. Actual successful polls produced metrics
   and returned it to OK; only then were notifications enabled. CloudWatch can
   reuse older datapoints, so five periods is not a guaranteed exact five-minute
   detection time. Failed batches do not publish the success heartbeat; a
   successful empty poll still does not prove every future message will process.
5. The previous 65 alarm configurations were preserved. SES sending, account
   suppression, feedback forwarding and VDM were not modified. Existing queue
   retention, global visibility, transport protections and separate DLQs remain.

The initial eight alarms cost about USD 0.80/month; the heartbeat metric and
alarm add about USD 0.40/month, for approximately **USD 1.20/month** before
allowances, tax and variable API/SNS/SQS/storage usage. No new EC2 was created.

Platform/external consumer discovery remains incomplete. No competing consumer
was observed in the reviewed hosts or regional metadata, and historical metrics
showed no receives; neither is a universal exclusion proof. This audit worker
must remain the single owner of its local ledger when web nodes are replaced.
Do not publish synthetic bounces into the real SES topic or send mail merely to
produce a production test event.

To stop the consumer, stop/disable its timer, allow the current invocation
to finish or stop it deliberately, and preserve the private ledger. A message
left in flight becomes visible after its lease; a committed item can safely
replay. Never delete the ledger before replaying a backlog. Do not run independent
SQLite ledgers on two web nodes or assume SQLite on a network filesystem is the
future HA design: a shared transactional deduplication store or dedicated worker
state/recovery plan is required before the proposed web-cluster migration.

To revoke the consumer access, stop its timer/service first and remove only the
added `LabdooSesFeedbackConsumer` inline policy after reviewing in-flight work.
Preserve the original role policies and private ledger. During an approved
shutdown, explicitly manage the task-owned heartbeat alarm notification state;
otherwise its missing-heartbeat alert is expected and useful.

To roll back protections, first disable only task-owned alarms, restore the
privately captured original source policy/redrive setting and subscription
redrive setting, then review DLQ contents without consuming them. Do not delete
DLQs containing real feedback. Their eventual retention expiry still applies;
restore/replay is an explicit operation, not part of rollback by default.

## Validation performed

Run `python3 -B tests/operations/ses_feedback_test.py`. Nineteen tests cover
republished notifications, persistence/deletion failures, malformed/untrusted
payloads, private non-PII storage, configuration binding, inspect-only defaults,
source-scoped policies, separate DLQ trust, sparse metrics, scoped IAM, lease
validation and unsuccessful batch exits.

The native controlled test used a temporary SNS topic and three encrypted SQS
queues with no email subscriber. Six valid notifications represented five event
categories plus a repeated Bounce: **five new, one duplicate, six acknowledgments**.
A malformed notification failed processing twice and reached the processing
DLQ intact. A forced SNS client delivery denial reached the separate delivery
DLQ intact. The ledger contained no synthetic recipient address. All temporary
queues, topic and subscription were removed. The controlled test did not use the
real source queue and sent no SES mail.
The separately approved real service was then polled with its actual identity;
its observed batches were empty, so no live notification was acknowledged.

The first native attempt passed worker duplicate handling but its controller
failed while interpreting an empty CLI response during DLQ inspection. Its
receipts are retained privately. The corrected second attempt passed both DLQ
checks and cleanup. Protection application also stopped at a read-only empty-list
preflight before any mutation; normalizing empty CLI responses allowed the
reviewed plan to run. These controller failures are not hidden as passes.

No Drupal behavior, theme or mail-dispatch code changed. Drupal browser, k6 and
Lighthouse suites do not exercise this standalone AWS consumer and were not run;
the operational unit suite and actual isolated SNS/SQS workflow provide the
relevant evidence. Activation, the actual service identity, real empty polls and
heartbeat supervision have been verified. Account-specific fresh SES-to-SNS mail delivery,
processing of a naturally arriving live notification, platform's processes and
production processing throughput remain unverified. Publishing these files does
not turn those limits into passes.
