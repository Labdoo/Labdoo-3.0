# Plan revisable de sustitución de Actions

Preparado el 10/11/2026. Política elegida por el cliente: el histórico de V2 es autoritativo. Este documento y los CSV describen una propuesta; NO se ha ejecutado.

| Operación propuesta | Registros | Condición |
|---|---:|---|
| Importar histórico V2 | 157608 | Huellas individuales y revisión de dependencias antes de guardar |
| Conservar NID original de la Action V2 | 157607 | NID actualmente libre en V3; verificar de nuevo al ejecutar |
| Asignar un NID alternativo a una Action | 1 | Action V2 #232258 colisiona con página de V3; conservar página y suUUID |
| Archivar Actions regeneradas cargables V3 | 34232 | Despublicar conservando NID,UUID, revisiones y referencias; tras verificar importación |
| Conservar Action V3 no cargable para revisión | 1 | NID 283457: Drupal no la carga; no archivarla automáticamente |

No se deben borrar ni renumerar nodos existentes. La página #232258 se conserva; la alternativa de la Action necesita un mapping y una revisión de sus enlaces. La Action #283457 queda fuera del archivado automático hasta revisar su integridad.

El inventario ha leído 157608 Actions V2 y 34233 registros V3 en 157,326 s, pico de 86,5 MiB. Ha emitido 4115 SELECT/lecturas en fuente y 1359 en destino, sin consultas de escritura. Nodos 112238, revisiones 247026, MAX(nid) 723678, secuencia 723679, MAX(vid) 1248262 y secuencia 1248263 permanecen iguales. La fuente mantiene los mismos conteos y techo al terminar; no equivale a un snapshot global congelado.

Los CSV contienen IDs, UUID, revisiones y huellas, sin cuerpos, nombres ni emails. `source-actions.csv.gz` permite revisar cada alta y la colisión; `v3-actions.csv.gz` contiene el archivado propuesto y la excepción. `plan-reviewed.jsonl.gz` combina ambas listas. Su SHA256 se comprueba contra `review-summary.json`.

Antes de ejecutar: respaldo nuevo validado; estabilizar V2 o revalidar cada huella y hacer pasadas finales; resolver usuarios/nodos requeridos; comprobar publicación, idiomas, geometría, enlaces e índice; reservar un ID alternativo para la colisión; importar y verificar primero las Actions V2 y sólo entonces archivar las regeneradas mediante un manifiesto explícito con posibilidad de reversión. El bloqueo automático de Actions del motor permanece activo. Este plan no autoriza ni implementa una fusión heurística de acontecimientos.

Artefactos completos: directorio privado `/mnt/data/labdoo-actions-review-20261110-r2` del servidor V3. El inventario se preparó con el código `859f4c872827`; un futuro manifiesto de ejecución debe regenerar y validar el contexto del código desplegado entonces.
