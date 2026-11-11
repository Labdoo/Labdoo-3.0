# Labdoo education quotes

El módulo sustituye la cita fija de Labdoo Next y ofrece el bloque reutilizable **Education quote**. Incluye seis citas con autor y fuente, y veinte catálogos `.po`: ar, bs, ca, de, en, es, fr, hy, it, ja, nl, pl, ro, ru, sr, sw, tr, uk, zh-hans y zh-hant. Las traducciones son propias del proyecto; no se presentan como traducciones oficiales de las obras.

## Carga y caché

El HTML contiene una cita de respaldo traducida. Es cacheable por idioma. Al cargar la página, el comportamiento Drupal solicita `GET /labdoo/education-quote/{langcode}` mediante `fetch` con `cache: 'no-store'`. El endpoint tiene `no_cache: TRUE`, activa el interruptor de caché de Drupal y devuelve `Cache-Control: private, no-store, max-age=0, must-revalidate`. Así, la frase puede cambiar aunque el HTML de la página se reutilice desde caché. No hace falta desactivar la caché del resto de la página ni depender de BigPipe para usuarios anónimos.

El navegador conserva solo el ID anterior en `sessionStorage` y lo envía como `exclude`; el servidor selecciona otra cita. También se renueva al regresar desde la caché de navegación del navegador (BFCache). Los comportamientos AJAX de la misma página no provocan peticiones duplicadas. No hay carrusel ni cambios periódicos durante la lectura.

Si JavaScript está desactivado, el endpoint falla o no hay red, permanece visible la cita traducida de respaldo. Si el navegador bloquea el almacenamiento, la selección sigue funcionando, pero no puede recordar la frase de la página anterior. El módulo no solicita contenido a servicios externos: los enlaces del autor permiten consultar las fuentes.

El proxy/CDN debe respetar `no-store` o excluir `/labdoo/education-quote/` de su caché. No convertir esta ruta en una respuesta pública con TTL forzado.

## Instalación y traducciones

```sh
vendor/bin/drush en labdoo_quotes -y
vendor/bin/drush cr
```

La instalación importa inmediatamente los `.po` de los idiomas habilitados. Al añadir uno de los idiomas soportados, su catálogo se importa también. El contexto exclusivo es `Labdoo education quotes`; las traducciones se pueden personalizar en la interfaz de traducción de Drupal. Las importaciones conservan las traducciones marcadas como personalizadas.

El importador utiliza el lector PO y el escritor de traducciones de Drupal para cadenas singulares. Deliberadamente no entrega las cabeceras al escritor: el lector genera una fórmula de plural predeterminada incluso cuando falta `Plural-Forms`. Las fórmulas globales del sitio permanecen intactas y no se refrescan traducciones de configuración desde este importador.

Para actualizar traducciones después de cambiar los archivos:

```sh
vendor/bin/drush php:eval '\Drupal::service("labdoo_quotes.importer")->import();'
vendor/bin/drush cr
```

No se crean nodos ni entidades de contenido. El bloque solo se coloca automáticamente dentro de la cabecera de Labdoo Next; en otros temas puede colocarse con la administración de bloques. El tema conserva su cita anterior si se deshabilita el módulo. Para conservar la activación tras futuras importaciones de configuración, exportar y revisar `core.extension` desde el entorno de configuración habitual, nunca desde la base aislada de pruebas.

## Fuentes

Las citas y sus referencias están en `quotes.json`. Se conservan las palabras de los originales ingleses, con fragmentos identificados cuando corresponde:

- Nelson Mandela: [discurso de inauguración de Mindset Network, 16/07/2003](https://www.mandela.gov.za/mandela_speeches/2003/030716_mindset.htm). La versión base usa «we can use».
- Helen Keller: [Optimism: An Essay, 1903](https://www.gutenberg.org/cache/epub/31622/pg31622-images.html).
- Confucio: [Analectas II.15, traducción de Leonard A. Lyall, 1909](https://www.gutenberg.org/cache/epub/24055/pg24055-images.html).
- Aristóteles: [Metafísica I.1, traducción de W. D. Ross](https://classics.mit.edu/Aristotle/metaphysics.1.i.html).
- John Dewey: [My Pedagogic Creed, artículo II, 1897](https://cyc-net.org/cyc-online/cycol-0502-dewey.html), fragmento de la frase que comienza «I believe that…».
- Horace Mann: [Twelfth Annual Report, 1848](https://ushistory.education/historical-documents/horace-mann-twelfth-annual-report/), fragmento de la frase sobre la educación como igualadora social.

Para añadir una cita, actualizar `quotes.json` y sus entradas de texto y autor en los veinte `.po`, conservando este contexto y las fuentes. Ejecutar la auditoría y las pruebas antes de importar.

## Comprobaciones

`tests/quotes-catalog.php` valida los veinte catálogos con el lector Gettext de Drupal, comprueba las 120 frases y sus autores contra el servicio de traducción, e importa de nuevo para comprobar que no cambien las reglas de plurales. Ejecutar con `drush php:script` en la copia aislada.

`tests/e2e/education-quotes.spec.ts` verifica los veinte idiomas en el endpoint, las cabeceras de caché, exclusión de la frase anterior, reutilización del mismo HTML cacheado, BFCache, idiomas inválidos, métodos de escritura, errores de red, respaldo sin JavaScript y tamaños de escritorio/móvil.

Validación del 11/11/2026: **64/64 E2E aprobadas**, sin fallos, omisiones ni reintentos (`education-quotes`), junto con la auditoría de los veinte PO. `tests/quotes-import.php` comprueba en la base aislada que las personalizaciones sobrevivan a una reimportación y restaura el texto original al terminar. Conservados los nodos, sus IDs, el tema predeterminado y las reglas de plurales anteriores a la instalación.
