# Origen y destino legibles en dootrips

El formatter anterior omitía todo el campo cuando Google no devolvía resultados. En v3 los registros de Geocoder muestran rechazos por restricciones de referer de la clave usada desde el servidor. No se cambia esa clave ni se amplían sus permisos.

El formatter `labdoo_dootrip_location` conserva los proveedores configurados y añade recuperación de textos V2 para trayectos existentes. Guarda datos derivados en la colección key/value `labdoo_dootrip_location_labels`, sin crear entidades de contenido. Cada texto se vincula al nid, campo y valor exacto de coordenadas. Si se cambia la ubicación, el texto antiguo deja de utilizarse. Esta colección persiste tras `drush cr`.

```sh
vendor/bin/drush updatedb -y
vendor/bin/drush cr
vendor/bin/drush labdoo:backfill-dootrip-location-text --nids=16086,16136
vendor/bin/drush labdoo:backfill-dootrip-location-text
vendor/bin/drush labdoo:backfill-dootrip-location-text --apply
```

El update 9002 cambia únicamente el formatter de origen y destino en la vista predeterminada; conserva el resto de ajustes y el orden de campos. La configuración exportada incluye el mismo cambio. La recuperación requiere la conexión V2 `external` y lee solo las tablas actuales de origen/destino. Omite fuentes ambiguas o cuyas coordenadas no coincidan; no supone que una dirección antigua siga siendo válida. Repetirla es idempotente.

Sin etiqueta V2 se intenta la geocodificación habitual. Si el proveedor sigue fallando, se muestran coordenadas con etiqueta legible y caché breve (300 s), evitando que desaparezcan origen/destino. Obtener una dirección de calle para ubicaciones nuevas requiere un proveedor operativo y una clave apta para consultas del servidor.

No se guardan nodos durante la recuperación ni al leer las fichas: no cambian títulos, fechas, relaciones, contadores, nid, ID público o revisiones. La escritura derivada invalida caché de las fichas afectadas.

Validación: `tests/e2e/dootrip-location-text.spec.ts` recupera dos dootrips existentes desde la copia V2 de solo lectura y verifica el orden origen/salida/destino/llegada, el contador de asignados, repetición y estabilidad de IDs. Simula respuesta vacía y excepción del proveedor, así como coordenadas cambiadas, sin llamadas externas.
