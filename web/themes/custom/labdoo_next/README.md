# Labdoo Next

Tema experimental basado en `labdoo`, con una cabecera propia, navegación con teclado, métricas agrupadas, colores verdes y naranja de la marca, superficies cálidas, tipografía del sistema y tablas desplazables en pantallas estrechas. Hereda los formatos de contenido, mapas, galerías y permisos existentes. No incorpora fuentes externas ni dependencias de frontend nuevas.

## Activación

Desplegar los archivos de esta rama y habilitar el módulo opcional:

```sh
vendor/bin/drush en labdoo_theme_preview -y
vendor/bin/drush cr
```

La instalación habilita `labdoo_next`. Drupal copia las posiciones de bloques del tema predeterminado, conservando regiones, configuración y permisos. Son entidades de configuración: no crea nodos ni consume sus identificadores. El tema predeterminado sigue siendo `labdoo`; no ejecutar `config:set system.theme default labdoo_next` para probarlo.

Abrir cualquier página pública añadiendo `?theme=labdoo_next`, o `&theme=labdoo_next` si ya tiene otros parámetros:

```text
/en/content/dootronics-dashboard?theme=labdoo_next
/en/node/749?theme=labdoo_next
/en/content/labdoo-social-network-how-it-works?theme=labdoo_next
/en/node/748?theme=labdoo_next
```

El enlace «Back to original» elimina exclusivamente ese parámetro. No se usan cookies ni preferencias de usuario para seleccionar el tema. Los enlaces internos y filtros GET mantienen el parámetro mediante JavaScript; sin JavaScript se puede probar cada página añadiéndolo explícitamente. Los enlaces externos, las rutas administrativas y los documentos de impresión/exportación conservan su comportamiento habitual.

El módulo es opcional y no se añade automáticamente a `core.extension` de este repositorio. Si el despliegue importa configuración, habilitarlo después de la importación para una prueba puntual. Para conservarlo en siguientes importaciones, exportar en el entorno de configuración `core.extension` y los bloques `labdoo_next_*` generados, revisándolos antes de incorporarlos a Git. No exportar configuración desde la base de datos de E2E.

## Caché y CDN

El selector acepta únicamente el nombre exacto del tema instalado. Parámetros desconocidos o arrays no seleccionan otro tema. Las páginas añaden el contexto de caché `url.query_args:theme`. Las respuestas del nuevo tema son privadas y `no-store`; el selector no cambia permisos ni configura el tema globalmente.

CloudFront u otro proxy debe reenviar `theme` al origen e incluirlo en su clave de caché, o desactivar caché para las solicitudes de vista previa. Cambiar solo la aplicación no basta si el CDN elimina el parámetro o sirve una respuesta anterior sin consultar el origen. Para comprobar la selección, la respuesta del tema nuevo incluye `X-Labdoo-Theme: labdoo_next`.

## Referencias y decisiones visuales

- [DesignPrompts](https://designprompts.dev/): referencias orientadas a agentes, especialmente Field Report, Allotment y Village Hall. Se adaptan la jerarquía de cifras, las superficies cálidas y el tono comunitario al contenido real de Labdoo.
- [MaxiBestOf, Non-profit](https://maxibestof.one/websites/non-profit): referencias de organizaciones con impacto social; se priorizan sus resultados y contenido frente a elementos decorativos.
- [Vercel Web Interface Guidelines](https://github.com/vercel-labs/web-interface-guidelines): navegación con teclado, foco visible, controles móviles, estado en la URL y respeto a movimiento reducido.

Se conserva el logotipo existente y el significado de los estados de los equipos. Todos los textos nuevos pasan por la traducción de Drupal. El CSS se limita al tema nuevo; no modifica archivos del tema `labdoo`.

## Comprobaciones

Las pruebas `tests/e2e/theme-preview.spec.ts` se ejecutan sobre la copia local aislada, con el módulo habilitado. Cubren panel, escuela, hub, dootrip, wiki, galería y equipo; escritorio y móvil; métricas sin cifras partidas; navegación, filtros y ordenación; retorno al estilo habitual; sesiones independientes; parámetros inválidos; lightbox; navegación sin JavaScript; y separación de administración e impresión. Antes y después comparan el número de nodos, su máximo ID y el tema predeterminado.

```sh
E2E_RUN_ID=theme-next npm test -- theme-preview.spec.ts regression.spec.ts dashboard-search.spec.ts gallery-lightbox.spec.ts --project=integration --project=mobile
```

La comprobación visual de mapas valida su estructura; las pruebas aisladas controlan las solicitudes externas y no certifican el proveedor de mapas. Este tema no corrige ni migra los datos ausentes detectados en producción.

Resultados del 08/11/2026: **50/50 pruebas aprobadas**, sin omisiones ni reintentos, en `theme-next-final`; tras ajustar el formato de las cifras en móvil, **17/17 comprobaciones responsive aprobadas** en `theme-next-responsive-final`. PHP, YAML y JavaScript validados; PHPCS Drupal/DrupalPractice sin errores en el módulo, helpers del tema y CSS nuevos. Capturas revisadas en 1440 px y 390 px. Los artefactos completos y los datos del laboratorio permanecen fuera de Git.
