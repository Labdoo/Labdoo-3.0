# Traducciones portables de configuración y menús

El módulo `labdoo_translations` incorpora un catálogo versionado de **1.417 textos únicos en 20 idiomas**: `en`, `es`, `ar`, `ca`, `zh-hans`, `zh-hant`, `nl`, `fr`, `de`, `it`, `ja`, `pl`, `ru`, `sr`, `sw`, `uk`, `hy`, `bs`, `ro` y `tr`. Este número corresponde al inventario de configuración y menús utilizado para esta entrega; no significa que cualquier texto futuro quede traducido automáticamente.

El alcance incluye los valores traducibles de configuración identificados mediante su esquema, las opciones y etiquetas de formularios web y los títulos y descripciones de menús. También incorpora textos de definiciones estáticas de menú mediante el servicio de traducción de Drupal. No traduce el contenido editorial de nodos, historias, comentarios ni archivos, ni crea traducciones de esas entidades. No modifica identificadores, UUID, rutas, relaciones o la estructura de los menús.

## Catálogo y precedencia

Los archivos están en `web/modules/custom/labdoo_translations/catalog/`:

- `source.json`: textos originales, contexto, inventario y metadatos canónicos de plurales.
- `LANG.json`: traducciones para cada idioma y justificaciones `unchanged` de las coincidencias legítimas con el original, como marcas, unidades y código.

La identidad de un texto se calcula a partir del contexto y del texto original. No utiliza identificadores de entidades ni UUID de un entorno. El mismo catálogo puede instalarse en otra copia compatible del portal.

Se conserva la traducción nativa válida y distinta del original. Cuando falta, es una copia del original o no supera las comprobaciones de integridad correspondientes, se utiliza el catálogo. Si tampoco existe una traducción válida en el catálogo, permanece el texto original. Los enlaces de menú de contenido resuelven título y descripción por separado, sin guardar las entidades. El idioma de interfaz y la huella del catálogo forman parte de la variación de caché.

La validación comprueba los marcadores de sustitución, tokens de Drupal, expresiones Twig, estructura HTML y atributos funcionales. Para YAML se conserva la estructura de claves y los tipos de valores utilizando el mismo analizador Symfony que Drupal. Los plurales utilizan el número de formas y las reglas de cada idioma; no se presupone que todos tengan dos formas. El texto visible `{Empty}` es una excepción explícita de etiqueta, no un marcador de sustitución.

Estas comprobaciones protegen la integridad técnica. Se recomienda una revisión lingüística humana de la precisión, el tono y la terminología antes de considerar definitivo el texto de cada idioma.

## Validación y transferencia entre entornos

Ejecutar desde la raíz del proyecto, con las dependencias Composer instaladas. Antes de importar, copiar al destino **la misma versión del código del módulo y de este script**; un paquete de catálogos no instala el código necesario para interpretarlo.

```sh
php scripts/labdoo-translations-catalog.php check
php scripts/labdoo-translations-catalog.php export --file=/ruta/segura/labdoo-translations.json
```

La exportación crea un archivo nuevo y falla si ya existe. Contiene `source.json` y los 20 catálogos, con sus sumas SHA-256. Para instalar el paquete en el destino:

```sh
php scripts/labdoo-translations-catalog.php import --file=/ruta/segura/labdoo-translations.json
vendor/bin/drush en labdoo_translations -y
vendor/bin/drush labdoo-translations:prepare --apply
vendor/bin/drush cr
vendor/bin/drush labdoo-translations:check --details
```

La importación valida primero un directorio temporal y conserva el catálogo anterior en `catalog-backup-FECHA-SUFIJO`, junto al directorio `catalog`. Devuelve su ruta como `backup_directory`. Si falla el reemplazo del directorio, intenta restablecer el anterior. No importa entidades ni escribe contenido en la base de datos.

El módulo prepara las reglas de plurales al instalarse. El comando `labdoo-translations:prepare` sin `--apply` muestra el plan sin modificar el estado. Con `--apply` actualiza las reglas de los idiomas configurados y conserva exactamente el estado anterior en una clave `labdoo_translations.plural_backup.HASH`; la salida identifica esa clave como `backup_key` cuando hay cambios. Guardar esa salida con el registro del despliegue. Las lecturas normales de traducción no ejecutan esta preparación.

Después de importar o actualizar archivos PO, o de sustituir los catálogos, repetir:

```sh
vendor/bin/drush labdoo-translations:prepare --apply
vendor/bin/drush cr
vendor/bin/drush labdoo-translations:check --details
```

La comprobación Drush es de solo lectura. Informa de la cobertura efectiva de configuración y menús, la integridad del catálogo y los textos ausentes. Una salida con errores o cobertura incompleta debe revisarse antes de dar por validado el despliegue.

## Reversión operativa

Conservar el paquete previo, la ruta `backup_directory`, la clave de respaldo de plurales y un respaldo de la base de datos previo al despliegue. Registrar también si el módulo estaba habilitado inicialmente.

Para revertir únicamente los textos, mantener el código compatible e importar el paquete anterior con el mismo comando `import`. Si solo se conserva el directorio de respaldo, se puede exportar directamente desde él y volver a importarlo; usar un archivo de exportación nuevo:

```sh
php scripts/labdoo-translations-catalog.php export --catalog=/ruta/al/catalog-backup-FECHA-SUFIJO --file=/ruta/segura/labdoo-translations-anterior.json
php scripts/labdoo-translations-catalog.php import --file=/ruta/segura/labdoo-translations-anterior.json
vendor/bin/drush labdoo-translations:prepare --apply
vendor/bin/drush cr
vendor/bin/drush labdoo-translations:check --details
```

Para retirar el comportamiento del módulo si se instaló en esta entrega, desinstalarlo **antes de retirar su código** y reconstruir las cachés:

```sh
vendor/bin/drush pm:uninstall labdoo_translations -y
vendor/bin/drush cr
```

Esto devuelve la resolución de textos a los mecanismos nativos de Drupal. No restaura por sí solo el estado anterior de plurales. Si es necesario revertir también ese estado, seleccionar la clave de respaldo de la operación que se está deshaciendo, no cualquier respaldo posterior. Un operador puede inspeccionar las claves disponibles con esta lectura:

```sh
vendor/bin/drush php:eval '$all = \Drupal::keyValue("state")->getAll(); foreach ($all as $key => $value) { if (str_starts_with($key, "labdoo_translations.plural_backup.")) { echo $key . " " . ($value["created"] ?? "") . PHP_EOL; } }'
```

Tras sustituir `CLAVE_DEL_RESPALDO` por la clave comprobada, la siguiente operación restaura tanto el valor anterior como su ausencia, si originalmente no existía:

```sh
vendor/bin/drush php:eval '$store = \Drupal::keyValue("state"); $snapshot = $store->get("CLAVE_DEL_RESPALDO"); if (!is_array($snapshot) || !array_key_exists("had_value", $snapshot) || !array_key_exists("formulae", $snapshot)) { throw new \RuntimeException("Respaldo de plurales ausente o inválido"); } if ($snapshot["had_value"]) { $store->set("locale.translation.formulae", $snapshot["formulae"]); } else { $store->delete("locale.translation.formulae"); } \Drupal::state()->resetCache();'
vendor/bin/drush cr
```

Coordinar la restauración con las importaciones PO y otros cambios de idiomas: restaurar un estado antiguo también puede deshacer reglas cambiadas posteriormente. Si no hay una instantánea verificable, utilizar el respaldo previo de la base de datos mediante el procedimiento habitual del entorno; no adivinar las reglas. Para una reversión completa, comprobar después una página y un plural representativo en cada idioma relevante.

## Mantenimiento del inventario

Un texto nuevo, un cambio del texto original o un contexto diferente necesita incorporarse al inventario y traducirse en una futura actualización de los catálogos. Las traducciones no se generan durante las peticiones del portal. Versionar juntos el inventario, los catálogos y el código que los interpreta; volver a ejecutar las comprobaciones de integridad, cobertura efectiva y plurales antes de distribuir una nueva entrega.
