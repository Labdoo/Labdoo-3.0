# V3 web origin protection

The web-origin change was separated from the deferred SSH task at the user's
request on 21 November 2026 (Europe/Madrid). It applies only to v3. SSH ingress,
SSH configuration, other hosts, database access, instance sizes, WAF rules and
CloudFront cache policies are outside this operation.

The implementation uses two layers:

1. The v3 security group admits public HTTPS origin traffic through the existing
   AWS-managed CloudFront origin-facing prefix list. The two world-IPv4 ingress
   rule IDs for TCP80 and TCP443 were removed. The existing TCP22 rule and all
   remaining ingress/egress rules were verified identical. The group was checked
   to belong only to the authorized v3 instance before mutation.
2. All three public Apache virtual hosts include a guard that rejects requests
   without the distribution's existing `X-Origin-Verify` value. The HTTPS
   virtual host previously did not enforce this header. A CloudFront address
   alone does not identify our distribution, so both layers are necessary.

The distribution already connected to the origin using HTTPS only and already
injected the verification header. Its full configuration was checked unchanged
before the network mutation. No distribution update or secret rotation was
needed. The existing Apache HTTP condition was checked against the privately
retrieved CloudFront value using SHA256; the installer reused the matching token
on the server without placing its value in SSM command arguments or source.

The new rendered guard is root-readable mode0600 outside the checkout, under
`/etc/apache2/labdoo-private/`. Public source contains only a placeholder.
Existing virtual hosts retain their prior content and ownership/mode, with one
Include added at the start of each VirtualHost, before existing rewrite rules.
The earlier HTTP virtual-host condition is preserved, including its existing
secret storage; this operation does not claim a complete host-secret migration.

## Apache semantics

The guard uses `CONN_REMOTE_ADDR`, the actual peer connection address. A client
cannot obtain the loopback maintenance exception by supplying X-Forwarded-For.
Loopback requests still face the existing Basic Auth and directory policies.
The separate port8081 local health virtual hosts are unchanged. Public requests
require the exact, anchored, case-sensitive token. A spoofed Via header is not
trusted. The server-level forbidden response executes before directory
authorization or `.htaccess` rules can grant access.

Basic Auth remains active for the application. ACME is served from the existing
webroot alias without Basic Auth, while requests arriving over a public origin
connection must still carry the distribution token. Certificate validation
reaches CloudFront on viewer HTTP80; CloudFront then uses HTTPS443 to the
origin, so public origin port80 is unnecessary for this configuration.

AWS describes the combination of a managed origin-facing prefix list and a
private custom header in its [origin restriction guidance](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/restrict-access-to-load-balancer.html).
The linked example uses an ALB; this deployment implements the header check in
Apache on EC2 and did not introduce an ALB. See also the
[Apache rewrite documentation](https://httpd.apache.org/docs/2.4/mod/mod_rewrite.html).

## Source and prerequisites

- [Guard template](../../scripts/templates/v3-origin-guard.conf.template).
- [Scoped installer and rollback](../../scripts/install-v3-origin-guard.py).
- [Isolated Apache regression tests](../../tests/operations/origin_guard_test.py).
- [Sanitized operation evidence](../../tests/performance/origin-protection-20261121.json).

Run the maintained test from the active checkout:

```sh
python3 -B tests/operations/origin_guard_test.py
```

It launches an isolated Apache on an ephemeral loopback port, with temporary
static files and dummy Basic Auth credentials. It does not use Drupal, a live
database, mail, queues or external providers. Apache2.4.58 was installed both
locally and on v3. A pre-guard reference failed eleven assertions/subtests;
the final guard and installer-plan suite passed all ten test methods. An
initial fixture error from a pre-existing `/tmp/.htaccess` was fixed by setting
explicit ancestor AllowOverride policy in the isolated fixture. It was not
treated as evidence of an application defect or a valid failing reference.

The installer is for the inspected v3 server as root. It refuses an unexpected
enabled-vhost layout, an existing guard, an unmatched token digest or a token
with regex metacharacters. It prepares root-private copies and checksums before
writing. Apache configtest runs before and after the change; a failed install
restores the originals. It reloads Apache only after successful configuration
validation. It never modifies EC2 rules, SSH, PHP code or Drupal configuration.
The applied remote staging copy was byte-checked against the reviewed source.

## Completed operational validation

- Before the change, direct-origin HTTP/HTTPS requests could retrieve a uniquely
  named static ACME canary with no token, a wrong token or spoofed Via/XFF.
- With the Apache guard active and public network rules still present, those
  four direct checks returned403. This tests the header layer independently.
- After removal of the two public web rules, all four fresh direct connections
  timed out; CloudFront HTTP/HTTPS canary requests remained200 with the exact
  expected body. HTTPS certificate validation remained enabled throughout.
- A viewer-supplied wrong origin header did not break the CloudFront response:
  the distribution continued to send its configured origin header.
- Public `/en` and `/es` retained the same401 Basic Auth challenge as before.
  This confirms the access gate remains in place; it is not an authenticated
  public-browser test. No user credentials were requested or changed.
- Local `/server-status` and `/php-fpm-status` on port8081 returned200.
- `certbot renew --dry-run --cert-name v3.labdoo.org --no-random-sleep-on-renew
  --non-interactive` succeeded against the staging CA after checking that no
  executable renewal hooks were present. The production certificate checksum
  was identical before and after; the existing renewal timer/configuration
  remain active. This is stronger evidence than an ACME-path canary alone.
- The existing database-free PHP-FPM verifier suite passed10/10. Final actual
  PHP-FPM, deployed source and rule-preservation checks are recorded on the task.

No application source or load-sensitive request handling was changed. Full
Playwright/k6/Lighthouse suites against the PHP development server would not
exercise these Apache/security-group controls, so their earlier results are
not relabelled as new validation. The dedicated Apache integration suite,
external positive/negative probes and actual-origin runtime checks cover this
operation. No production load, user submission or real mail was generated.
PHPCS/PHPStan/PHPUnit are not represented as passing for this Python/config
operation. Existing unrelated workspace edits and staging were preserved.

## Rollback and continuing operations

Private original virtual-host files and a checksum manifest are retained under
`/root/labdoo-origin-guard-backups/`. The exact successful backup directory is
recorded in the private installation receipt and the Todoist task. Rollback is:

```sh
sudo python3 -B scripts/install-v3-origin-guard.py --rollback /root/labdoo-origin-guard-backups/REVIEWED_BACKUP_DIRECTORY
```

The installer verifies before/after hashes and refuses unrelated configuration
drift. Use the immutable reviewed staging copy if the checkout's installer has
subsequently changed. Restoring network access, if required, uses only the
privately saved GroupId/IpPermissions input for the two removed web rules;
never replace an entire security group or include TCP22 in that rollback.
Restored EC2 rule IDs may differ from their original IDs.

The canary is removed after final probes, checking its expected body before
unlinking that single file. Private backups and diagnostic logs are retained;
they are not published. Normal code-only deployments preserve these Apache
includes. Future changes to distribution headers, origin protocol, certificates,
ACME routing, host recovery or health probes must account for this guard. A
token rotation requires a coordinated transition; changing only CloudFront's
value would reject legitimate origin traffic.

Tracked web task: [protect v3 origin](https://app.todoist.com/app/task/6j3w9hXvfXhJ3VP3).
Deferred, unchanged task: [SSH](https://app.todoist.com/app/task/6j3vfF8Mr4qV6WGV).
