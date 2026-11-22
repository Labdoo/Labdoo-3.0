# SES feedback verification and monitoring

AWS-A12, 22 November 2026. Queue protections and monitoring have been applied;
the audit consumer is prepared but **not activated** on the real queue.
The task explicitly prohibits receiving/deleting real messages during diagnosis.
[Sanitized evidence](../../tests/performance/ses-feedback-20261122.json) records
what was observed and tested. Raw queue URLs, ARNs, policies, identities and
receipts remain private.

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

## Prepared audit worker

[scripts/ses_feedback_worker.py](../../scripts/ses_feedback_worker.py) is a
standalone operational tool, outside Drupal request handling. It never sends
mail, updates Drupal users, adds/removes suppressions or changes preferences.
The default command reads only attributes:

```sh
python3 -B scripts/ses_feedback_worker.py --config /private/path/config.json
```

A private config binds one account, region, queue URL/ARN, expected SNS topic
and absolute ledger path. No config containing live identifiers is committed.
Receiving and acknowledging requires an explicit **`--apply`** flag, which has
only been exercised against temporary test resources in this assessment.

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

The source 30-second visibility still applies. Before activation, measure batch
runtime against this lease and set an appropriate per-receive lease or smaller
batch if needed. Local commit/delete retry safety does not prove adequate
throughput or timely DLQ recovery in a future production workload.

The prepared [service](../../scripts/runtime/labdoo-ses-feedback.service) and
[timer](../../scripts/runtime/labdoo-ses-feedback.timer) are **source templates**.
They are not installed or enabled by the code deployment. The timer waits one
minute after the previous invocation ends, so it does not overlap its own
oneshot service. The service uses a dedicated user, private config/state,
restricted writable path and a post-run CloudWatch PollHeartbeat metric. Native
v3 inspection confirmed Python 3.12.3 and `/usr/local/bin/aws`; local tests used
Python 3.12.3. No PHP dependency compatibility claim is involved.

## Activation decision and rollback

Activation is a separate step because the current card says not to receive or
delete real messages during diagnosis. Before approval, keep the timer absent,
consumer IAM permissions unapplied, and use only the default inspect mode.

A concrete activation would:

1. Confirm no competing consumer on platform or externally and accept the
   audit-only behavior. Existing SES suppression and feedback forwarding stay
   unchanged; this worker will acknowledge valid notifications after saving
   their non-PII audit projection, not retain their complete bodies.
2. Create the dedicated non-login `labdoo-feedback` user and new private
   `/etc/labdoo-ses-feedback` and `/mnt/data/labdoo-feedback` directories. Review
   unexpected existing paths instead of overwriting or changing permissions
   recursively. Install a mode-0600 config and verify source ARN/topic privately.
3. Review the instance role and attach only the additional queue permissions
   generated by `consumer_policy()` in
   [ses_feedback_policy.py](../../scripts/ses_feedback_policy.py): attributes,
   receive and delete for that queue, plus heartbeat writes restricted to
   `Labdoo/SESFeedback`. No mail-send, purge or wildcard SQS permissions. Existing
   role permissions still need review; this does not narrow unrelated grants.
4. Run inspect under the actual service identity, then one explicitly authorized
   real poll. Measure runtime, acknowledge counts and lease behavior; inspect
   the private ledger's aggregate categories and safe logs.
5. Install/enable the reviewed timer. Configure a heartbeat no-data alarm only
   after successful heartbeats are observed: `PollHeartbeat`, namespace
   `Labdoo/SESFeedback`, no dimensions, a threshold below its nonnegative values,
   five one-minute missing periods treated as breaching. This ninth alarm and
   custom metric would add about USD 0.40/month before variable charges. They
   are not created/enabled during this diagnostic task. A heartbeat proves a
   completed poll, not successful processing of every message.
6. Observe backlog, DLQs, worker failures and heartbeat through real traffic,
   then perform a controlled retry exercise without publishing fake bounces to
   the real SES topic or sending real mail.

To stop the future consumer, stop/disable its timer, allow the current invocation
to finish or stop it deliberately, and preserve the private ledger. A message
left in flight becomes visible after its lease; a committed item can safely
replay. Never delete the ledger before replaying a backlog. Do not run independent
SQLite ledgers on two web nodes or assume SQLite on a network filesystem is the
future HA design: a shared transactional deduplication store or dedicated worker
state/recovery plan is required before the proposed web-cluster migration.

To roll back protections, first disable only task-owned alarms, restore the
privately captured original source policy/redrive setting and subscription
redrive setting, then review DLQ contents without consuming them. Do not delete
DLQs containing real feedback. Their eventual retention expiry still applies;
restore/replay is an explicit operation, not part of rollback by default.

## Validation performed

Run `python3 -B tests/operations/ses_feedback_test.py`. Seventeen tests cover
republished notifications, persistence/deletion failures, malformed/untrusted
payloads, private non-PII storage, configuration binding, inspect-only defaults,
source-scoped policies, separate DLQ trust, sparse metrics and proposed IAM scope.

The native controlled test used a temporary SNS topic and three encrypted SQS
queues with no email subscriber. Six valid notifications represented five event
categories plus a repeated Bounce: **five new, one duplicate, six acknowledgments**.
A malformed notification failed processing twice and reached the processing
DLQ intact. A forced SNS client delivery denial reached the separate delivery
DLQ intact. The ledger contained no synthetic recipient address. All temporary
queues, topic and subscription were removed. No real queue message was received
or deleted; no SES mail was sent.

The first native attempt passed worker duplicate handling but its controller
failed while interpreting an empty CLI response during DLQ inspection. Its
receipts are retained privately. The corrected second attempt passed both DLQ
checks and cleanup. Protection application also stopped at a read-only empty-list
preflight before any mutation; normalizing empty CLI responses allowed the
reviewed plan to run. These controller failures are not hidden as passes.

No Drupal behavior, theme or mail-dispatch code changed. Drupal browser, k6 and
Lighthouse suites do not exercise this standalone AWS consumer and were not run;
the operational unit suite and actual isolated SNS/SQS workflow provide the
relevant evidence. Production consumer activation, account-specific fresh
SES-to-SNS delivery, platform's processes and production processing throughput
remain unverified. Publishing these files does not turn those limits into passes.
