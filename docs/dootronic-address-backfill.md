# Recuperar las direcciones de dootronics existentes

La mejora de presentación ya estaba desplegada, pero `field_address` no se había rellenado en los registros existentes. Este comando recupera exclusivamente direcciones vacías de nodos `dootronic` ya presentes.

Se requiere una conexión `external` a V2, preferiblemente con permiso SELECT exclusivamente. No importa nodos ni ejecuta la sincronización geográfica general. Lee una sola ubicación de la revisión actual de V2; omite fuentes ausentes, ambiguas o con país desconocido. Conserva los componentes parciales y no sustituye direcciones ya pobladas.

```sh
vendor/bin/drush cr
vendor/bin/drush labdoo:backfill-dootronic-addresses --nids=1829
vendor/bin/drush labdoo:backfill-dootronic-addresses
vendor/bin/drush labdoo:backfill-dootronic-addresses --apply --backup-path=/home/ubuntu/dootronic-address-before-YYYYMMDD.jsonl
```

Sin `--apply` solo calcula los cambios. `--limit` limita los nodos examinados, no el número de direcciones recuperables. El respaldo debe ser una ruta nueva y se crea con permisos 0600; contiene datos personales y debe quedar fuera del repositorio y del directorio público. Un fallo al escribirlo detiene el proceso.

La escritura se limita a las tablas del campo de dirección y su revisión actual con inserciones por lotes de 100 nodos y el mapeo de tablas de Drupal. No guarda la entidad completa: no ejecuta notificaciones, acciones o colas, no cambia fecha/título/ID público, no crea nodos ni revisiones y conserva revisiones históricas. Invalida las etiquetas de caché de cada nodo actualizado. Repetir el comando no reemplaza lo ya recuperado. Cada lote es transaccional; una revisión o dirección cambiada por otro usuario durante el proceso detiene el lote para evitar sobrescribirla.

Validación local: `tests/e2e/address-backfill.spec.ts` ejecuta el comando real sobre nodos existentes y una copia V2 con SELECT. Contrasta doce casos, incluidos direcciones parciales e historial, verifica respaldo privado, simulación, repetición y estabilidad del número y máximo ID de nodos y revisiones. Las fichas se comprueban en escritorio y móvil.
