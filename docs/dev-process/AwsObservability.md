# AWS capacity alerts and audit logging

AWS-A05 extends the existing monitoring without changing application code,
SSH, cache/database endpoints, instance sizes or the deferred encryption migration.
The operational inventory and private AWS receipts were collected on 22 November
2026. The [sanitized result](../../tests/performance/aws-observability-20261122.json)
records the final readback and validation counts.

## Capacity and loss of telemetry

The existing recovery/reboot actions and six DLM recovery alarms are preserved.
One CPU alarm for an instance absent from the live inventory was archived privately
and removed. Thirty-eight new standard-resolution alarms cover both the active
RDS/Redis resources and the encrypted destinations prepared by AWS-A06.

| Signal | Condition | Evaluation |
| --- | --- | --- |
| v3 data filesystem `/mnt/data` | Used space above 85%; separate critical alert above 90% | 3 of 5 five-minute periods |
| v3 and www burst credits | Balance below 20 | 3 of 5 five-minute periods |
| RDS available memory | Below 256 MiB, minimum | 3 of 5 five-minute periods |
| RDS burst credits | Balance below 30, minimum | 3 of 5 five-minute periods |
| Prepared RDS disk / connections | Below 5 GiB free / above 100 connections | 3 of 5 five-minute periods |
| Each Redis node's dataset memory | Above 85%, maximum | 3 of 5 five-minute periods |
| Each Redis node's evictions | More than zero, sum | 1 of 3 five-minute periods |
| Each Redis node's host CPU | Above 80%, maximum | 3 of 5 five-minute periods |
| Each Redis node's burst credits | Balance below 10, minimum | 3 of 5 five-minute periods |
| Each Redis replica's lag | Above 5 seconds, maximum | 3 of 5 five-minute periods |

The existing RDS storage/connections and v3 CPU/root/memory alarms remain active.
Redis memory was approximately 81% during the baseline, without evictions;
85% leaves a warning margin before exhaustion. These limits are operational
starting points, not proof of sufficient capacity for future production load.
No cache contents were flushed and no production load test was run.

Ten distinct telemetry alarms monitor v3 root/data/memory and the health
publisher, both RDS instances and all four Redis nodes. Their real measurements
are nonnegative: a value below -1 cannot breach, while three missing five-minute
periods are treated as breaching. CloudWatch can reuse older real points in its
evaluation range, so this is an approximate 15-minute detector, not a strict SLA.
Capacity alarms use `missing` rather than silently treating absent telemetry as
healthy. All alarms use the exact observed metric dimensions and omit `Unit`
to avoid filtering out native samples.

The existing CloudWatch agent on v3 now collects `/` and `/mnt/data`. Its log
collection settings and other metrics were preserved; only the disk resources changed.
A root-private backup of the prior configuration is on v3 under
`/var/lib/labdoo-observability`. This added one custom metric series.

## Notifications

Existing confirmed email subscriptions are retained. Both existing SNS topics now
explicitly allow the CloudWatch service to publish, restricted by source account
and regional alarm ARN. Other existing policy statements remain intact.
New alarms were created with actions disabled, checked for real data and `OK`
state, then enabled after the controlled delivery check succeeded. They notify;
they do not reboot hosts, recover instances or invoke application jobs.

The controlled check used a temporary custom metric, CloudWatch alarm, SNS topic
and encrypted SQS queue with no email subscriber. Its topic policy reused the
existing topic's grants and the added CloudWatch grant, replacing only the topic
resource. A real alarm transition had to produce a matching CloudWatch message
in that owned queue. Only this temporary queue was read. Temporary test resources
were removed afterwards. No test message was published to production topics.
This verifies the service chain and policy pattern; it does not prove delivery
into a recipient's email inbox. Subscription confirmation is metadata evidence.

## Retention and durable audit trail

The two existing log groups without retention now retain 90 days; three groups
already at 30 days remain unchanged. Applying retention allows AWS to expire older
events; there was no manual deletion of historical logs.

A single account trail records read/write management events across enabled AWS
regions and global services. Data events, Insights and CloudTrail Lake are not
enabled. It uses a dedicated private S3 bucket in Frankfurt with public access
blocked, bucket-owner-enforced ownership, default AES256 encryption, an HTTPS-only
bucket policy and CloudTrail delivery grants scoped to the exact trail ARN/account.
Management log/digest objects expire after 365 days. Log-file validation is
enabled. Management log delivery and sampled account provenance were checked. The first nonempty signed digest had not arrived at
the final check; `validate-logs` reported no digests and its zero exit status
is **not** counted as a cryptographic verification pass. Verify the first
digest after CloudTrail produces it.
This is an account trail, not certification of other Organizations accounts.

WAF logs are delivered to a private CloudWatch log group in us-east-1 with
30-day retention. The logging filter retains only `BLOCK` actions and drops all
other traffic. Authorization, Cookie and the origin-verification header, plus
query strings, are redacted. Client IP addresses, URI paths and other request
metadata can still contain personal information; access and retention remain
restricted. WAF sampled requests have separate behavior and were not changed.
WAF logging is best-effort, not a guarantee of every request being recorded.
Full CloudFront access logging remains disabled to avoid duplicating all traffic
and retaining cookies/query strings; CloudFront metrics and blocked-request WAF
logs provide the selected coverage. No WAF protection rule was weakened.
Three inert unauthenticated GET probes
returned 403; a correlated blocked-request record demonstrated redaction of
all supplied synthetic credentials and query markers. The probes contained
no real credentials and caused no authenticated application writes.

## Cost and migration considerations

The AWS Price List API returned Frankfurt prices of USD 0.10 per standard alarm
metric-month and USD 0.30 per added custom metric-month. Thirty-eight new alarms,
minus one retired alarm, and one custom series therefore add approximately
**USD 4.00/month**, before free allowances, taxes and variable API/SNS usage.
Native service metrics do not add custom metric fees. The first copy of CloudTrail
management events has no CloudTrail delivery charge, but S3 requests/storage do.
WAF log ingestion/storage and API usage are variable; blocked-only filtering and
retention reduce volume but do not impose a monetary cap. No claim of zero total
logging cost or guaranteed future invoice is made.

The prepared RDS/Redis resources incur their existing migration costs separately;
this task did not create, delete, promote or redirect traffic to those targets.
AWS-A06's migration must update alarm dimensions if identifiers change at switchover
and retire destination/source alarms when the corresponding resource is retired.

Platform has native EC2 monitoring but no managed guest access in this session;
its RAM/filesystem telemetry is not certified. No new guest agent was installed
on www or platform. This task adds the requested v3 data coverage and native
RDS/Redis/credit coverage; it does not claim complete guest-level observability
on every host. Guest access and any installation must be coordinated with the
[deferred migration](https://app.todoist.com/app/task/6j4Gw5VjgfxQVP4V), without
changing SSH as part of this work.

## Validation and rollback

The pure builders in [aws_observability_policy.py](../../scripts/aws_observability_policy.py)
make no AWS calls. Run their notification, scope and confidentiality checks with:

```sh
python3 -B tests/operations/aws_observability_test.py
```

Native readbacks and controlled tests complement these checks: actual data on the
new filesystem series, all new alarms evaluated/enabled, original recovery actions
preserved, isolated delivery, trail configuration/delivery and WAF log redaction.
See the sanitized result for exactly which delivery/integrity checks completed.
No application, SQL, test-runner or browser behavior was changed, so Playwright,
k6, Lighthouse and PHP quality suites are not substitutes for these AWS checks
and were not rerun for this task. An unresolved Redis-report HTTP500 from AWS-A06's
local E2E run remains tracked in the deferred migration; this task does not certify
that application flow as fixed.

Rollback uses the private before/spec receipts, never an account-wide reset:
restore only the v3 agent configuration; disable/delete only this task's new
alarms; restore the archived stale alarm only if deliberately needed; restore
only the topic-policy statements changed here; and restore prior retention
settings if necessary (expired data cannot be recreated). WAF logging can be
disabled without changing protection rules. CloudTrail can be stopped without
deleting its existing audit records. Do not delete audit buckets or retained
logs as a routine rollback. Reverting repository files alone does not revert AWS.

## Primary references

- [CloudWatch agent configuration](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/CloudWatch-Agent-Configuration-File-Details.html)
- [ElastiCache metrics to monitor](https://docs.aws.amazon.com/AmazonElastiCache/latest/dg/CacheMetrics.WhichShouldIMonitor.html)
- [CloudWatch pricing](https://aws.amazon.com/cloudwatch/pricing/)
- [CloudTrail pricing](https://aws.amazon.com/cloudtrail/pricing/)
- [CloudTrail bucket policy](https://docs.aws.amazon.com/awscloudtrail/latest/userguide/create-s3-bucket-policy-for-cloudtrail.html)
- [WAF log filtering and redaction](https://repost.aws/knowledge-center/waf-turn-on-log-filtering)
