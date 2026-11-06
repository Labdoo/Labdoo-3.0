# Validación E2E de MEJORAS

Ejecución del 6 de noviembre de 2026: **90 pruebas aprobadas, 0 fallos, 0 omitidas y 0 reintentos**. Duración: 6 min 24 s. Las 38 tarjetas tienen al menos una comprobación de navegador aprobada. Traducciones permanece excluida.

| Proyecto | Pruebas aprobadas |
|---|---:|
| Autenticación: siete perfiles por copia, con condiciones y sesión real | 2 |
| Regresión en development (1582fd13) | 17 |
| Integración de las mejoras | 60 |
| Tamaño móvil 390 × 844 | 11 |

Las pruebas usan Chrome/Chromium en escritorio y emulación móvil. Se han ejecutado formularios reales de clonación y actualización mediante JSON, persistencia del peso de páginas wiki, navegación dentro de libros, paginación de 53 fotos, ordenación en ambos sentidos de las 31 columnas de los cuatro paneles, generación de PDF de etiquetas y borrado de escuelas con sus galerías antes y después de ejecutar la cola.

## Correcciones detectadas y publicadas

| Rama | Corrección | Commit |
|---|---|---|
| `feature/wiki-sequential-navigation` | Generar anterior/siguiente en el modo full usado por la página. | [2b88ef98](https://github.com/Labdoo/Labdoo-3.0/commit/2b88ef98e8a1191d3ec0b62e394fdc3d594774c4) |
| `feature/wiki-add-subpage-access` | Conservar los bloques laterales que Drupal renderiza de forma diferida. | [cff62672](https://github.com/Labdoo/Labdoo-3.0/commit/cff6267211e7ab1ffe3e87282d3ec58e6bcd0eaa) |
| `feature/team-nice-urls` | Conservar bloques diferidos y variar los bloques vacíos por ruta, sesión y permisos. | [7dca4142](https://github.com/Labdoo/Labdoo-3.0/commit/7dca4142844f403831e8820b6ee95a9273e8fca1) |
| `feature/dootrip-semaphore-size` | Evitar que gin-custom.css vuelva a ampliar el semáforo a 200 px. | [5dec38a9](https://github.com/Labdoo/Labdoo-3.0/commit/5dec38a94dcd1ec1569c44de2bc8405886514c38) |
| `feature/bulk-print-labels` | Aplicar la plantilla y estilos de impresión a las rutas de selección y compatibilidad. | [19f3566d](https://github.com/Labdoo/Labdoo-3.0/commit/19f3566d9a2d1ff2e589ac539892585850499792) |

## Cobertura por tarjeta

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
| Habilitar revisiones de dootrips | `feature/dootrip-public-revisions` | Aprobado | 1 |
| Nice URLs | `feature/team-nice-urls` | Aprobado | 1 |
| Color de título en Teams | `feature/team-title-color` | Aprobado | 2 |
| Rediseño de edoovillage | `feature/edoovillage-single-page` | Aprobado | 2 |
| Rediseño de gallery | `feature/gallery-thumbnail-grid` | Aprobado | 2 |
| Dootronics (show textual address) | `feature/dootronic-text-address` | Aprobado | 2 |
| Edoovillage photo album deletion | `feature/edoovillage-album-cleanup` | Aprobado | 2 |
| Edoovillage cloning | `feature/edoovillage-clone-album` | Aprobado | 2 |
| Columnas ordenables | `feature/dashboard-sortable-columns` | Aprobado | 4 |
| Orden de los campos de dootrips | `feature/dootrip-form-order` | Aprobado | 1 |
| Orden de los campos de dootrips en la vista | `feature/dootrip-display-order` | Aprobado | 1 |
| Gráfico de hub | `feature/hub-node-chart` | Aprobado | 1 |
| Vista de Labdoo stories | `feature/stories-list` | Aprobado | 1 |
| Imprimir multiples etiquetas | `feature/bulk-print-labels` | Aprobado | 5 |

## Alcance y pendientes

- Se trabajó con dos bases de datos y copias de archivos independientes. La cuenta MySQL de pruebas solo tiene permisos en las copias. No se ha desplegado ni fusionado en development.
- El correo permanece en el colector local y la geocodificación devuelve valores controlados. Las imágenes externas de mapas/QR son sustituidas; se comprueban los destinos de los QR y la composición de las etiquetas, no la lectura óptica del código generado por el proveedor.
- El mapeo de direcciones usa la configuración y los servicios reales de migración, persiste los cuatro componentes y verifica su visualización en navegador con entradas controladas. **La migración desde datos V2 reales no queda certificada**: los dos servicios Docker de ~/Projects/labdoo2 están arrancados, pero la base labdoo del volumen labdoo2_dbData tiene 0 tablas y su web devuelve HTTP 500. Hace falta la copia SQL o el volumen correcto para esa comprobación.
- Un sondeo adicional de /en/content/dootronics-dashboard?combine=000063348 devuelve HTTP 404 tanto en baseline como en integración. Se documenta como comportamiento pendiente de diagnóstico del entorno/base existente; no forma parte de las 90 comprobaciones aprobadas y no se atribuye a estas mejoras.
- La cobertura de regresión comprueba los flujos enumerados; no certifica todas las funcionalidades, navegadores o combinaciones posibles del sitio.

## Evidencias y reproducción

Los artefactos privados permanecen en `.e2e-lab/state/runs/final-mejoras/`: `playwright-report/index.html`, `playwright-results.json`, `card-coverage.json`, `card-coverage.md`, `additional-probes.json` y PDF de etiquetas. Los informes pueden contener información de la copia de datos y no se publican en Git.

El código de pruebas y las instrucciones están en [README.md](README.md). `support/coverage.py final-mejoras` valida que las 38 tarjetas estén cubiertas y aprobadas. Las tareas permanecen en IN REVIEW para revisión y despliegue.
