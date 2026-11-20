# AWS infrastructure audit — 20 November 2026

The application changes requested in this queue are deployed at code revision
`748f4667c421bf3941f18dd879ab759a49cdc97a`. This subsequent infrastructure audit
found capacity headroom, but also security, recovery and monitoring work that
prevents describing the account as fully configured or production resilient.
No infrastructure remediation was performed. The twelve linked follow-up
cards below remain pending; completing an audit does not complete those fixes.

## Scope, method and evidence

Collection used AWS CLI from the user's workstation, profile `labdoo`, in the
authenticated account, across all 17 enabled regions and applicable global
services. Organizations metadata was accessible but `ListAccounts` was denied:
other organization accounts and their effective SCPs were not certified.
Account IDs, resource IDs, IP addresses, IAM names, credentials and origin
header values are intentionally excluded from published evidence.

The [sanitized aggregate](aws-infrastructure-audit-20261120.json) contains the
regional inventory, cost periods, hourly metric statistics, operation outcomes
and Todoist action references. Raw evidence remains under the ignored private
`tests/performance/.local/aws-audit-20261120/` directory. Collector files use
0600 permissions and directories 0700. No raw evidence is part of this release.

The collector recorded 1,173 operations: 1,135 successful, 17 unsupported by the
local CLI model, one access denial and 20 expected absent configurations.
These records are not a count of HTTP requests: pagination and CLI retries can
make multiple requests. The absent configurations are 17 Security Hub hubs,
the IAM custom password policy, S3 account public-access block and WAF logging.
These are findings or hardening opportunities, not 20 permission failures.

AWS CLI is 1.22.34, botocore 1.34.46 and Python 3.12.3. Account-level IMDS
defaults and the newer RDS major-version lifecycle operation are unavailable
in this CLI model; per-instance IMDS settings and regional RDS engine/upgrade
metadata were inspected instead. A quota evidence filename collision was
corrected with a separately labelled EC2 query; both quota results are retained.
Initial CLI compatibility failures were retained and corrected before inventory.

Supplemental read-only SSM diagnostics inspected v3 services, filesystem,
memory, package metadata, backup metadata and existing database transport.
Repository-owner and generated PHP quoting mistakes in early diagnostics were
corrected; those attempts failed before the intended diagnostic completed.
Successful receipts are `f8d8aede-cfa8-4b67-86ff-4f36cdfd2f64` (host/provenance),
`3d502fc9-f430-42a1-b9a4-5cb0448795ae` (backup/certificate metadata), and
`88321d58-10c5-4113-9081-9a34686468fd` (database transport). Python used `-B`;
no server source or Git configuration was changed by these diagnostics.

IAM credential-report generation created diagnostic metadata, as permitted by
[AWS IAMReadOnlyAccess](https://docs.aws.amazon.com/aws-managed-policy/latest/reference/IAMReadOnlyAccess.html).
The report was current at 21:07:59 UTC. No keys were retrieved or rotated.
No secret/parameter values, user data, queue messages or log message contents
were retrieved. No resize, deletion, upgrade, restart, failover, new service,
production load test or real email was executed.

Representative read-only reproduction commands are below. Resource-specific
queries used private identifiers discovered from inventory; do not paste their
raw output into public issues. CloudWatch uses the exact observed metric
dimensions, hourly period and timestamps above; no invented RAM dimensions.

```sh
aws --profile labdoo --region eu-central-1 ec2 describe-regions
aws --profile labdoo --region eu-central-1 ec2 describe-instances --query 'Reservations[].Instances[].{Type:InstanceType,State:State.Name,AZ:Placement.AvailabilityZone,IMDS:MetadataOptions.HttpTokens}'
aws --profile labdoo --region us-east-1 ec2 describe-volumes --query 'Volumes[].{Size:Size,State:State,Type:VolumeType,Encrypted:Encrypted}'
aws --profile labdoo --region eu-central-1 rds describe-db-instances --query 'DBInstances[].{Engine:Engine,Version:EngineVersion,Class:DBInstanceClass,MultiAZ:MultiAZ,Public:PubliclyAccessible,Encrypted:StorageEncrypted,Retention:BackupRetentionPeriod}'
aws --profile labdoo --region eu-central-1 cloudwatch describe-alarms --query 'MetricAlarms[].{Namespace:Namespace,Metric:MetricName,State:StateValue}'
aws --profile labdoo --region us-east-1 ce get-cost-and-usage --time-period Start=2026-08-01,End=2026-11-20 --granularity MONTHLY --metrics UnblendedCost AmortizedCost --group-by Type=DIMENSION,Key=SERVICE
```

## Inventory and coverage

| Area | Observed state | Scope or verification limit |
| --- | --- | --- |
| EC2 web | Three running instances in eu-central-1, all in one AZ: www t2.micro, platform m4.2xlarge, v3 t2.large | Only v3 is SSM-managed; other hosts' OS/services/RAM/filesystem were not inspected |
| EBS | Five attached volumes, 328 GiB, eu-central-1; one unattached gp2 volume, 64 GiB, us-east-1 | All six report AWS encryption disabled; guest filesystem encryption was not established |
| Images/backups | 20 completed EBS snapshots, seven AMIs, two enabled DLM policies | One policy selects one current volume; the other selects none; no AWS Backup plans or restore jobs found |
| Database | One private RDS MariaDB 10.6.25 db.t3.medium, Multi-AZ, 50 GiB | v3's existing Drupal connection was confirmed to use RDS |
| Redis | Two Redis OSS 7.0.7 cache.t3.medium nodes, primary/replica, Multi-AZ/failover enabled | Seven-day snapshot retention; detailed recovery was not exercised |
| Edge | One CloudFront distribution for v3, one global WAF ACL, ACM certificate issued/renewal eligible | Viewer TLS minimum TLSv1.2_2021; HTTPS-only origin with TLS1.2; certificate configuration is not a public browser test |
| Storage/DNS | No S3 buckets or Route53 hosted zones in this account | External DNS, other accounts and external backups are outside demonstrated coverage |
| Other workloads | No ALB/NLB/classic ELB, ASG, Lambda, ECS/EKS clusters, EFS, ECR repositories, DynamoDB tables or OpenSearch domains found | Enumerated services across enabled regions; this is not an assertion that every possible AWS service is unused |
| Messaging | SNS topics, one SQS queue and SES production configuration inspected | No messages consumed, delivery notifications sent or secrets read |
| Observability/security | CloudWatch, log groups, IAM, KMS, ACM, CloudTrail/Lake, Config, GuardDuty, Security Hub and Access Analyzer metadata inspected | No trails/Lake stores, Config recorders, GuardDuty detectors or Access Analyzers visible; these services have distinct cost/coverage tradeoffs |
| Quotas | EC2 standard on-demand vCPU quota 1,920; RDS instance quota 40, storage quota 100,000 GiB | Current three EC2 instances use 11 vCPUs and one RDS instance; no demonstrated immediate quota bottleneck |

All enabled regions are listed in the aggregate, including those without
application workloads. The us-east-1 unattached EBS volume is included: inventory
was not limited to resources carrying application tags or the default region.
Compute Optimizer enrollment is inactive; no recommendations from that service
were claimed or enrollment enabled. Four KMS keys are AWS-managed and enabled;
there are no customer-managed keys in the returned inventory. Parameter metadata
and Secrets Manager listings found no stored parameters/secrets in this account;
that does not establish that credentials are absent from hosts or other systems.

## Capacity and sizing

CloudWatch window: **21 October 2026 21:00 to 20 November 2026 21:00 UTC**,
period 3,600 seconds. Principal EC2/RDS/Redis series have 720 hourly points.
CPU percentiles below are percentiles of hourly **Average**, not instantaneous
CPU or request latency. Peak columns use the maximum of hourly **Maximum**.
Basic EC2 monitoring remains enabled; detailed monitoring is disabled.

| Resource | CPU mean / p95 hourly average | Observed peak | Decision supported by this evidence |
| --- | --- | --- | --- |
| www t2.micro | 6.46% / 12.47% | 100%; CPU-credit minimum zero in 15 of 720 hours | Investigate credit pressure and actual burst duration before retaining/changing class; RAM is unmeasured |
| platform m4.2xlarge | 12.17% / 21.03% | 100%; p95 hourly CPU maxima 99.69% | Keep capacity pending workload investigation; low mean does not exclude saturation; active reservation affects savings |
| v3 t2.large | 2.90% / 3.31% | 81.24%; credits minimum 861.74 | No evidence for expansion now; downsizing requires a representative concurrency/memory test |
| RDS db.t3.medium | 3.87% / 4.09% | 62.75%; credits minimum 574.72 | Low CPU is insufficient reason to shrink: minimum freeable memory is about 394 MiB |
| Redis primary | 2.60% / 2.74% | Host CPU14.15%; engine CPU4.78% | Preserve node memory and replica; review memory trend and maintenance rather than CPU-only downsizing |

v3 CWAgent memory has p95 hourly maxima 10.84% and observed maximum 39.80%.
That maximum is approximately 3.1 GiB on its roughly 7.75 GiB reported memory:
a move to a smaller instance needs headroom, worker/concurrency tuning and a
rollback test. The live host snapshot had 7.09 GB available out of 8.32 GB RAM.
Swap contained 480 MB, which alone does not demonstrate current swap pressure.

The historical disk metric covers **only the root filesystem** (observed
maximum 43.55%). Live `df` showed root at 44% but **/mnt/data at 80%**, with
24.08 GB available. The latter is not represented by that historical root alarm.
The collection therefore cannot establish a data-volume growth rate. Alert on
the correct mount and inspect retention before deciding cleanup or expansion.

EBS hourly average queue-length maxima are 0.409 for platform data and 0.163 for
v3 data, with much lower p95 values. Platform data p95 hourly total read+write
IOPS is not an instantaneous peak; individual sum series are retained in the
aggregate so their units remain explicit. No storage performance upgrade is
justified from these aggregates alone. The unattached volume has no observed
metric samples; absence of samples is not evidence that its data is disposable.

RDS has at least 38.08 GiB free storage in the window, at most two observed
connections in an hourly maximum, write-latency p95 about 2.8 ms and ample CPU
credits. Preserve Multi-AZ and validate memory/connection behavior under the
expected production workload before changing its class.

Redis primary memory usage averages 83.25%, p95 86.85%, maximum 90.01%; there
are **zero evictions** in both nodes' 720 hourly sum samples. Primary read hit
ratio is approximately 96.06% from 10,733,548 hits and 440,213 misses; replica
lag maximum is 7 ms. Policy is volatile-lru and reserved-memory-percent 25.
These observations improve on the historical near-full-cache concern and do
not justify an emergency increase or a smaller-memory replacement.
[AWS metric definitions](https://docs.aws.amazon.com/AmazonElastiCache/latest/dg/CacheMetrics.Redis.html)
distinguish dataset memory, eviction counts and replication lag; cache memory
usage alone is not proof of a failing service.

CloudFront recorded 1,590,720 requests. Aligning hourly request counts with
hourly average error percentages gives an approximate request-weighted 4xx
rate of 71.17% and 5xx rate of 0.119%; maximum hourly 5xx is 18.71% on
9 November at 14:00 UTC. This is a historical signal requiring investigation,
not proof that the current release fails: Basic Auth, WAF, bots and origin
responses cannot be separated by these metrics without request-level logs.
An unweighted mean of hourly percentages would substantially misstate the
overall 4xx rate; it must not be presented as the request-weighted rate.

## Security, recovery and operations

All three public EC2 instances have an attached SG permitting IPv4 TCP/22
from anywhere and an internet-gateway route; inspected NACLs have default
allow rules. This confirms a broad network policy, not the authentication
strength or an exploited host. v3 also permits public 80/443. Adding a
CloudFront prefix-list rule does not narrow an existing world-open rule.
Server-level authentication/origin restrictions were not comprehensively
audited, so successful direct-origin bypass is not claimed. www/platform
allow optional IMDSv1; v3 requires IMDSv2. Account metadata defaults could not
be read with this local CLI. Require tested access/compatibility before
tightening SSH, origin rules or metadata policy.

RDS is private, restricted to the v3 security group, and retains automated
backups/PITR for 14 days. The returned restore window was 6–20 November,
latest 21:10 UTC on the audit date; recent automated snapshots were available.
However, RDS storage/backups and every EBS volume report encryption disabled.
Read-only SQL through the existing Drupal connection confirmed
`require_secure_transport=OFF` and an empty session `Ssl_cipher`: that specific
database session was not using TLS. Redis has at-rest/transit encryption
disabled, no auth token and no user groups, with network access limited to
v3's SG. These findings require staged data-protection changes, not opening
data services publicly or abruptly requiring TLS before clients are ready.

RDS storage encryption requires a supported migration path rather than an
assumed in-place toggle. Use a tested encrypted copy/restore or other currently
supported migration design, preserve recovery and plan writes at cutover.
[AWS RDS encryption guidance](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Overview.Encryption.html)
describes the resource and migration constraints. No guest-level encryption or
external backup coverage was certified by this audit.

The v3 **120 GiB data volume has no EBS snapshots** in the collected inventory;
the 20 GiB root volume has seven. Two recent local backup locations were
observed, but both share the data filesystem. One DLM policy selects no current
volume. No AWS Backup restore-job evidence was found and no new restore was
performed. Root cron has backup-related jobs; keyword inspection of jobs does
not prove independent retention or a working restore. An off-volume copy and
isolated restore test are required before claiming full v3 recovery.

Root has MFA and no access keys. Four IAM users, one group and 13 roles were
inspected; the only console user has MFA. One user has AdministratorAccess and
three active keys are over 90 days old, oldest approximately 3,540 days.
Last-use metadata includes EC2, SES and one unavailable service-use record;
unavailable API last-use information does not prove an SMTP key is unused.
No compromise is inferred from age. Rotate with consumer identification and
mail/deployment continuity; evaluate effective permissions with policy
conditions, boundaries and currently inaccessible organization constraints.

CloudFront viewer configuration is current TLS and HTTPS origin transport;
ACM is issued and renewal eligible. The origin certificate inspected on v3
expires 8 January 2027. Apache/PHP-FPM are running and no systemd units are
failed. v3 has roughly one hundred apt upgrade entries, current package
metadata and `reboot-required`; this is maintenance evidence, not a CVE scan.
SSM patch-state history is empty. Redis has seven unapplied update actions:
five past recommended dates and two due 5/7 December. Applicability and
supersedence need review before scheduling maintenance.

RDS 10.6.25 remains an available version in the regional API. AWS currently
lists **31 December 2026** as the end of standard support for MariaDB 10.6;
10.6.28 is a newer minor version but shares that major-version deadline.
Choose and test the major upgrade path before that date. This audit does not
describe 10.6.25 as already out of support.
[Official RDS MariaDB lifecycle](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/MariaDB.Concepts.VersionMgmt.html).

WAF has managed common, reputation and Bot Control groups plus rate rules,
but earlier terminal Allow rules cover media/AJAX routes and a test IP set.
The IP set contains one address and no world CIDR. These exceptions must be
reviewed for scope because [Allow stops later WAF evaluation](https://docs.aws.amazon.com/waf/latest/developerguide/web-acl-rule-actions.html).
This is separate from the existing remote-browser false-positive task; no
exploit test, broad allowlist addition or Bot Control disabling was performed.

All five CloudFront behaviors, including static-file paths, currently use
Managed-CachingDisabled with all TTLs zero. That policy prevents edge caching,
even though browsers may use their own Cache-Control behavior.
[AWS managed-policy documentation](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/using-managed-cache-policies.html).
A public-static-only cache design should preserve authenticated pages, private
files, query/version semantics, languages and deployment invalidation.

There are 14 metric alarms: 13 OK and one INSUFFICIENT_DATA referring to an
instance no longer present. No Redis alarm exists; most host coverage is for
v3. Alarm actions are configured, and two email subscriptions are confirmed,
but receiving an actual notification was not tested. Five log groups contain
about 146 MB total; two have no retention configured. No durable CloudTrail
trail/Lake store was visible in the inspected regions. CloudTrail management
Event History is distinct and is not declared absent. CloudFront legacy
logging is disabled; no CloudWatch delivery sources/deliveries were returned
in the edge/origin regions, and WAF has no logging configuration. Logging and
security-service choices need a deliberate retention, privacy and cost policy.

SES production sending and VDM are enabled. The measured regional SES series
contained seven sends and seven deliveries; no transactional email was sent
to test it. A related SQS queue had two visible messages; observed series
showed 14 sent, zero received/deleted and about five days maximum age, despite
four-day configured retention. Approximate metric and message-age semantics
need investigation; do not infer a mail outage or message-loss count. No queue
contents were consumed. Verify the feedback consumer and alerting separately.

## Costs and commitments

Cost Explorer range is 1 August–20 November, end exclusive. USD totals include
the returned tax charge; November covers **1–19 November**, is estimated and
is not a complete-month forecast. Usage quantities from different services
have different units and were not summed into a supposed resource count.

| Period | Unblended USD | Amortized USD | Tax in unblended USD |
| --- | ---: | ---: | ---: |
| August, closed | 605.59 | 605.59 | See aggregate |
| September, closed | 906.61 | 906.80 | See aggregate |
| October, closed | 799.68 | 799.68 | 138.78 |
| November 1–19, estimated | 556.38 | 494.40 | 97.84 |

October's main service costs before its separate tax entry were EC2 compute
285.89, RDS 138.36, Redis 113.42, EC2 Other 66.26, WAF 25.89, SES 15.53 and
VPC 14.88. SES is zero in the returned November partial period; current VDM
configuration alone does not justify repeating the old USD52/month saving.

The active regional m4.2xlarge reservation runs to 4 September 2029 at
USD0.2419/hour recurring, approximately USD176.59 for 730 hours. Cost Explorer
shows 100% utilization and no unused reservation cost in the audited 696-hour
utilization window. Shrinking/moving that workload can leave a paid commitment
unused. Partial-month unblended fees and amortization differ because of timing,
not necessarily a new recurring consumption increase.
[AWS cost definitions](https://docs.aws.amazon.com/cost-management/latest/userguide/ce-advanced.html).

Two candidates have directly observable idle-resource costs: the unattached
64 GiB gp2 volume billed USD6.40 in October and the idle IPv4 address billed
USD3.72 for 744 hours. At USD0.005/hour, the address is USD3.65 per 730 hours:
**approximately USD10.05/month combined candidate saving**, before taxes,
snapshot retention or migration costs. Confirm ownership, data and external
DNS before deletion/release. A released address cannot be promised back.
[Official public IPv4 price](https://aws.amazon.com/vpc/pricing/).

## Comparison and tracked work

The [28 September audit](../../docs/informe-costes-infraestructura-labdoo.md)
is a historical baseline, not fresh evidence. Current snapshots/AMIs number
20/7 versus the old 48/19; this audit did not delete any. Redis no longer shows
the old near-full/eviction situation in the measured window. SES actual cost
has fallen, although VDM remains enabled. Single-AZ web, the long reservation,
older RDS major branch, encryption gaps and the idle IPv4 still require work.
The wider regional audit additionally found the unattached us-east-1 volume.

All 180 then-open project tasks were inspected for duplicates. The existing
[AWS role-permissions task](https://app.todoist.com/app/task/6gPXQjC42Vmm25qV)
and [remote E2E/WAF task](https://app.todoist.com/app/task/6j2wMCPQj9Fc2xrV)
are referenced rather than recreated. Twelve distinct follow-up cards were
created in TODO; their detailed descriptions include acceptance checks,
risks and rollback limits. The audit parent remains open for review when
publication and deployment verification finish.

| Action | Priority | Pending outcome |
| --- | --- | --- |
| [AWS-A01](https://app.todoist.com/app/task/6j3vfF8Mr4qV6WGV) | P1 | Restrict public SSH and verify origin access protection |
| [AWS-A02](https://app.todoist.com/app/task/6j3vfF9VjvCgJ483) | P1 | Independent v3 data backup, DLM coverage and isolated restore |
| [AWS-A03](https://app.todoist.com/app/task/6j3vfFFJj7RGFJg3) | P1 | Supported RDS major-version migration before 31 December |
| [AWS-A04](https://app.todoist.com/app/task/6j3vfFF2MmhG73r3) | P1 | Staged IAM credential rotation and least privilege |
| [AWS-A05](https://app.todoist.com/app/task/6j3vfFF9F6xr8RF3) | P2 | Correct alarm coverage, retention and audit-trail policy |
| [AWS-A06](https://app.todoist.com/app/task/6j3vfFGp779wMHG3) | P1 | Data TLS/authentication and encrypted migration design |
| [AWS-A07](https://app.todoist.com/app/task/6j3vfFMPCJ3JwhqV) | P2 | Planned host/cache maintenance and verified recovery |
| [AWS-A08](https://app.todoist.com/app/task/6j3vfFHr2RfGp4M3) | P2 | Safe edge caching for public static assets |
| [AWS-A09](https://app.todoist.com/app/task/6j3vfFM73FRXRc93) | P2 | Explicit web availability target and workload-based sizing |
| [AWS-A10](https://app.todoist.com/app/task/6j3vfFRvcHChwRw3) | P3 | Owner-approved idle-volume/IP retirement |
| [AWS-A11](https://app.todoist.com/app/task/6j3vfFRGhV6pvMQV) | P1 | Narrow WAF exceptions with positive/negative checks |
| [AWS-A12](https://app.todoist.com/app/task/6j3vfFXFCq3XXM43) | P2 | Verified SES feedback consumer and age/backlog alerts |

## Validation and limits

The report and aggregate were reviewed against private evidence, local links,
numeric invariants and a sensitive-identifier scan. This release changes only
documentation/data: rerunning browser/load suites would not validate these
AWS configuration findings, so no fresh application-suite pass is claimed.
The prior aligned application release passed 558 E2E cases with 26 expected
skips and 24 Lighthouse runs; details remain in
[the alignment report](PANEL-ALIGNMENT-20261120.md).

Publication uses the maintained code-only wrapper, preserving private settings
and backups, and verifies the final Git ref/file hashes and actual PHP-FPM
responses. Final publication/SSM receipts are recorded on the audit task.
This is origin/runtime verification, not a public browser test through WAF and
Basic Auth. PHPCS, PHPStan and PHPUnit were not run for this documentation-only
release and are not represented as passing.

No new restore, failover, attack simulation, notification delivery or production
load was performed. Other accounts, external DNS/backups, two unmanaged hosts'
OS state, effective organization policies and all future production demand
remain unverified. Current headroom is not a future capacity guarantee, and
successful application deployment does not eliminate the pending AWS findings.
