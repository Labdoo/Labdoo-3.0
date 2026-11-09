# Sincronización incremental de nodos V2 a V3

La nueva familia `labdoo-delta:*` conserva lo migrado y distingue altas, cambios efectivos, nodos intactos, cambios exclusivos de V3, conflictos y fuentes desaparecidas. No se ejecuta desde cron. Las bajas se informan por defecto: nunca se borran nodos automáticamente.

El alcance son los nueve tipos de nodos configurados: laptop, edoovillage, dootrip, hub, action, labdoo_story, team, team_page y team_task. Los idiomas existentes y las secciones de historias forman parte del contenido; no se generan traducciones. Usuarios, configuración, menús, aliases, comentarios, bytes de archivos y revisiones históricas completas requieren sus procesos específicos. No se puede presentar este comando como una réplica automática de todo el portal.

## Inventario y detección

El lector consulta V2 únicamente mediante SELECT, en páginas keyset de hasta 500 raíces. Incluye campos actuales, familias de traducción, localizaciones del VID vigente y referencias de ficheros y secciones. Los hashes detectan cambios aunque `node.changed` permanezca igual. El coste de las consultas es por tabla y lote, no por campo de cada nodo.

Los destinos se resuelven mediante tracker y `field_d7_nid`; igualdad numérica de NID no prueba identidad. Las colisiones, mappings contradictorios y Actions regeneradas sin identidad se bloquean antes de crear duplicados. Las referencias a usuarios y otros nodos también exigen identidad probada. Deben sincronizarse las dependencias antes de aplicar un nodo que las necesite.

Una baseline conserva las huellas de fuente y de los campos de destino que gestiona el mapping. Los campos propios de V3 ajenos al mapping se preservan. Los campos derivados explícitamente calculados por V3 no generan conflictos falsos ni se importan repetidamente desde los contadores de V2. La identidad, idiomas y contenido autoritativo permanecen protegidos.

En la primera ejecución no existe una baseline histórica: los nodos semánticamente iguales se adoptan escribiendo sólo metadatos; los diferentes se clasifican `update_candidate`. No se puede deducir con certeza quién editó primero una divergencia antigua. `--source-wins` permite adoptar esas diferencias iniciales después de revisarlas; nunca supera una colisión de identidad, datos no verificables o un conflicto posterior entre ambos lados.

Con baseline válida, los nodos sin cambios se omiten por lotes sin guardarlos ni releer individualmente la fuente durante apply. Si la fuente cambia pero la proyección efectiva sigue igual, sólo se actualiza la baseline. Las modificaciones exclusivamente locales se preservan sin rebaselinar y ocultar su origen. Cambios reales sólo en V2 se aplican; cambios incompatibles en ambos lados quedan bloqueados.

## Preparación

La instalación añade únicamente tres tablas de manifiestos, operaciones y baselines mediante `labdoo_migrate_update_9003`. No modifica nodos ni ejecuta una migración. Antes de una ejecución con contenido, guardar respaldo SQL y archivos; mantener los escritores V3 y los importadores antiguos detenidos durante la ventana de corte. Los comandos delta comparten un lock renovable y las rutas legacy se protegen frente a ese lock.

Sincronizar primero los archivos físicos mediante el procedimiento rsync revisado, preservando las protecciones de V3. El adaptador reutiliza los FID existentes; crea metadata nueva sólo durante apply si el original público ya está copiado y coincide en tamaño, MIME y ruta segura. No copia binarios ni convierte archivos privados en públicos. Cambios binarios sin cambio de metadata requieren validación independiente de archivos.

## Comandos

Previsualización sin manifiestos, contenido, invalidaciones de caché persistente ni adquisición de locks de escritura:

```sh
drush labdoo-delta:preview all --batch-size=100 --max-items=500
```

`max-items` limita las filas inventariadas por tipo y por llamada, incluidas las comprobaciones de bajas. Un preview parcial no prueba que el inventario esté completo. `--max-items=0` recorre todo el tipo.

Crear manifiesto, conservando su ID devuelto como RUN_ID:

```sh
drush labdoo-delta:plan laptop --batch-size=100 --max-items=1000
drush labdoo-delta:plan laptop --run=RUN_ID --max-items=1000
drush labdoo-delta:status RUN_ID
drush labdoo-delta:items RUN_ID --operation=update_candidate --limit=100
drush labdoo-delta:items RUN_ID --operation=conflict --after=0 --limit=100
```

Repetir `plan --run` hasta `phase=planned`. El manifiesto conserva su techo original y cursor; una exploración parcial no permite aplicar bajas. Cambios de conexión, normalización, mapping o configuración relevante invalidan el manifiesto. Si cambia la pertenencia de nodos durante el inventario se exige un nuevo plan.

Aplicar operaciones revisadas y reanudar:

```sh
drush labdoo-delta:apply RUN_ID --max-items=100
drush labdoo-delta:apply RUN_ID --max-items=100
drush labdoo-delta:status RUN_ID
```

Las primeras diferencias sin baseline necesitan autorización explícita de precedencia de V2:

```sh
drush labdoo-delta:apply RUN_ID --source-wins --max-items=100
```

Las operaciones confirmadas no se repiten. Cada escritura y checkpoint se confirman en la misma transacción. Se verifican otra vez identidad y hashes antes de aplicar y la fuente después de escribir. Un fallo revierte la operación, conserva la evidencia y sale con error; no marca el nodo como sincronizado. Tras corregir un fallo transitorio puede usarse `--retry-errors`. Si cambiaron los datos hay que generar otro manifiesto, no forzar un plan obsoleto.

## Bajas

La opción habitual informa de fuentes desaparecidas sin alterar V3. Retipados o raíces convertidas en traducción son conflictos, no bajas. La despublicación explícita conserva NID, UUID, historial y relaciones:

```sh
drush labdoo-delta:apply RUN_ID --missing-source=unpublish --max-items=100
```

Si no existe baseline fiable, se requiere además `--source-wins`. Un origen vacío o un conjunto de bajas superior al umbral conservador requiere `--confirm-mass-unpublish` después de comprobar que la fuente está completa. No se debe usar esa opción para eludir un dump parcial. Los cambios locales protegidos siguen bloqueados.

Una baja confirmada deja un tombstone con el hash resultante: repetirla no crea otra revisión y, si reaparece la fuente, puede restaurarse el mismo nodo sin duplicar su identidad. La despublicación mantiene la semántica actual de los repositorios de Labdoo; no equivale a eliminar un dootronic de todos los contadores por estado S4/T1.

## Derivados y comprobación final

Tras aplicar las operaciones, encolar los trabajadores existentes sólo para destinos realmente escritos y sus relaciones antiguas/nuevas:

```sh
drush labdoo-delta:enqueue-derived RUN_ID --limit=100
```

Repetir hasta `remaining=0`. El finalizador usa evidencias `written` y `effects` guardadas en la misma transacción de apply. Conserva los IDs anteriores de viajes y dispositivos, y checkpointa conjuntamente el encolado. No regenera galerías ni acciones. Los contadores de hubs y escuelas afectados se reparan directamente en sus filas actuales y revisión vigente, incluidas las antiguas relaciones que quedaron vacías. Se conservan demanda autoritativa y revisiones históricas; before/after quedan registrados. Las demás colas se encolan, no se ejecutan desde este comando: hay que comprobar su finalización antes del corte.

Verificar cantidades, identidades, publicación, referencias, idiomas, ficheros, contadores e índice de búsqueda. Repetir el inventario con fuente estable debe omitir todo lo equivalente, dejando únicamente los conflictos explícitos. Una V2 que siga cambiando necesita otra pasada: las dos bases no constituyen un snapshot global común. No se promete igualdad final mientras existan cambios concurrentes o conflictos sin resolver.

Los títulos/cuerpos que V3 tenga configurados como compartidos no pueden recibir valores distintos por idioma silenciosamente. Se listan como datos no verificables y requieren revisar la configuración de traducción antes del corte. Las referencias ausentes, cardinalidades incompatibles y datos ambiguos también se conservan para revisión. No se inventan valores ni entidades dependientes.

## Pruebas y rendimiento

`tests/incremental-sync-checklist.md` detalla las baterías aisladas y sus comandos. Cubren SQL SELECT-only, páginas/techo, cambios sin timestamp, traducciones, archivos, historias, conflictos, colisiones, bajas/tombstones, races, rollback, límites y reanudación. Los tests de escrituras se ejecutan exclusivamente contra bases `labdoo_e2e_*`; no son comandos de prueba para producción.

La muestra local de 500 raíces sintéticas requirió ocho SELECT y aproximadamente 0,04 segundos. El preview combinado contra la fuente V2 autorizada, desde un destino mínimo aislado, revisó los nueve tipos con límites 200/500 sin escrituras SQL en ambas bases. Es una medición del inventario, no del coste de guardar entidades ni de la duración completa de la migración. Se deben medir throughput, memoria y fallos de la ejecución real sobre una copia representativa antes del corte definitivo.
