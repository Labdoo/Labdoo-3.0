# V3 WAF exception boundaries

AWS-A11, applied on 21 November 2026. [Tracking task](https://app.todoist.com/app/task/6j3vfFRGhV6pvMQV).

## Problem and applied change

The CloudFront web ACL associated only with `v3.labdoo.org` had five early,
terminating Allow rules: ACME, a test IP set, node-edit AJAX, media-library AJAX,
and a broad media/file path exception. Matching requests stopped before common
managed rules, reputation checks, Bot Control and rate limits. The IP set held
one address, not a world CIDR; this still bypassed all later checks for that
address. No exploitation is claimed.

All five terminal Allow rules were removed. The ACL now has six rules:

| Priority | Rule | Enforcement |
| --- | --- | --- |
| 5 | AWSCommonRules | Original managed group and overrides preserved |
| 6 | AWSIPReputation | Original managed group preserved |
| 7 | AWSBotControl | Native enforcement except the two signals below |
| 8 | RateLimitSensitivePaths | Original IP rate rule, limit 200 preserved |
| 9 | BlockNonAcmeBotSignals | Block either counted signal outside canonical ACME |
| 13 | RateLimitGlobal | Original IP rate rule, limit 1,500 preserved |

`CategoryHttpLibrary` and `SignalNonBrowserUserAgent` are individually counted
inside Bot Control. Their rule-match labels are then blocked by priority 9 unless
**all** of the following match:

- Host is exactly `v3.labdoo.org`, ignoring case.
- Method is GET or HEAD.
- Path is exactly `/.well-known/acme-challenge/` followed by 20–128 URL-safe
  ASCII token characters, with no nested path or decoding transformations.
- Query string and body are empty. Oversized bodies cannot qualify.

This lets ACME validation clients fetch their token without allowing non-browser
clients to bypass Bot Control elsewhere. Other bot rules, including automated
browser detection, keep their native actions. There is no group-wide Count,
Bot Control scope-down exclusion, new terminal Allow, IP exemption or trusted
client-supplied bypass header. The remaining common/reputation checks and both
rate limits still apply even to an ACME request qualifying for the exception.

The compensating rule uses `CategoryHttpLibrary` and
`SignalNonBrowserUserAgent` **rule-match labels**, not a general HTTP-library
classification. AWS can classify verified bots without matching the blocking
rule. A regression test reproduced the overblocking risk of category matching;
the final rules preserve verified-bot handling by using the specific rule labels
confirmed in native provider metadata. The final edge and certificate checks
were repeated after that refinement.

The ACL default Allow and other settings are unchanged. Existing common-group
Count overrides for `GenericLFI_BODY` and `SizeRestrictions_BODY` were preserved;
this task does not claim to remove those separate body-inspection exceptions.
No new body-rule exception was needed for the tested ordinary edit/upload
payloads. Those pre-existing overrides and AWS's finite body inspection window
remain security limits, especially for large uploads.

The old test IP set remains stored for rollback but is no longer referenced by
this ACL. The related [remote E2E task](https://app.todoist.com/app/task/6j2wMCPQj9Fc2xrV)
was already proposed for retirement because that runner had been removed. This
change does not restore remote testing or allow an entire IP for automation.

## Verification

The [builder](../../scripts/v3_waf_exceptions.py) performs no AWS calls and rejects
unexpected inventories, managed-group overrides and ordering before producing a
replacement. Ten [unit tests](../../tests/operations/v3_waf_exceptions_test.py)
pass. They cover the emitted predicates, source immutability, preserved rules,
bot-label blocking, verified-bot category preservation, ACME boundaries and fail-closed
inputs. They do **not** emulate AWS managed attack detection.

AWS's native `CheckCapacity` accepted the complete candidate at **805 WCU**.
The two rule names and full label keys were checked against the current managed
group metadata. Immediately before updating, the complete ACL was reread and
compared with the reviewed baseline; the update used its fresh optimistic lock.
The readback exactly matched the candidate rules, with all non-rule settings
preserved. The initial CLI request rejected an empty optional description before
sending the update; omitting that empty field resolved it. A repeated check made
before the successful update still reproduced the old bypasses; it was retained
as failed/pre-change evidence, not counted as a passing post-change run.

Twenty baseline probes and twenty final probes went through the real public
CloudFront/WAF path at low volume, with TLS verification enabled:

| Probe | Before | After |
| --- | --- | --- |
| EN/ES home and media-library reads | Existing Basic Auth 401 | Same 401 |
| EN/ES ordinary node-edit AJAX payloads | Existing Basic Auth 401 | Same 401 |
| EN/ES synthetic image upload payloads | Existing Basic Auth 401 | Same 401 |
| Canonical ACME, browser/library GET and library HEAD | 200 | 200, expected fixture verified |
| Inert XSS query on edit/media/file | Reached Basic Auth 401 | WAF 403 |
| Inert XSS query on ACME fixture | 200 | WAF 403 |
| Non-browser media read / node AJAX | Reached Basic Auth 401 | WAF 403 |
| Non-browser ACME with extra query | 200 | WAF 403 |
| Non-browser ACME with short token | Origin 404 | WAF 403 |
| Non-browser ACME POST | 403 | 403 |

All **20/20 final expectations passed**. Native sampled requests were inspected
to distinguish managed/common and compensating bot blocks from an origin status.
The harmless XSS strings were unauthenticated probes; they did not execute in a
browser or submit authenticated content. No rate-limit saturation, exploitation
or production load test was performed. Exact rate-rule preservation was checked
in configuration rather than by exceeding the thresholds.

The synthetic POSTs deliberately carried no Basic Auth credentials, application
cookies or valid form tokens. A 401 demonstrates that WAF did not reject the
representative ordinary request; it is **not proof of an authenticated browser
upload or edit through WAF**. Full lifecycle operations were separately tested
only against the guarded local clone, never against production:

```sh
E2E_RUN_ID=waf-scope-20261121 npm --prefix tests/e2e test -- \
  -c upgrade.config.ts upgrade-entities.spec.ts --project=desktop \
  --grep 'upload, read, edit and delete media (image|document)|create, read, update and delete (article|gallery)$'
```

The reviewed Playwright report has **5 expected, 0 unexpected, 0 flaky, 0 skipped**:
authentication, article lifecycle, gallery lifecycle/media selection, document
upload lifecycle and image upload lifecycle. This ran against the active local
checkout (HEAD `d714a9dd2fe4d5330f6567dbe405d658085c4a1c` with unrelated dirty
changes), with its own isolated database, test mail and external-service guards.
Installed local PHP is 8.3.36; installed and locked Drupal are both 11.4.13.
This local application evidence does not certify a different published revision
or replace the real edge checks. No application source was changed by AWS-A11.

After applying WAF, `certbot renew --dry-run --cert-name v3.labdoo.org
--no-random-sleep-on-renew --non-interactive` passed against the staging CA.
Executable/inline renewal hooks were ruled out first, and the production
certificate checksum was unchanged. This exercises actual ACME validation,
beyond a static fixture. The temporary public fixture was removed after testing.

[Sanitized aggregate evidence](../../tests/performance/waf-exceptions-20261121.json)
contains no IP addresses, account IDs, ARNs, private form data or challenge token.
Raw ACLs, samples and command receipts remain in the ignored private directory
`tests/performance/.local/waf-exceptions-20261121/`. This publication changes
operational Python and documentation, with no PHP/JS/CSS, dependency, database
schema or Drupal configuration edits. k6, Lighthouse and PHP quality suites were
not run for this WAF change; they would not evaluate AWS rule enforcement.
Publication/deployment receipts and actual target FPM results are recorded on the
task separately from the local and edge checks above.

## Operation and rollback

Use the existing ACL; never create a duplicate or blindly rerun the builder on
an already changed inventory. Confirm the distribution association, current
managed rule names/labels, default action, complete settings and optimistic lock.
Review the candidate, run its unit tests and native capacity validation, and
prepare both positive and negative low-volume probes before applying it.

Monitor common-rule, bot and custom-block samples for false positives while
preserving request privacy. A detected new legitimate blockage requires diagnosis
of the precise rule and payload, not a broad Allow or disabling Bot Control.
For a planned larger upload inspection review, account for both the existing
Count overrides and inspection-size limits explicitly.

The complete original configuration is retained privately. The prepared rollback
helper refuses to overwrite another operator's changes, obtains a fresh lock and
restores only that reviewed baseline. Rollback reintroduces the old broad Allow
rules, so it is an incident fallback, not the desired final state. Never set the
whole bot group to Count or remove security groups to work around an error.
After rollback or further tuning, repeat edge probes, actual certificate renewal
validation when relevant, and application health checks.

## References

- [AWS rule action termination](https://docs.aws.amazon.com/waf/latest/developerguide/web-acl-rule-actions.html)
- [Individual rule overrides and continued evaluation](https://docs.aws.amazon.com/waf/latest/developerguide/web-acl-rule-group-override-options.html)
- [Bot Control managed rules and labels](https://docs.aws.amazon.com/waf/latest/developerguide/aws-managed-rule-groups-bot.html)
- [Common managed rules and body inspection limits](https://docs.aws.amazon.com/waf/latest/developerguide/aws-managed-rule-groups-baseline.html)
- [V3 code deployment](V3CodeDeployment.md)
