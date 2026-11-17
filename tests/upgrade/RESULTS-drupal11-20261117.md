# Drupal 11.4.12 local regression verification — 2026-11-17

All commands targeted the isolated E2E clone at `http://127.0.0.1:18411` from
this checkout. The clone had no pending database updates before the run.

## Runtime

- Drupal: 11.4.12, matching `composer.lock`.
- Drush: 13.8.1.0.
- PHP CLI: 8.3.36.
- Lighthouse: 12.8.2.

## E2E

Command: `make e2e LAB_RUN_ID=drupal11-final-e2e-20261117`

- 451 tests discovered; 429 passed, 22 skipped, 0 failed.
- The wiki link distinction regression passed on desktop and mobile.
- Contact webform submission and translation-related workflows passed.

## k6

Command: `make k6 LAB_RUN_ID=k6-routes-20261117`

- 13-minute profile, peak 100 VUs.
- 38,549 iterations and 46,212 HTTP requests.
- Functional and checkout-isolation checks: 115,647 passed, 0 failed.
- Failed HTTP requests: 0.00% (threshold: below 2%).
- p95 response time: 11.08 ms (threshold: below 2,000 ms).

## Lighthouse

Command: `python3 tests/performance/lighthouse.py --base-url http://127.0.0.1:18411 --routes home dashboard school wiki --profiles mobile desktop --output tests/performance/.local/drupal11-final-20261117`

Scores are Performance / Accessibility / Best Practices / SEO:

| Route | Mobile | Desktop |
|---|---:|---:|
| Home (`/en`) | 99 / 100 / 100 / 100 | 98 / 100 / 100 / 100 |
| Dashboard | 98 / 100 / 100 / 100 | 96 / 100 / 100 / 100 |
| School | 97 / 100 / 100 / 100 | 100 / 100 / 100 / 100 |
| Wiki | 99 / 100 / 100 / 100 | 100 / 100 / 100 / 100 |

The school audit started at `/en/node/749` and redirected to its canonical
alias, `/en/content/nepal-kathmandu-handigaun-primary-school-handigaun`. The
runner recorded that redirect as a warning; the resulting audits completed.
The wiki `link-in-text-block` audit passed in both profiles.

Machine-readable Lighthouse reports and the k6 summary are kept under ignored
`tests/performance/.local/`; the Playwright report is under ignored
`tests/upgrade/.local/state/runs/drupal11-final-e2e-20261117/`.
