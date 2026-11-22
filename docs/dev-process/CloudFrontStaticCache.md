# Safe CloudFront caching for v3 static assets

AWS-A08 enables short edge caching of reviewed code/assets while preserving the
existing dynamic behavior, staging authentication, WAF and origin access guard.
The [sanitized evidence](../../tests/performance/cloudfront-static-cache-20261122.json)
records native checks separately from local application checks.

## Scope and cache key

Thirteen extension-qualified behaviors precede the four existing behaviors:

| Paths | Extensions |
| --- | --- |
| `/themes/*` | css, js, webp, svg, png, jpg, woff2 |
| `/core/*`, `/modules/*` | css, js |
| `/sites/default/files/css/*` | css |
| `/sites/default/files/js/*` | js |

CloudFront's `*` matches nested path components. These are extension-qualified
patterns, not blanket caching of PHP, templates or every file under those roots.
General uploads and image-style derivatives remain on their existing disabled
policies; their public/private semantics were not certified or broadened here.
HTML, authenticated application pages, APIs and ACME retain their original
`CachingDisabled` behavior and origin request policies.

The static policy has minimum/default/maximum TTL **0 / 300 / 300 seconds**.
Minimum zero preserves origin `private`, `no-store` and `no-cache` decisions.
Default 300 is needed because real Apache static responses lacked an explicit
Cache-Control header. Maximum 300 bounds edge freshness even when the origin
advertises a longer lifetime. Browser caching still follows the origin headers;
a CDN invalidation cannot clear an already cached browser response.

Authorization and Host are in the cache key and forwarded. All query strings are
also keyed/forwarded, preserving Drupal aggregate parameters, language selectors
and release versions. Gzip/Brotli use CloudFront's normalized encoding variants.
Session cookies are neither keyed nor forwarded on these static behaviors. GET
and HEAD are cached; OPTIONS is allowed but not cached. Other methods remain on
the original dynamic behaviors, or are rejected on static URLs.

Including Basic Auth in the key prevents an unauthenticated or incorrect-credential
request from borrowing a warmed authenticated object. It does not revalidate a
credential against Apache on every hit. **Rotate/revoke staging credentials with
an invalidation of the cached static prefixes**; otherwise previously authorized
static objects can remain cached for their bounded lifetime. HTML and APIs remain
uncached. No authentication check was removed from the origin.

## Origin response guard

A dedicated logical origin clones the existing EC2 origin, including its TLS and
secret origin-verification header, and adds the nonsecret header
`X-Labdoo-Static-Cache: 1`. Only the new static behaviors target this origin.
It creates no additional EC2 instance or separate application stack. Future
origin-verification secret rotation must update both logical origins and the
Apache origin guard together.

The maintained [Apache configuration](../../scripts/runtime/cloudfront-static-cache.conf)
is installed at `/etc/apache2/conf-available/labdoo-static-edge-cache.conf`
with a corresponding enabled symlink. It makes marked responses noncacheable if
the status is an error/redirect or the actual MIME is not reviewed CSS, JavaScript,
image or font content. It preserves existing private/no-store static responses.

The marker survives Drupal/ErrorDocument internal redirects. A root Location
section runs after per-directory headers, preventing an earlier public max-age
from overriding this guard. A viewer forging the marker can only disable caching;
it does not grant access. CloudFront supplies the fixed marker on its static
origin. Ordinary dynamic-origin requests retain their existing header behavior.

Ten origin error mappings (400, 403, 404, 405, 414, 500, 501, 502, 503, 504)
have error TTL zero, including conditionally cached 4xx responses. Native status
codes and bodies are preserved. Empty ResponseCode/ResponsePagePath XML elements
specify no replacement page. The
origin guard also removes positive max-age from marked 4xx responses. This is
necessary because an error TTL alone does not override a positive origin max-age.

## Publication and invalidation

Use versioned URLs when publishing changed code/assets. Drupal aggregate hashes
and query parameters remain distinct keys. Update the URL/version of unversioned
images or code when a release changes them; invalidation alone cannot evict a
browser's long-lived copy.

For urgent refresh, invalidate the exact affected object path with a trailing
wildcard to include its query variants. CloudFront invalidation wildcards must
be at the end; a behavior pattern such as `/themes/*.css` is not an equivalent
invalidation pattern. A cache behavior's allowed wildcard syntax differs.

Credential rotation or a full rollback of this change uses only these affected
prefixes, rather than a distribution-wide `/*` invalidation:

```text
/themes/*
/core/*
/modules/*
/sites/default/files/css/*
/sites/default/files/js/*
```

This deliberately excludes general uploads, derivatives and dynamic routes.
The native publication test changed only an inert owned CSS fixture: a new
version query fetched the new content, the old query retained its old object,
and a completed targeted invalidation refreshed the old URL with `Miss → Hit`.

## Validation

The [pure configuration builder](../../scripts/cloudfront_cache_policy.py) has no
AWS calls. Its readback comparator normalizes unordered origin/method sets while
preserving behavior precedence and exact origin secret values. Run the local
policy and real isolated Apache checks with:

```sh
python3 -B tests/operations/cloudfront_cache_test.py
```

The Apache checks cover private responses, unexpected MIME, internal error
handling, later per-directory headers and ordinary dynamic responses. They use
a disposable loopback-only server and no Drupal database. Native source config
tests and actual edge requests provide separate deployment evidence.

Sixteen native checks passed, including five actual/static fixture
`Miss → Hit` pairs with matching bodies, missing/invalid Basic Auth, different
synthetic cookies with proof they did not reach the static origin, private CSS,
HTML MIME under a CSS URL, a missing CSS object, EN/ES HTML, a denied application
route, the fresh-quote API and an inert ACME challenge. Actual AWS readback
verified the new policy and preservation of the original configuration.

A short-lived Basic Auth probe identity was added without creating a Drupal
account or changing existing identity entries/permissions. It had a fail-safe
cleanup timer. All owned fixture files and the inert ACME file were removed;
after targeted cache purging, the identity was revoked and both HTML and a
previously cached asset returned 401 with its old credential. The timer was
stopped. No source users, sessions, private uploads or certificate configuration
were modified by these probes. Request/response receipts remain private.

Local application validation used the active checkout's isolated site, with
mail/provider/SQL guards, rather than production load:

- 44 desktop/mobile E2E cases passed, without skips, failures or retries: global
  search and theme asset/map/chart behavior, including authenticated search.
- The [focused k6 profile](../../tests/performance/static-assets-load.js) completed
  453 HTTP requests, 900/900 checks and all original thresholds. It checks checkout
  identity, actual rendered CSS/JS, MIME, body type, ETags and HTTP 304 behavior.
- Four Lighthouse 12.8.2 audits covered home/dashboard on desktop/mobile. They had
  no runtime errors or warnings, performance scores 98–99, and accessibility,
  best-practices and SEO scores of 100. Nonpassing optimization audits remain,
  including CSS/JS/render-blocking/cache recommendations; scores are not a claim
  that every audit passed.

These local runs do not traverse CloudFront and establish no CDN speedup or cost
saving. The first k6 setup attempt used default-site aggregate paths and failed
before generating load; it was corrected to the isolated site's paths and rerun
with unchanged thresholds. The failed report is retained privately and is not
counted as a pass. PHP/Composer dependencies and Drupal application code were not
changed. Local PHP 8.3.36 and installed/locked Drupal 11.4.13 are recorded; this
is not a claim that every target runtime patch was tested locally.

The unrelated deferred AWS-A06 Redis-report E2E HTTP500 remains in its own task;
this focused run does not claim to repair or certify that route.

## Rollback and operational boundaries

Private before/spec receipts include the full distribution configuration, ETag
and protected origin headers. Do not publish or paste that JSON into a public
issue. For rollback, fetch a fresh ETag, confirm only the reviewed fields changed,
restore this task's Origins/CacheBehaviors/error settings and invalidate the
five affected static prefixes. Preserve unrelated concurrent edits and WAF,
certificates, aliases and origin-verification secrets. Remove the owned cache
policy only after verifying it has no remaining associations.

The Apache guard can be disabled by removing only its enabled configuration,
validating Apache and performing a graceful reload, after CloudFront has reverted
to disabled caching. Repository reversion alone does not revert AWS or `/etc`.
The original origin access guard and authentication configuration must remain.

No OS patch, reboot, Redis update, failover, encryption cutover, dependency
installation, schema update or Drupal configuration import belongs to this
change. The earlier encryption and maintenance cards remain deferred.

Do not claim financial savings from probe hits. Review real CloudFront request,
byte, error and cache-hit-ratio metrics over representative traffic, recording
its period and staging Basic Auth fragmentation. Test invalidations have their
normal AWS billing semantics; no claim of unused account allowance is made.

## Primary references

- [CloudFront cache keys and minimum TTL](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/cache-key-understand-cache-policy.html)
- [CloudFront error caching and origin headers](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/custom-error-pages-expiration.html)
- [CloudFront cached HTTP error codes](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/HTTPStatusCodes.html)
- [Apache header processing order](https://httpd.apache.org/docs/2.4/mod/mod_headers.html)
