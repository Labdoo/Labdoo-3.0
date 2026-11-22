# Labdoo education quotes

El módulo sustituye la cita fija de Labdoo Next y ofrece el bloque reutilizable
**Education quote**. Incluye seis citas con autor y fuente, y veinte catálogos
`.po`: ar, bs, ca, de, en, es, fr, hy, it, ja, nl, pl, ro, ru, sr, sw, tr, uk,
zh-hans y zh-hant. Las traducciones son propias del proyecto; no se presentan
como traducciones oficiales de las obras.

## Loading and caching

The language-cacheable HTML contains a translated fallback and a safely
JSON-encoded
catalog of the six translated choices. The early head script prepares the
catalog;
the inlined quote observer chooses and displays one as soon as its markup is
complete.
Normal page loads need neither an uncached quote request nor a blocking
quote-script download. The browser remembers only the
previous quote ID in `sessionStorage` and excludes it from the next choice.
Selection also runs after BFCache restoration, without a carousel or periodic
changes during reading. Multiple widgets share one selection per endpoint.

Without JavaScript the translated fallback remains visible. If storage is
blocked,
selection still works but cannot remember the preceding page. Text and author
are
updated together, using text nodes and validated HTTPS source links.

Older cached HTML without an embedded catalog retains the early request to
`GET /labdoo/education-quote/{langcode}` with `cache: 'no-store'` and an
`exclude`
parameter. Network failure or an eight-second timeout restores its fallback.
The endpoint remains private and uncached (`no_cache: TRUE`, Drupal cache kill
switch, and `Cache-Control: private, no-store, max-age=0, must-revalidate`).
Proxies/CDNs must respect that policy; never force a public TTL on this
endpoint.
No external content provider is called by the module.

## Instalación y traducciones

```sh
vendor/bin/drush en labdoo_quotes -y
vendor/bin/drush cr
```

La instalación importa inmediatamente los `.po` de los idiomas habilitados. Al
añadir uno de los idiomas soportados, su catálogo se importa también. El
contexto exclusivo es `Labdoo education quotes`; las traducciones se pueden
personalizar en la interfaz de traducción de Drupal. Las importaciones conservan
las traducciones marcadas como personalizadas.

El importador utiliza el lector PO y el escritor de traducciones de Drupal para
cadenas singulares. Deliberadamente no entrega las cabeceras al escritor: el
lector genera una fórmula de plural predeterminada incluso cuando falta
`Plural-Forms`. Las fórmulas globales del sitio permanecen intactas y no se
refrescan traducciones de configuración desde este importador.

Para actualizar traducciones después de cambiar los archivos:

```sh
vendor/bin/drush php:eval '\Drupal::service("labdoo_quotes.importer")->import();'
vendor/bin/drush cr
```

No se crean nodos ni entidades de contenido. El bloque solo se coloca
automáticamente dentro de la cabecera de Labdoo Next; en otros temas puede
colocarse con la administración de bloques. El tema conserva su cita anterior si
se deshabilita el módulo. Para conservar la activación tras futuras
importaciones de configuración, exportar y revisar `core.extension` desde el
entorno de configuración habitual, nunca desde la base aislada de pruebas.

## Fuentes

Las citas y sus referencias están en `quotes.json`. Se conservan las palabras de
los originales ingleses, con fragmentos identificados cuando corresponde:

- Nelson Mandela: [discurso de inauguración de Mindset Network,
  16/07/2003](https://www.mandela.gov.za/mandela_speeches/2003/030716_mindset.htm).
  La versión base usa «we can use».
- Helen Keller: [Optimism: An Essay,
  1903](https://www.gutenberg.org/cache/epub/31622/pg31622-images.html).
- Confucio: [Analectas II.15, traducción de Leonard A. Lyall,
  1909](https://www.gutenberg.org/cache/epub/24055/pg24055-images.html).
- Aristóteles: [Metafísica I.1, traducción de W. D.
  Ross](https://classics.mit.edu/Aristotle/metaphysics.1.i.html).
- John Dewey: [My Pedagogic Creed, artículo II,
  1897](https://cyc-net.org/cyc-online/cycol-0502-dewey.html), fragmento de la
  frase que comienza «I believe that…».
- Horace Mann: [Twelfth Annual Report,
  1848](https://ushistory.education/historical-documents/horace-mann-twelfth-annual-report/),
  fragmento de la frase sobre la educación como igualadora social.

Para añadir una cita, actualizar `quotes.json` y sus entradas de texto y autor
en los veinte `.po`, conservando este contexto y las fuentes. Ejecutar la
auditoría y las pruebas antes de importar.

## Comprobaciones

`tests/quotes-catalog.php` valida los veinte catálogos con el lector Gettext de
Drupal, comprueba las 120 frases y sus autores contra el servicio de traducción,
e importa de nuevo para comprobar que no cambien las reglas de plurales.
Ejecutar con `drush php:script` en la copia aislada.

`tests/e2e/education-quotes.spec.ts` verifica los veinte idiomas en el endpoint,
las cabeceras de caché, exclusión de la frase anterior, reutilización del mismo
HTML cacheado, BFCache, idiomas inválidos, métodos de escritura, errores de red,
respaldo sin JavaScript y tamaños de escritorio/móvil.

Validación del 11/11/2026: **64/64 E2E aprobadas**, sin fallos, omisiones ni
reintentos (`education-quotes`), junto con la auditoría de los veinte PO.
`tests/quotes-import.php` comprueba en la base aislada que las personalizaciones
sobrevivan a una reimportación y restaura el texto original al terminar.
Conservados los nodos, sus IDs, el tema predeterminado y las reglas de plurales
anteriores a la instalación.

The invisible marker at the end of the author tells the observer when parsing
has completed. It also discovers widgets inserted by AJAX. The E2E suite covers
selection from the cached catalog without a network request, nonrepetition,
BFCache, all twenty endpoint languages, legacy cached HTML and network failure,
no-JavaScript fallback, RTL, and desktop/mobile rendering.
