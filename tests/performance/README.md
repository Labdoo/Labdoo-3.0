# Auditorías de Labdoo Next

## Acceso desde el Makefile del checkout principal

Desde `/var/www/html/labdoo3`, la suite del checkout actual, con datos aislados, se controla con `make e2e-serve`
(Drupal en primer terminal), `make e2e` (Playwright), `make k6` o
`make lighthouse`. Para el panel web, ejecuta `make e2e-dashboard` en otro
terminal y abre `http://127.0.0.1:18412`. La primera vez, instala Playwright con
`make e2e-deps`. El laboratorio debe estar preparado según
[`tests/upgrade/README.md`](../upgrade/README.md).

El escenario k6 conserva las cinco rutas y los umbrales de latencia/error; exige además que todos los checks funcionales y de aislamiento pasen. La identidad del checkout servido se comprueba antes de generar carga. Los comandos usan únicamente el clon aislado en `127.0.0.1:18411`. Genera el resumen en `tests/performance/.local/`.

La evaluación histórica descrita a continuación usó directamente la aplicación aislada en
`http://127.0.0.1:18381`, sin el proxy adicional de la primera evaluación.
El router local aplica gzip a HTML, JSON, CSS y JavaScript y caducidad a los
recursos estáticos, como el servidor Apache. Conserva los encabezados de caché
privados de Drupal. La configuración versionada activa la agregación CSS/JS.

## Reproducir la URL del usuario

Con la copia de integración preparada, los usuarios de prueba sembrados y Chrome
instalado, usar `tests/e2e/support/local-lab/router.php` como router del servidor
local. Si la copia existente usa `.e2e-lab/tools`, actualizar allí el router.
Confirmar que los overrides de `settings.php` también permiten la agregación;
reconstruir las cachés después de copiar el código.

Se puede usar una instalación temporal y fijada de Lighthouse:

```sh
npm install --ignore-scripts --prefix /tmp/labdoo-lighthouse-13 lighthouse@13.6.0
python3 tests/performance/lighthouse.py \
  --base-url http://127.0.0.1:18381 \
  --path '/es/content/dootronics-dashboard?palette=ocean' \
  --lighthouse /tmp/labdoo-lighthouse-13/node_modules/.bin/lighthouse \
  --output /tmp/labdoo-lighthouse --runs 3
```

`--path` permite reproducir una URL concreta, incluyendo su consulta. Si no se
indica, se miden las cuatro rutas representativas definidas en el script.
`--profiles mobile` limita la ejecución al móvil.

El runner comprueba HTTP 200 y `X-Labdoo-E2E: integration`, prepara los agregados
del servidor y después ejecuta las auditorías de forma secuencial, con navegador
nuevo en cada ejecución. No precarga la caché del navegador, acepta cookies antes
de medir ni sustituye la red externa. Los datos de mapas y la analítica permanecen
operativos. Informar la mediana y todas las ejecuciones; no seleccionar la mejor.

## Regresión

Desde `tests/e2e` de la copia aislada:

```sh
E2E_RUN_ID=lighthouse-regression npm test -- education-quotes.spec.ts theme-palette.spec.ts theme-preview.spec.ts theme-performance.spec.ts --project=integration --project=mobile --no-deps
```

78 casos comprueban los veinte idiomas, el respaldo sin JavaScript, las frases
con el JavaScript de Drupal bloqueado, el tamaño compacto del widget, las paletas,
los formularios, la navegación, las galerías, los mapas y las trayectorias.
Se verifica expresamente que un mapa fuera de pantalla no descargue Leaflet ni
sus puntos antes del desplazamiento y que se inserten los 2.000 puntos de prueba.

## Informes y límites

[RESULTS.md](RESULTS.md) contiene la evaluación actual de la URL exacta.
Los JSON publicados solo incluyen puntuaciones, métricas y metadatos; los
informes completos quedan privados, porque pueden incluir parámetros de terceros.
Son resultados locales, no una verificación del servidor AWS con autenticación.

`before.json` y `after.json` conservan la primera evaluación incluida en el commit
`4ef2f78d`, realizada con Lighthouse 12.8.2 y el proxy `serve.py` en el puerto
18385. Esos archivos documentan una revisión anterior; sus condiciones no deben
mezclarse con las de la medición directa actual. El proxy se conserva para
reproducir ese histórico, usando `--base-url http://127.0.0.1:18385`.

## Current-checkout validation

Routine runs use this checkout at `http://127.0.0.1:18411` with the isolated E2E site. The older port 18381 and results above describe historical measurements, not the default target. k6 and Lighthouse reject a local server belonging to another checkout. Direct k6 calls must pass `-e LABDOO_EXPECTED_ROOT="/absolute/path/to/checkout"`; the Makefile and dashboard supply it.

Follow [tests/AGENTS.md](../AGENTS.md) for mandatory testing. Inspect k6 thresholds and failed checks; inspect Lighthouse `summary.json`, failed audits and same-condition before/after metrics. A successful Lighthouse process is not an automatic regression pass. Keep all repeated runs and report medians rather than the best score.

## Open global-search dialog

`node tests/performance/global-search-audit.mjs PRIVATE_OUTPUT` audits the open
global-search dialog on this checkout's isolated site at port 18411. It records
three fresh-browser desktop/mobile accessibility snapshots for the empty dialog
and real autocomplete suggestions, plus private screenshots and JSON/HTML reports.
It verifies the checkout identity and blocks external browser requests. Keep its
output under the ignored `.local` directory: search suggestions may contain
private fixture content. These snapshots complement navigation audits and the
interaction tests in `tests/e2e/global-search.spec.ts`; they do not measure page
loading performance or replace manual keyboard/visual checks.

The following exercises the actual autocomplete endpoint discovered from the
guarded homepage:

```sh
k6 run --summary-export tests/performance/.local/k6-global-search.json \
  -e LABDOO_EXPECTED_ROOT=/absolute/path/to/checkout \
  tests/performance/global-search-load.js
```

It uses five users for 30 seconds and retains the standard latency, HTTP error
and functional/isolation budgets. It checks positive indexed suggestions for
Dootrip and Dootronics. This checks backend stability; browser tests cover the
client's debounce, cancellation, failure recovery and suggestion selection.

## Platform uptime statistics

`platform-uptime-load.js` adds focused coverage for the statistics route, which
is outside the five routes in the general load profile. It alternates English
and Spanish with five users for 30 seconds, preserving the standard latency,
HTTP error and all-checks-passing budgets. It verifies the checkout identity
and the rendered platform age. Run it only against the isolated clone:

```sh
k6 run --summary-export tests/performance/.local/k6-platform-uptime.json \
  -e LABDOO_EXPECTED_ROOT=/absolute/path/to/checkout \
  tests/performance/platform-uptime-load.js
```

This checks the existing page with its normal caching, not every database
fallback under load. `tests/e2e/platform-uptime.spec.ts` separately exercises
the actual fallback and empty aggregate queries without changing stored data,
and checks the page in both languages on desktop and mobile. The focused
profile complements the general load profile; it is not a maximum-capacity
test or a controlled performance comparison.

## Migrated destination content

`migration-destination-load.js` reads an owned fixture created by
`tests/e2e/support/migration-destination-contract.php` in the isolated clone.
Pass its node ID through `LABDOO_MIGRATION_NODE_ID`, its unique fixture token
through `LABDOO_MIGRATION_TEST_TOKEN`, and this checkout path through
`LABDOO_EXPECTED_ROOT`. Keep the fixture until the run finishes, then invoke the
same helper's `cleanup` operation with the same token. The helper's supported
operations use `LABDOO_MIGRATION_TEST_OP=create` or `cleanup` and the guarded
`tests/upgrade/.local/tools/drush.py integration php:script` launcher.

The profile uses five users for 30 seconds and checks both language URLs,
persisted title/body values, published comment content and checkout isolation.
It also requires unpublished comments to remain hidden from anonymous visitors.
The browser test verifies both comments with its authorized account. Standard latency,
HTTP-error and all-checks-passing budgets remain unchanged. This checks normal
read caching after mapping; save failure/retry behavior is covered by unit
tests. It does not load-test V2 sources, write throughput or external services.

## Notification previews

`notifications-load.js` exercises English and Spanish contact email previews
with five users for 30 seconds. It checks checkout isolation, localized
acknowledgements, preserved message content and resolved tokens, using the same
latency, HTTP-error and all-checks-passing thresholds as the general profile.
It performs read-only preview requests; mail delivery and provider throughput
are outside this profile. Browser coverage is in
`tests/e2e/notification-preview.spec.ts` for both desktop and mobile.

```sh
k6 run --summary-export tests/performance/.local/k6-notifications.json \
  -e LABDOO_EXPECTED_ROOT=/absolute/path/to/checkout \
  tests/performance/notifications-load.js
```

## Action stream and owner filter

`actions-load.js` alternates English and Spanish action listings, with and
without `mine=1`, using five anonymous users for 30 seconds. It retains the
standard p95, HTTP-error and all-checks-passing thresholds and verifies both
checkout/isolation headers and rendered activity. Run it only against this
checkout's isolated clone:

```sh
k6 run --summary-export tests/performance/.local/k6-actions.json \
  -e LABDOO_EXPECTED_ROOT=/absolute/path/to/checkout \
  tests/performance/actions-load.js
```

Authenticated owner filtering and persisted generator output are checked by
`tests/e2e/action-contract.spec.ts`, including all four source node bundles,
navigation and English/Spanish desktop/mobile states. The focused HTTP profile
uses ordinary page caching; it does not measure creation throughput or replace
the unchanged general maximum-concurrency profile.
