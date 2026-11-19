# Purga Técnica de Bundles Migrados

Este documento detalla el uso del comando `labdoo_migrate:purge-bundle`, diseñado para acelerar el borrado masivo de nodos migrados en Labdoo v3 mediante operaciones SQL directas.

## ¿Cuándo usarlo?

Este comando debe usarse **exclusivamente** para limpiar datos migrados descartables (como el bundle `action`) que no requieren la ejecución del ciclo de vida completo de la entidad (hooks, indexación de búsqueda, etc.).

**ADVERTENCIA:** Este comando realiza borrados directos en la base de datos. No es una eliminación funcional de producción. Use con precaución.

## Comandos Recomendados

### Simulación (Dry Run)
Antes de ejecutar, siempre es recomendable ver qué se va a borrar y qué tablas se verán afectadas:

```bash
vendor/bin/drush labdoo_migrate:purge-bundle node action --dry-run
```

### Ejecución Real (con confirmación)
```bash
vendor/bin/drush labdoo_migrate:purge-bundle node action
```

### Ejecución Masiva (sin confirmación)
```bash
vendor/bin/drush labdoo_migrate:purge-bundle node action --yes
```

### Uso desde Makefile
Se han añadido objetivos al Makefile para facilitar la integración con todos los bundles:

```bash
# Borrado de un bundle específico (pide confirmación)
make delete-action-fast
make delete-hub-fast
make delete-edoovillage-fast
make delete-dootronic-fast
make delete-dootrip-fast
make delete-gallery-fast
make delete-story-fast
make delete-page-fast
make delete-teams-fast

# Borrado sin confirmación
make delete-action-fast-no-confirm
# ... etc ...

# Borrado de un bundle arbitrario
make delete-bundle-fast bundle=mi_bundle

# Borrado masivo de TODO lo migrado (usando SQL rápido)
make delete-all
```

## Parámetros

- `entity_type`: Actualmente solo soporta `node`.
- `bundle`: El tipo de contenido a purgar (ej: `action`, `hub`, `edoovillage`).
- `--chunk`: (Opcional, defecto 5000) Número de entidades a procesar por lote de transacción.
- `--dry-run`: (Opcional) No realiza cambios, solo informa.
- `--yes`: (Opcional) Salta las confirmaciones interactivas.

## Validación

Para validar que el bundle ha quedado vacío, puede ejecutar:

```bash
vendor/bin/drush sqlq "SELECT COUNT(*) FROM node_field_data WHERE type = 'action';"
```

El resultado debería ser `0`.

## Reconstrucción de Caché

El comando invalida el tag de caché `node_list` y ofrece ejecutar `drush cr` al finalizar. Si el borrado fue muy masivo, es altamente recomendable ejecutar:

```bash
vendor/bin/drush cr
```

## Recuperación

Si algo sale mal, la única forma de recuperar los datos borrados por este comando es restaurar un backup previo de la base de datos:

```bash
make import file=backups/tu_backup.sql.gz
```
