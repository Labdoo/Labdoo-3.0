# Idle resource recovery and approved retirement

AWS-A10, 22 November 2026. The owner explicitly approved retiring both candidates
and retaining the recovery copy. **The original 64 GiB volume has been deleted
and the unassociated Elastic IP released.** The encrypted recovery copy and all
38 pre-existing snapshots/seven AMIs remain; no running web server was retired.
[Retirement evidence](../../tests/performance/aws-idle-retirement-20261122.json)
records the completed actions and absence checks. The
[initial preparation snapshot](../../tests/performance/aws-idle-resources-20261122.json)
retains the earlier, then-pending decision. Native IDs, addresses, DNS results,
tags, file paths and hashes remain private.

## Candidates and ownership limits

Before the approved retirement, both candidates matched the original audit:

| Alias | Pre-retirement state | Removed cost before recovery storage |
| --- | --- | ---: |
| Idle legacy volume | us-east-1, gp2, 64 GiB, unencrypted, available, no attachments or tags; created 3 June 2019 | USD 6.40/month |
| Unassociated address | eu-central-1, Amazon-pool VPC Elastic IP, no instance/ENI association or tags | USD 3.65 per 730 hours |

Creation in 2019 does not establish when the volume was last attached. Neither
missing metrics nor missing tags establish that its data is dispensable. The
restored filesystem has a data layout, not the standard operating-system
directories used by the initial sampling plan. Business ownership and expected
retention were presented to the owner before explicit retirement approval.

There are no hosted zones in this AWS account. Five selected service/origin DNS
names resolved successfully without matching the candidate IP. Neither of the
two CloudFront origins references its literal address or EC2-encoded hostname.
A bounded search of the active checkout's scripts/config/docs/custom code, local
SSH config and hosts file found no reference. These checks do not inventory
external DNS, partner allowlists, undocumented clients or every subdomain.

The public service/origin checks and absence of an association support the idle
classification; they do not guarantee the IP is unused everywhere. AWS can
allocate a released address to another account, and its tags are not recoverable.
Identical-IP rollback is therefore not promised.
[AWS release/recovery restrictions](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/using-instance-addressing-eips-releasing.html)
are the reference.

## Recovery prepared and exercised

1. Refreshed the source volume state and confirmed available/no attachments
   immediately before creating a new snapshot in us-east-1. The source was not
   attached or modified for this operation.
2. Copied that snapshot to **eu-central-1 with encryption explicitly requested**.
   The copy completed at 100%, retained the 64 GiB volume size, used an enabled
   KMS key and had no snapshot-sharing grants. A new encrypted copy is the
   supported way to encrypt a previously unencrypted snapshot;
   [AWS documents that workflow](https://docs.aws.amazon.com/ebs/latest/userguide/ebs-creating-snapshot.html).
3. Tagged the copy as AWS-A10 recovery hold, with source region/volume/snapshot
   provenance in private resource tags. `ReviewAfter=2026-12-22` is a manual
   review date, **not automatic deletion**. Keep the copy until the owner accepts
   retirement, the rollback window and subsequent retention review.
4. Restored a fresh encrypted gp3 64 GiB test volume into the existing isolated
   private inspection controller's AZ. Its original three attached volumes and
   network were preserved; no new EC2 or public endpoint was created. Resources
   supporting the deferred encryption migration remain intact.
5. Identified the restored device by its exact EBS serial, set both the block
   device and ext4 partition read-only, and mounted with
   `ro,noload,nodev,nosuid,noexec`. No journal replay, old OS boot, old executable,
   application queue or mail process was run.
6. Read **32 file samples totaling 286,386 bytes**, with a maximum 64 KiB per file.
   Twenty-nine selected files fit entirely within that bound; three were prefix
   samples. Private SHA-256 baselines and file metadata were recorded. Nothing
   from the file contents or paths is published.
7. Unmounted and removed the task mount, detached/deleted only the temporary
   restored volume, then deleted only the task-created unencrypted source
   snapshot after the encrypted copy/readability proof succeeded. The encrypted
   recovery copy is retained. The controller is still running with its original
   three volumes. At that preparation stage the original legacy volume/IP were
   unchanged; they were retired only after the subsequent explicit approval.

The first probe selected standard OS directories and failed its sampling-count
assertion because those directories were absent. Its receipt was retained and
its finally block unmounted the filesystem. A separate read-only inventory
identified the data layout; the subsequent data-filesystem sampler covered 32
files with an explicit bounded-read scope. The failed attempt is not counted as
a passing data test.

This drill establishes an encrypted, private, restorable copy with readable ext4
data samples. It does **not** establish full-volume hash equality against the
original, complete application/database consistency, bootability or a business
recovery-time objective. The encrypted copy is retained after the approved
original-volume deletion.

## Existing snapshots and AMIs preserved

The refreshed baseline across eu-central-1, us-east-1 and eu-west-1 contained
**38 snapshots and seven AMIs**, rather than the audit's historical 20/seven:

- 13 snapshots are referenced by AMIs.
- 15 further snapshots carry DLM management tags and are not AMI-referenced.
- Ten are manual/unclassified by those two checks. This group is not approved
  for deletion; it includes recovery/migration evidence that needs retention
  review rather than an automatic age-based purge.

No launch templates, Auto Scaling groups, launch configurations or CloudFormation
stacks were found in those three regions. None of the seven custom AMIs matches
the running instances' current ImageId. That does not exclude manual recovery or
future launch dependencies. Snapshot copies can have an arbitrary VolumeId, so
provenance tags and AMI/block-device references matter more than a naive volume
ID join. [AWS snapshot-copy behavior](https://docs.aws.amazon.com/ebs/latest/userguide/ebs-copy-snapshot.html)
describes this caveat.

All 38 baseline snapshots and all seven AMIs remain. After cleanup there is one
additional permanent encrypted recovery snapshot and no temporary test volume.
DLM policies and the preparation resources for postponed migrations were not
changed. The original recovery/maintenance holds continue to apply.

## Cost comparison

Native AWS Price List results for 22 November, before tax/allowances/variable
charges, give:

| Item | Rate/assumption | Normalized USD/month |
| --- | --- | ---: |
| Original gp2 volume | 64 GiB at 0.10/GB-month, us-east-1 | 6.40 |
| Unassociated IPv4 | 0.005/hour, 730 hours | 3.65 |
| Gross removed run rate | Both originals retired | **10.05** |
| Retained standard snapshot | Frankfurt 0.054/GB-month; full 64 GiB envelope | **up to 3.456** |
| Conservative net run-rate reduction | Both originals retired; full-size snapshot envelope | **about 6.59** |

Snapshot storage is billed on stored snapshot data, not simply filesystem used
space. The legacy CLI response did not expose a full snapshot size field; no
precise billed-byte or filesystem-used-size saving is asserted. The full-volume
envelope makes the recovery-storage cost visible. Cross-region copy transfer,
short-lived snapshot/test-volume storage and API requests can add one-time or
variable charges; they are not measured here. Snapshot review does not imply
its future deletion is approved.

Both original resources have now been removed. The gross removed cost run rate
is USD 10.05/month; after the full-size snapshot envelope, the conservative net
estimate is about USD 6.59/month. This is a forward estimate, **not measured
invoice savings**. The comparison excludes the rest of the account and does not
count removal of existing backup, staging, database or migration resources.

## Approved retirement and verification

The owner explicitly instructed: **retire both and keep the copy**. The earlier
card restriction applied to the diagnostic phase; no further retirement approval
is pending for these two exact resources.

1. Refreshed both private resource IDs immediately before execution. The same
   64 GiB gp2 source in us-east-1 was available/unattached with unchanged tags.
   The same eu-central-1 allocation had no association, ENI or instance and its
   address/tags were unchanged. No substitute resource was selected.
2. Confirmed the recovery snapshot was completed, encrypted, private and 64 GiB;
   the KMS key was enabled and RecoveryHold/source provenance tags matched.
3. Deleted only that original volume. Rechecked the exact IP allocation guard
   immediately before releasing it. Both API requests succeeded. The retained
   encrypted snapshot was not deleted or shared.
4. Refreshed inventories: the original volume is absent and us-east-1 now has no
   EBS volumes in this account; the released allocation/address is absent, with
   one other allocation retained. All 38 baseline snapshots and all seven AMIs
   remain; there are 39 snapshots including the new encrypted recovery copy.
5. The three web instances remain running, all 66 alarms are OK, and the five
   selected service/origin names resolve without matching the released IP.
   The report publication also uses the maintained v3 deployment/FPM checks.

The released address was not reclaimed for a test. Identical-IP rollback is not
guaranteed, and external DNS/allowlist discovery remains bounded as stated above.
No AMI deregistration, unrelated snapshot deletion, server retirement, deferred
maintenance or encryption switchover was performed under this approval.

For volume recovery, copy the encrypted snapshot back to the desired region if
needed, create a new volume in the attachment AZ and restore/mount it through an
approved recovery procedure. A new volume gets a different resource ID; region,
KMS permissions, attach paths and any filesystem UUID references require review.
The read-only sample drill does not promise a bootable application rollback.
For the released IP, recovery is best effort and may be impossible. If it cannot
be recovered, allocate another address and update all known DNS/allowlist
references; do not label that as identical rollback.

## Deliverable validation

Only this runbook and sanitized aggregate evidence are published. Validate JSON,
relative links, cost arithmetic, diff scope and absence of identifiers, addresses
and data contents. The actual encrypted-copy/readonly-restore/cleanup receipts
provide operational recovery evidence. No Drupal behavior, dependencies, queue
or interface changed, so E2E/k6/Lighthouse do not establish additional evidence
for this document. The maintained v3 publication wrapper checks PHP-FPM and
private settings when the report is deployed. No different PHP/runtime version,
complete recovery SLA or full application consistency is certified here. The
resource retirements themselves are confirmed by the native absence checks.
