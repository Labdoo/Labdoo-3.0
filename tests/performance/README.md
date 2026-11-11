# Auditorías de Labdoo Next

La medición actual usa directamente la aplicación aislada en
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
  --path '/es/content/dootronics-dashboard?palette=ocean&theme=labdoo_next' \
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
