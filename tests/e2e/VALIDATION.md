# Validación E2E de MEJORAS

Ejecución del 8 de noviembre de 2026 tras corregir las acciones del historial: **98 pruebas aprobadas, 0 fallos, 0 omitidas y 0 reintentos**. Duración: 428 s. Las 38 tarjetas tienen cobertura de navegador aprobada. Traducciones permanece excluida.

Esta ejecución incorpora tres pruebas de permisos con revisiones históricas a las 95 comprobaciones anteriores, incluidas la regresión del filtro y la migración de direcciones con el dump V2 real.

| Proyecto | Pruebas aprobadas |
|---|---:|
| Autenticación: siete perfiles por copia, condiciones y sesión real | 2 |
| Regresión en development (1582fd13) | 17 |
| Integración de las mejoras y regresión del filtro y de las acciones del historial | 67 |
| Flujo de direcciones desde V2 real: 12 registros | 1 |
| Tamaño móvil 390 × 844 | 11 |

Chrome/Chromium en escritorio y emulación móvil. Se ejecutaron formularios de clonación y actualización mediante JSON, persistencia de pesos wiki, navegación dentro de libros, paginación de 53 fotos, ordenación en ambos sentidos de las 31 columnas de los cuatro paneles, generación de PDF de etiquetas y borrado de escuelas/galerías antes y después de ejecutar la cola.

## Correcciones detectadas y publicadas

| Rama | Corrección | Commit |
|---|---|---|
| `feature/wiki-sequential-navigation` | Anterior/siguiente en el modo full usado por la página. | [2b88ef98](https://github.com/Labdoo/Labdoo-3.0/commit/2b88ef98e8a1191d3ec0b62e394fdc3d594774c4) |
| `feature/wiki-add-subpage-access` | Conservar bloques laterales de renderizado diferido. | [cff62672](https://github.com/Labdoo/Labdoo-3.0/commit/cff6267211e7ab1ffe3e87282d3ec58e6bcd0eaa) |
| `feature/team-nice-urls` | Conservar bloques diferidos y variar bloques vacíos por ruta, sesión y permisos. | [7dca4142](https://github.com/Labdoo/Labdoo-3.0/commit/7dca4142844f403831e8820b6ee95a9273e8fca1) |
| `feature/dootrip-semaphore-size` | Mantener el tamaño frente a gin-custom.css. | [5dec38a9](https://github.com/Labdoo/Labdoo-3.0/commit/5dec38a94dcd1ec1569c44de2bc8405886514c38) |
| `feature/bulk-print-labels` | Aplicar la presentación de impresión a las rutas de selección y compatibilidad. | [19f3566d](https://github.com/Labdoo/Labdoo-3.0/commit/19f3566d9a2d1ff2e589ac539892585850499792) |
| `feature/dootronic-text-address` | Leer solo la ubicación de la revisión V2 actual e invalidar las entradas de caché anteriores al guardar entidades migradas. | [525dd145](https://github.com/Labdoo/Labdoo-3.0/commit/525dd145ac3148d3fd7e6ba7039c9e950fab14cf) |
| `feature/dootronics-dashboard-filter-404` | Alinear el identificador del título usado por el filtro en la vista principal, tabla y mapa. | [77ac7a49](https://github.com/Labdoo/Labdoo-3.0/commit/77ac7a493ed9c9b7f6cc204d84dbf7ac351c7f69) |
| `feature/dootrip-public-revisions` | Respetar el retorno booleano del control de acceso para ocultar Revert/Delete a usuarios sin permisos. | [1b87e048](https://github.com/Labdoo/Labdoo-3.0/commit/1b87e048eb0ce7e39560987b336ea3f95b8f09af) |

## Verificación con el dump V2 real

- Archivo aportado: `/var/www/html/labdoo2/labdoo.sql`, 3.938.964.295 bytes; fecha de generación indicada en el dump: **15/01/2024 18:18:31**.
- SHA-256: `cab14d1b2caa18bdc883ce56af664ffa3a6050826730cc69afa3e73f340631f9`.
- Las 512 tablas se importaron en una base separada, `labdoo_e2e_v2_20261107`. El importador solo puede escribir en esa base; el lector de migración tiene exclusivamente SELECT y sesión de solo lectura. La conexión se sustituye solo dentro del proceso CLI de pruebas.
- El inventario contiene 47,761 dootronics publicados; 28,550 tienen varias filas de ubicación. El SQL de referencia selecciona la ubicación de la revisión actual.
- Se usaron los servicios reales SourceRepository y DestinationRepository, las cuatro reglas JSON de direcciones, la conversión de componentes y el guardado real en la copia V3. Se contrastaron los componentes persistidos con SQL independiente y después con el HTML de las fichas.
- Se comprobaron 12 registros distintos: direcciones completas de CH, DE, ES, US, FR, GB, AT, CN e IN; un registro sin código postal; otro sin calle; y otro con historial de ubicaciones.
- La prueba reprodujo primero una dirección vacía: la consulta sin filtrar revisión devolvía listas de valores históricos que el conversor escalar descartaba. Las cuatro reglas ahora unen también `node.vid = location_instance.vid`.
- Tras corregir la consulta, la prueba detectó valores correctos en SQL pero una ficha antigua en navegador. El migrador deshabilitaba la caché persistente y Drupal omitía por ello la eliminación de entradas previas. El guardado ahora invalida la entrada de la entidad y conserva la política de caché del proceso. La prueba precarga una ficha sin dirección antes de migrarla y verifica después su actualización en navegador.
- Los registros ya migrados se actualizan al repetir la migración del campo con la corrección; esta ejecución solo guarda muestras en la copia V3 aislada.
- La copia base y los registros originales de los fixtures se conservan. El alcance de esta comprobación V2 es el flujo de direcciones de dootronics; no certifica una migración completa de todos los tipos de contenido.

## Regresión del 404 del filtro

El filtro combinado referenciaba el campo interno `title`, ausente en la tabla que usa `title_3`. Drupal marcaba la construcción de la vista como fallida y respondía HTTP 404 al recibir `combine`. Se alinearon las referencias y el identificador del título en la vista del mapa, sin cambiar los datos ni el criterio de búsqueda.

Las cuatro pruebas nuevas comprueban: resultado correcto en las vistas principal/tabla/mapa; búsqueda por ID y conservación del filtro al ordenar y abrir la ficha; búsqueda sin coincidencias con HTTP 200 y lista vacía; envío y limpieza del formulario desde navegador. El caso original `/en/content/dootronics-dashboard?combine=000063348` devuelve HTTP 200 en integración.

La corrección está publicada desde `development` en `feature/dootronics-dashboard-filter-404`. Para aplicarla al desplegar se debe importar la configuración de la vista y reconstruir caché. La copia base de control permanece sin cambios.

## Acciones del historial de revisiones

La revisión manual detectó que un anónimo veía Revert/Delete en revisiones históricas, aunque las rutas de acción respondían 403. El controlador de acceso personalizado devolvía un objeto incluso cuando Drupal solicitaba un booleano; el constructor de los botones interpretaba ese objeto como verdadero. Ahora se devuelve `isAllowed()` por defecto y se conserva el objeto con sus metadatos cuando se solicita explícitamente.

La prueba anterior usaba un dootrip con una única revisión actual y no ejercitaba las operaciones de revisiones antiguas. Se añaden tres casos con un dootrip aislado de dos revisiones: anónimo y usuario básico pueden leer el historial y la revisión anterior, no ven acciones y reciben 403 al abrir sus rutas; el administrador conserva las acciones y puede abrir la confirmación sin enviarla. Una sesión anónima independiente comprueba después que los controles del administrador no se filtren entre sesiones.

## Cobertura por tarjeta

Corrección posterior en la revisión manual [28/38]: el dootrip existente 16086 tenía un equipo vinculado pero el contador almacenado estaba vacío y Drupal omitía el campo. `feature/dootrip-display-order`, commit `501234ce`, añade un respaldo de visualización: cuando el contador está vacío y el campo está habilitado en la vista, usa el número de referencias a equipos con el formato y posición configurados. No guarda cambios ni modifica contadores existentes.

La ejecución `dootrip-counter-fixed` aprobó **11/11 pruebas**, sin omisiones ni reintentos: tres casos nuevos (contador ausente con cero/un equipo y el dootrip existente), orden de campos, formulario y permisos de historial/revisiones. El fallo de la ficha existente se reprodujo primero en `dootrip-counter-reproduction-ready`. Se comprobó después que la lectura no rellenó el contador persistido. No se ha repetido la suite completa para esta corrección.

Corrección posterior en la revisión manual [25/38]: el álbum del clon se creaba, pero el bloque de acciones almacenado antes de ejecutar la cola no mostraba **Go to photo album**. Se añaden dependencias de caché del nodo, de la lista de galerías y del álbum encontrado para invalidar el bloque al crear, modificar o borrar galerías. Publicado en `feature/edoovillage-clone-album`, commit `34408224`.

La prueba ampliada reprodujo primero la ausencia del enlace (`clone-album-link-reproduction`). Tras corregirlo, `clone-album-link-fixed` aprobó **12/12 pruebas**, sin omisiones ni reintentos: autenticación, clonación/borrado antes y después de la cola, seguimiento del enlace en la misma sesión con el bloque precargado y regresiones de escuelas en escritorio/móvil. La prueba anterior comprobaba las entidades, pero no recargaba el bloque después de crear el álbum. No se ha repetido la suite completa para esta corrección.

Ampliación posterior del 08/11/2026: la revisión manual [22/38] solicitó un visor modal para las fotos. Se añadió a `feature/gallery-thumbnail-grid` en el commit `cc6e8508`. La ejecución específica `gallery-lightbox` aprobó **10/10 pruebas**, sin omisiones ni reintentos: autenticación, cinco casos nuevos de visor/fallback y cuatro comprobaciones previas de galería. Incluye originales cargados, navegación con botones y teclado, Escape y devolución del foco, cierre por botón/fondo, paginación, escritorio, móvil y enlaces sin JavaScript. La suite completa de 98 pruebas indicada arriba corresponde al estado anterior a esta ampliación; no se ha repetido íntegramente para el visor.

| Tarjeta | Rama | Resultado | Casos anotados |
|---|---|---|---:|
| Naming de Edoovillages | `feature/edoovillages-naming` | Aprobado | 1 |
| Read the story about this edoovillage | `feature/hub-story-label` | Aprobado | 1 |
| Ocultar botón preview en gallery | `feature/gallery-hide-preview` | Aprobado | 1 |
| Ubicación de estadísticas | `feature/statistics-position` | Aprobado | 2 |
| Visualización de tags | `feature/wiki-inline-tags` | Aprobado | 2 |
| Tamaño imagen de semáforo | `feature/dootrip-semaphore-size` | Aprobado | 2 |
| Ocultar "Add subpage" para anónimo | `feature/wiki-add-subpage-access` | Aprobado | 2 |
| Eliminar bloque Happening Right Now de la página de contacto | `feature/contact-hide-activity` | Aprobado | 2 |
| Eliminar "Happening right now" de la wiki | `feature/wiki-hide-activity` | Aprobado | 2 |
| Color párrafo wiki | `feature/wiki-text-color` | Aprobado | 2 |
| Reducir longitud horizontal de la wiki | `feature/wiki-reading-width` | Aprobado | 2 |
| Edoovillage Photo Album field should be hidden | `feature/edoovillage-hide-album` | Aprobado | 1 |
| Ocultar campos en la edición de edoovillage | `feature/edoovillage-hide-album-edit` | Aprobado | 1 |
| Navegación de gallery | `feature/gallery-parent-navigation` | Aprobado | 1 |
| Mensajes al clonar y actualizar dootronics masivamente | `feature/dootronics-bulk-feedback` | Aprobado | 1 |
| Mensaje de confirmación al clonar | `feature/dootronics-confirmation-message` | Aprobado | 1 |
| Being able to order wiki pages | `feature/wiki-page-order` | Aprobado | 1 |
| Orden de las subpáginas | `feature/wiki-subpage-order` | Aprobado | 1 |
| We can't go to the next wiki page | `feature/wiki-sequential-navigation` | Aprobado | 1 |
| Rediseño de la vista de dootronics | `feature/dootronic-inline-fields` | Aprobado | 2 |
| Dootronics (campos de objetos) | `feature/object-inline-fields` | Aprobado | 2 |
| Descripción de campos en dootronics | `feature/dootronic-field-descriptions` | Aprobado | 1 |
| Edoovillages (campos sin descripción) | `feature/edoovillage-field-descriptions` | Aprobado | 1 |
| Descripción del campo "Announce this dootrip" | `feature/dootrip-announcement-description` | Aprobado | 1 |
| Habilitar revisiones de dootrips | `feature/dootrip-public-revisions` | Aprobado | 4 |
| Nice URLs | `feature/team-nice-urls` | Aprobado | 1 |
| Color de título en Teams | `feature/team-title-color` | Aprobado | 2 |
| Rediseño de edoovillage | `feature/edoovillage-single-page` | Aprobado | 2 |
| Rediseño de gallery | `feature/gallery-thumbnail-grid` | Aprobado | 2 |
| Dootronics (show textual address) | `feature/dootronic-text-address` | Aprobado | 3 |
| Edoovillage photo album deletion | `feature/edoovillage-album-cleanup` | Aprobado | 2 |
| Edoovillage cloning | `feature/edoovillage-clone-album` | Aprobado | 2 |
| Columnas ordenables | `feature/dashboard-sortable-columns` | Aprobado | 4 |
| Orden de los campos de dootrips | `feature/dootrip-form-order` | Aprobado | 1 |
| Orden de los campos de dootrips en la vista | `feature/dootrip-display-order` | Aprobado | 1 |
| Gráfico de hub | `feature/hub-node-chart` | Aprobado | 1 |
| Vista de Labdoo stories | `feature/stories-list` | Aprobado | 1 |
| Imprimir multiples etiquetas | `feature/bulk-print-labels` | Aprobado | 5 |

## Alcance

- Dos bases V3 y copias de archivos independientes; la cuenta V3 de pruebas solo tiene permisos en las copias. Las ramas permanecen para revisión, sin despliegue ni fusión en development.
- Correo en colector local y geocodificación controlada. Las imágenes externas de mapas/QR son sustituidas: se comprueban destinos y composición de etiquetas, no la lectura óptica del QR del proveedor.
- La cobertura de regresión comprueba los flujos enumerados; no certifica todas las funcionalidades, navegadores o combinaciones del sitio.

## Evidencias y reproducción

Artefactos privados: `.e2e-lab/state/runs/final-mejoras-revision-controls/` (`playwright-report/index.html`, `playwright-results.json`, `card-coverage.json`, `card-coverage.md` y PDF). Metadatos del dump y muestras reales: `.e2e-lab/state/v2-import-complete.json` y `legacy-address-fixtures.json`. Los valores personales, el SQL, las credenciales y los informes completos permanecen fuera de Git.

Una ejecución anterior quedó interrumpida al detenerse los servidores locales con SIGTERM (74 pruebas aprobadas y 17 errores de conexión). Sus evidencias se conservan en `final-mejoras-v2-real-server-interrupted/`. Tras reiniciar ambos servidores se repitió íntegramente la suite; la repetición completa de 91 pruebas pasó y las ejecuciones posteriores ampliaron el total a 95 y después a 98.

Véase [README.md](README.md). El proyecto opcional `legacy-v2` permite repetir la comprobación real; `support/coverage.py final-mejoras-revision-controls` verifica las 38 tarjetas. Las tareas siguen en IN REVIEW.
