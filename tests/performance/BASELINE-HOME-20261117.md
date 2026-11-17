# Lighthouse baseline for `/en` — 2026-11-17

This audit used the default theme at the exact `/en` URL, Lighthouse 12.8.2,
the isolated local checkout at `127.0.0.1:18411`, and one run per profile. It
was repeated after aligning the runtime to Drupal 11.4.12.

Reports are in the ignored directory
`tests/performance/.local/drupal11-home-default-20261117/`.

## Results

Scores are Performance / Accessibility / Best Practices / SEO:

| Profile | Current score | FCP | LCP | TBT | CLS |
|---|---:|---:|---:|---:|---:|
| Mobile | 58 / 88 / 100 / 83 | 3.47 s | 9.16 s | 378 ms | 0.0062 |
| Desktop | 85 / 88 / 100 / 75 | 0.89 s | 2.55 s | 14 ms | 0.0020 |

Compared with the earlier `/en` run recorded in
`tests/performance/.local/lighthouse-search-style/`, the failing accessibility
audits are identical on both profiles: `color-contrast`, `heading-order`,
`image-alt`, and `list`. SEO findings are also identical by profile:
`meta-description` and `image-alt` on mobile, plus `crawlable-anchors` on
desktop. These are persistent baseline findings, not new failures from the
autocomplete styling change.

The earlier scores were 44 / 88 / 100 / 83 on mobile and 86 / 88 / 100 / 75
on desktop. Mobile FCP improved from 4.67 s to 3.47 s and TBT from 767 ms to
378 ms; LCP changed from 8.98 s to 9.16 s. Desktop FCP stayed at 0.89 s, LCP
changed from 2.45 s to 2.55 s, and TBT changed from 10 ms to 14 ms. CLS
improved in both profiles. The prior runtime was Drupal 10.5.2 and the current
runtime is Drupal 11.4.12, so these small performance differences cannot be
attributed to one code change from this comparison alone.

The E2E autocomplete checks pass on desktop and mobile. No new accessibility
or SEO audit failures appeared in the current run. Follow-up cards should
address the persistent accessibility/SEO findings and the low mobile
performance score separately.
