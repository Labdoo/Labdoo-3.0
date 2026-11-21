# V3 volume protection and recovery

AWS-A02, validated on 21 November 2026. Tracking: [recovery task](https://app.todoist.com/app/task/6j3vfF9VjvCgJ483).

## Protection in service

A dedicated instance-tagged DLM policy protects the v3 root (20 GiB) and data
(120 GiB) disks together. Only the v3 instance carries
`LabdooRecoveryPolicy=v3-daily`. The policy includes the boot volume, starts a
backup window daily at **00:42 UTC**, retains **seven local recovery points**,
and copies both snapshots to **eu-west-1 with encryption and 14-day retention**.
The first observed scheduled pair started at `2026-11-21T00:56:40.048Z`.
Both source snapshots and both encrypted regional copies completed. Local
snapshots inherit the source volumes' unencrypted state; the regional copies
are encrypted. Existing DLM policies and source volume encryption were unchanged.

Two initial manual bootstrap snapshots, taken before the first scheduled point,
remain as additional fallback copies. They are outside this policy's automatic
retention: review their eventual removal separately after subsequent scheduled
points are proven. No existing backup was deleted during this task.

This provides an independent copy outside the source volume and region, within
the same AWS account. It is **not an immutable or cross-account backup**. Native
snapshot expiration is configured, but a 7/14-day retention cycle had not elapsed
at the time of this report. Storage and regional transfer incur usage-based
costs; logical volume size does not predict incremental snapshot billing.

## Monitoring

Six CloudWatch alarms use native `AWS/EBS` metrics scoped to this policy's
`DLMPolicyId` and the existing confirmed v3 SNS email destination:

| Alarm suffix (prefix `LabdooV3Backup`) | Metric | Condition |
| --- | --- | --- |
| CreateFailed | SnapshotsCreateFailed | Hourly sum greater than zero |
| CopyFailed | SnapshotsCopiedRegionFailed | Hourly sum greater than zero |
| DeleteFailed | SnapshotsDeleteFailed | Hourly sum greater than zero |
| CopyDeleteFailed | SnapshotsCopiedRegionDeleteFailed | Hourly sum greater than zero |
| NoLocalActivity | SnapshotsCreateCompleted | Six consecutive four-hour buckets without success |
| NoRegionalCopyActivity | SnapshotsCopiedRegionCompleted | Six consecutive four-hour buckets without success |

Failure alarms treat missing events as non-breaching; activity alarms treat
missing successes as breaching. Activity detection is roughly a day; bucket alignment and CloudWatch missing-data
evaluation can delay the transition. This is not an exact per-volume RPO guarantee. Native metrics actually reported unit
`None`; alarm specifications intentionally omit a guessed `Count` unit.
All six alarms were read back **OK with actions enabled** after observing the
real scheduled backup and copy. No fabricated metrics, forced alarm states or
test emails were used. Delivery to a human mailbox was not separately exercised.
An activity success for one disk can mask failure of another: inspect the complete
root/data recovery set when responding to any alert or conducting routine review.

## Recovery drill and evidence

The drill restored **the actual scheduled encrypted regional copies**, not just
the earlier bootstrap snapshots. Disposable encrypted copies were brought back
to eu-central-1 and restored to separate disks. A clean Amazon Linux controller
mounted them as secondary filesystems; the recovered operating system was never
booted. Journal replay affected only the disposable restored disks. A disposable
overlay provided application writes without changing the recovered lower image.

The isolated VPC had local routes only: no Internet gateway, NAT, peering or
public IP. The controller had no inbound access; egress allowed only private
SSM endpoints and the restored database. Endpoint policies allowed only the
temporary SSM role. Source credentials could not provide a production network
path. No source volumes, SSH rules, source database or production service
configuration were changed by the drill.

An RDS clone was recovered using native PITR at the same
`2026-11-21T00:56:40.048Z` reference time as the scheduled disk pair. Recovery
settings pointed only to that clone, used database caches, disabled automated
cron, selected the test mail collector, blocked sendmail and external HTTP, and
ran no queue workers. Source RDS PITR retention remains 14 days. The clone
inherited the source's unencrypted RDS storage; RDS encryption remediation is
outside this task.

Results reviewed from private receipts:

- **52/52 sampled files** matched SHA-256, size, numeric owner/group, mode and
  web-user readability, both before and after the overlay preparation. This
  included 20 uploads and approximately 8.1 MB hashed on the source. It is not a
  full hash inventory of all uploads or the whole disk.
- Drupal bootstrapped against the recovery database. Aggregate records:
  112,238 nodes, 62,192 file records and 8,021 user records.
- Recovered PHP **8.3.6** and Drupal **11.4.13** matched the recovered Composer
  core lock version **11.4.13**. Application revision was
  `021b7b3a957777f67dff27fbfb136e3f4258c4ce`.
- The recovered `verify-v3-fpm.py` ran through the recovery PHP-FPM socket as
  the web user: **12/12 HTTP 200 checks, zero PHP stderr errors**. Coverage
  included English/Spanish home, principal listings, clustered maps, fresh
  private quotes and cold/warm map cache transitions. The map aggregates were
  56,523 devices and 3,162 schools. This HTTP phase took 57.472 seconds.
- Harness setup failures involving overlay permissions, a Drush entry point
  and resolver access were corrected only in the isolated clone before the
  successful run. No assertion or performance budget was relaxed.
- Dedicated Python unit coverage passes **16/16 tests**, covering policy scope,
  retention, encryption and alarm semantics, plus corruption, ownership,
  permissions, missing files, web access and path/symlink rejection.

The machine, its three temporary disks, both test databases, private endpoints,
copyback snapshots, test network, instance profile and temporary IAM role were
removed after validation. Scheduled local/regional backups and the two bootstrap
snapshots remain. Raw manifests, filenames, settings and AWS identifiers are
private under the ignored `tests/performance/.local/volume-recovery-20261121/`;
only [aggregate evidence](../../tests/performance/volume-recovery-20261121.json)
is published.

## RPO, RTO and consistency limits

The planning RPO is **one daily disk point (nominally 24 hours), plus snapshot
and regional-copy completion delay**. This is a scheduling objective, not an
established business SLA; RDS has its own finer PITR granularity. Use the latest
complete matching disk pair and an available corresponding RDS restore time.

A **four-hour operational RTO is provisional and unproven**. This drill crossed
an overnight pause and did not measure uninterrupted end-to-end recovery time.
The 57.472-second HTTP phase is not the RTO. Repeat a timed drill before promising
a recovery deadline. Public DNS/CloudFront failover, certificate replacement and
booting the restored operating system were not tested.

Online EBS snapshots provide crash consistency, not an atomic transaction across
Drupal, uploads and RDS. Matching RDS time and successful application checks
reduce risk but do not prove cross-service transactional consistency. Production
was not frozen or stopped; the data volume also supports runtime bind mounts.
For stricter requirements, plan an explicitly authorized write-quiescence window
and database/file reconciliation rather than freezing the live disk blindly.

## Repeatable operator procedure

1. Identify v3, its two volume IDs, the dedicated policy and RDS source using
   private inventory. Confirm the account/regions and avoid printing settings or
   credentials. Check source health, backup age, both disks' completed state,
   encrypted regional copies and snapshot sharing permissions. Resolve alerts
   before assuming the most recent point is usable.
2. Select a complete same-time root/data set. Keep originals and retained
   snapshots. Restore encrypted copies to new disks and an RDS PITR clone in a
   separately tagged private VPC. Grant only narrowly scoped SSM and clone-DB
   access. Do not attach these disks to production or reuse production routes.
3. Start a clean controller, never the recovered operating system. Verify disk
   identities against the new volume IDs, mount only disposable recovery disks,
   and provide a disposable overlay for writes. Disable copied scheduled work,
   real mail and external providers before bootstrapping the application.
4. Run `compare_manifest()` from
   [the filesystem verifier](../../scripts/verify_v3_recovery_files.py) against
   a private manifest, with a web-user readability callback. Reject any mismatch;
   never use `/` as the recovery target. The helper reads files but does not
   create snapshots, mount disks or change production.
5. Override only the clone's settings, verify the DB host and locked/runtime
   versions, and run the maintained [FPM verifier](../../scripts/verify-v3-fpm.py)
   against its isolated socket. Use `--isolated` only when that site's harness
   supplies the required checkout/isolation headers. Do not substitute a
   production smoke test for isolated restoration evidence.
6. Record start-to-finish timings, failures, file and database checks, and the
   exact point/revision. Any public failover or production replacement requires
   its own approved operational plan; this drill does not perform cutover.
7. After evidence passes, remove only tagged disposable resources, then read back
   their absence. Keep retained snapshots, source disks and permanent monitoring.

[Policy builders](../../scripts/v3_backup_policy.py) produce specifications only;
they perform no AWS calls. Review generated requests, existing resource IDs and
alarm actions before applying anything. Do not rerun creation blindly: update
or inspect the existing dedicated policy instead of making duplicates.

Run the database-free checks from the checkout being published:

```sh
python3 -B tests/operations/v3_backup_policy_test.py
python3 -B tests/operations/v3_recovery_files_test.py
```

These changes add operational Python tools and documentation, with no application
behavior, dependency, schema or Drupal configuration changes. Browser/load/audit
suites and PHP quality suites were not rerun for this publication. They are not
claimed as passing; the actual isolated restore and target FPM smoke provide the
operational evidence described above.

## Reference behavior

- [EBS snapshot consistency](https://docs.aws.amazon.com/ebs/latest/userguide/ebs-creating-snapshot.html)
- [DLM encrypted regional copy](https://docs.aws.amazon.com/dlm/latest/APIReference/API_CrossRegionCopyRule.html)
- [DLM native metrics](https://docs.aws.amazon.com/ebs/latest/userguide/monitor-dlm-cw-metrics.html)
- [CloudWatch metric unit selection](https://docs.aws.amazon.com/AmazonCloudWatch/latest/APIReference/API_PutMetricAlarm.html)
- [RDS point-in-time recovery](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_PIT.html)
- [Existing v3 code deployment procedure](V3CodeDeployment.md)
