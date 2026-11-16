# Resultado de la actualización a Drupal 11

Rama: `upgrade/drupal-11`, creada desde `origin/development` (`20ebdeb3`). Trabajo probado en un worktree y una base MySQL aislados; no se modificó la base ni los archivos del sitio de origen.

## Suite E2E

| Etapa | Drupal | Casos aprobados | Omitidos | Fallidos | Duración |
|---|---:|---:|---:|---:|---:|
| Antes | 10.5.2 | 425 | 22 | 0 | 14 min 33 s |
| Después | 11.4.12 | 425 | 22 | 0 | 13 min 59 s |

Comparación por nombre de caso: **0 regresiones, 0 casos ausentes**. Los 22 omitidos son enlaces externos, enlaces de marcador, acciones administrativas/destructivas y otros destinos que no son páginas navegables; cada omisión lleva su motivo en el informe.

La huella de archivos de prueba es distinta entre etapas porque, tras la referencia D10, se hicieron dos ajustes de compatibilidad de los selectores de mensajes con el marcado de Drupal 11 y se explicitó la respuesta 404 del antiguo formulario RSS. No se añadieron ni quitaron casos: las 447 identidades de prueba están en ambas ejecuciones. La interfaz marca esta diferencia de huella para dejarla visible.

| Ejecución | Versión | Huella de suite | Estado |
|---|---:|---|---|
| `before-1794598683387` | 10.5.2 | `ae06051ec2e6552fcbf561dbcb3d51267f505fc60ec243badc1616aa739b3773` | Salida 0 |
| `after-1794601856408` | 11.4.12 | `eef0572e6fc0b6733f67d6e409120e9d14e8ea14f2308ec297cee4d447309739` | Salida 0 |

Los informes Playwright, trazas y comparación quedan en `tests/upgrade/.local/state/runs/`. La base de referencia D10 comprimida queda en `tests/upgrade/.local/state/drupal10-before-upgrade.sql.gz`; estos archivos están ignorados por Git y pueden contener datos privados copiados del sitio.

## Integridad de datos

Se tomaron los recuentos antes de la primera prueba y después de las actualizaciones Drupal 11, antes de volver a preparar usuarios de prueba. Los recuentos coincidieron en todas las tablas comprobadas:

- 112.339 nodos, 243.732 revisiones de nodo y 8.026 usuarios.
- 35.245 medios, 62.224 archivos administrados y 303 párrafos.
- 553 términos, 117 páginas wiki, 223 revisiones wiki, 1.908 comentarios y 19 envíos Webform.
- 33.009 registros de dirección.

Las verificaciones detalladas están en `integrity-before.json` y `integrity-after.json` dentro del estado privado.

## Cobertura preparada

El inventario previo al cambio contiene 216 entradas de menú, 13 bundles de nodo, 20 tipos de entidad de contenido y 20 idiomas. La suite ejecuta altas, lecturas, ediciones y bajas en formularios Drupal; también recorre los menús, idiomas, búsqueda, paneles, Webform, comentarios, equipo, revisiones, archivos, mapas, tema y vistas móviles.

Las pruebas usan correo y geocodificación locales, bloquean conexiones salientes y simulan la respuesta de YouTube oEmbed. No validan el envío real a proveedores de correo, CAPTCHA, AWS, mapas ni YouTube.

## Cambios de compatibilidad

- Drupal 11 exige PHP 8.3; `.ddev/config.yaml` usa PHP 8.3 y MySQL 8.0.
- Antes de reemplazar el código D10 se desinstalaron `variationcache` y `webform_bootstrap`; ya no están en Composer ni en `core.extension.yml`.
- `labdoo_dootronics` usa el validador `FileExtension` para cargas JSON de Drupal 11. La prueba masiva encontró y verificó la corrección del validador anterior que daba 500.
- El perfil AWS declara un constructor de vista para que Drupal registre la ruta canónica que requiere su listado.
- Al editar una historia, el servicio conserva el padre existente de la historia si la URL no incluye `parent_id`.
- El antiguo formulario de configuración RSS devuelve 404 en Drupal 11.3 y posterior: Drupal retiró ese formulario y la vista RSS del núcleo. El inventario registra la respuesta esperada. [Registro de cambio de Drupal](https://www.drupal.org/node/3519410).
- `drupal/diff` quedó en 2.1.1 y `firebase/php-jwt` en 7.2.1. `composer audit --locked` no encontró avisos de seguridad para el lock final. La actualización de Diff incorpora la corrección del [aviso SA-CONTRIB-2026-096](https://www.drupal.org/sa-contrib-2026-096); la versión anterior de JWT estaba afectada por [GHSA-2x45-7fc3-mxwq](https://github.com/advisories/GHSA-2x45-7fc3-mxwq).
- `drupal/customerror` sólo publica esta compatibilidad en `1.x-dev`; `composer.lock` fija el commit probado `2dc9066`. Composer sigue informando de `doctrine/annotations` y `doctrine/common` como paquetes abandonados; se conservan por dependencias actuales del código/contrib y no presentan avisos de seguridad en el audit final.

## Abrir el panel local

Desde la raíz del worktree `upgrade/drupal-11`, instala Playwright la primera vez y ejecuta:

```sh
npm ci --prefix tests/e2e
python3 tests/upgrade/lab.py serve
```

En otra terminal:

```sh
node tests/upgrade/dashboard.mjs
```

Abre `http://127.0.0.1:18412`. Tanto el panel como el Drupal de pruebas (`127.0.0.1:18411`) escuchan exclusivamente en loopback. Más información y los comandos de reconstrucción están en [README.md](README.md).
