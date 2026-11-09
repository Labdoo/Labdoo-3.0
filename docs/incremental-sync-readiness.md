# Preparación de sincronización incremental V2 → V3

Auditoría realizada el 09/11/2026 sobre el código desplegado y las conexiones configuradas. No se han sincronizado, creado ni borrado nodos. El inventario de sólo lectura ejecutado en v3 tardó 4,642 segundos, con 23.068.672 bytes de memoria máxima en el proceso completo de Drush. Lee metadatos en lotes de 500, sin cargar entidades completas.

## Dictamen

El módulo permite reaprovechar lo migrado, pero **todavía no garantiza dejar V3 exactamente como V2 mediante una ejecución incremental segura y reanudable**. No hay que empezar desde cero; hace falta cerrar las diferencias de selección, identidad, comprobación de cambios y recuperación antes del corte.

`labdoo-sync --incremental` selecciona altas sin seguimiento válido. No actualiza automáticamente las entidades ya migradas que hayan cambiado en V2. `--mode=update --from-date` filtra contenido de origen por fecha, pero no sustituye un inventario de diferencias: primero carga el mapa completo de destinos y puede dejar fuera cambios en traducciones, relaciones, menús o campos cuyo guardado no actualice `node.changed`.

## Evidencia de v3

| Origen | Registros principales V2 | Nodos V3 del tipo | Identidad válida en seguimiento | Sin destino identificado | Cambiados en V2 desde seguimiento |
|---|---:|---:|---:|---:|---:|
| laptop → dootronic | 66.860 | 63.348 | 63.348 | 3.512 | 1.864 |
| edoovillage | 3.284 | 3.177 | 3.172 | 112 | 102 |
| dootrip | 6.165 | 5.932 | 5.930 | 235 | 5.930 |
| hub | 657 | 639 | 639 | 18 | 27 |
| labdoo_story | 65 | 64 | 64 | 1 | 1 |
| team | 12 | 12 | 12 | 0 | 0 |
| team_page → team_post | 683 | 674 | 674 | 9 | 0 |
| team_task → task_team | 325 | 325 | 325 | 0 | 0 |
| action | 157.593 | 34.233 | 0 | 157.593 sin mapeo | No determinable con este seguimiento |

«Sin destino identificado» significa que no hay identidad válida en el tracker ni coincidencia unívoca en `field_d7_nid`; no demuestra por sí solo que deba crearse una entidad. Las acciones se han regenerado con otros IDs y requieren conciliación histórica específica. Los números de V2 excluyen nodos traducidos que apuntan a otro principal (`tnid`); los de V3 cuentan nodos base reales, publicados y no publicados. No restar columnas como si tuvieran idéntico alcance.

La fecha identifica candidatos, no demuestra una diferencia real de contenido. También aparecen destinos modificados después de la migración: hay que comparar los valores antes de sobreescribirlos. Hay seis diferencias de publicación en hubs y cuatro fuentes ausentes entre las filas históricas de seguimiento de viajes/escuelas; no se borrarán automáticamente.

## Cobertura y carencias

| Área | Existe | Falta para una sincronización final fiable |
|---|---|---|
| Identidad | Tracker por tipo, bundle y source_id; campo de origen | Conciliar entidades anteriores al tracker y entidades regeneradas; detectar referencias colgantes y colisiones |
| Altas | `--incremental`, IDs explícitos, lotes de 200 | Paginación keyset desde origen y límites aplicados en SQL; hoy se reúnen todos los IDs antes de dividir |
| Actualizaciones | `--mode=update`, `--from-date`, IDs explícitos | Huella de valores normalizados, watermark confirmado y detección de cambios en campos/relaciones/traducciones |
| Seguimiento | Última migración, duración, estado de integridad | Fuente.changed/vid/hash, versión de mapping, run_id, checkpoint por lote y errores recuperables |
| Colas | Workers para tipos principales | Payload sin mapping repetido por registro, deduplicación por trabajo, restaurar contexto al fallar, controlar concurrencia y reintentos |
| Errores | Failing IDs en repositorios | Salida global fiable y checkpoint sólo tras éxito; algunos repositorios registran errores y continúan |
| Archivos | Montaje V2 de sólo lectura, rsync y servicios de archivos | Separar copia física de sincronización de metadatos; impedir creación incidental de archivos/media en un supuesto dry-run |
| Traducciones existentes | Repositorios de contenido y comandos específicos | Verificar todos los idiomas, revisiones y borrados de traducciones; esto no autoriza generar traducciones nuevas |
| Wikis, equipos y galerías | Comandos específicos y mapeos parciales | Unificar selección incremental, relaciones, permisos y orden de dependencias; no basta el comando genérico |
| Menús, aliases, comentarios y configuración | Importadores parciales/específicos | Inventario independiente, comparación y política de conservación de cambios propios de V3 |
| Bajas y publicación | Datos de estado de origen | Lista de bajas para revisión, conservación de historial y política de archivo; nunca borrar sólo por ausencia en un lote |

El `--dry-run` del sincronizador no se ha usado como garantía de sólo lectura en producción: la preparación de campos especiales puede pasar por servicios que guardan archivos o medios antes de decidir si se guarda el nodo. Para este diagnóstico se usa `scripts/migration_readiness.php`, que únicamente realiza consultas SELECT y restaura la conexión activa.

## Secuencia recomendada

1. Obtener una copia consistente de V2 y fijar hora de corte; registrar versión de código y mapping. Mantener credenciales SELECT para planificar y validar.
2. Conciliar identidades antes de crear: tracker, referencia de origen y nodos base. Apartar colisiones, entidades locales y referencias ausentes.
3. Generar un manifiesto paginado por tipo y source_id: crear, actualizar, sin cambios, conflicto y baja propuesta. Comparar huellas normalizadas de campos mapeados, traducciones y relaciones; excluir campos calculados de V3.
4. Ensayar en copia aislada. Procesar dependencias: usuarios/taxonomía y equipos; escuelas/hubs/viajes; dootronics y relaciones; páginas/wiki/galerías/comentarios; aliases/menús y cálculos derivados. Resolver referencias diferidas en una segunda pasada.
5. Sincronizar archivos físicos una vez y sólo sus deltas al final. Evitar volver a copiar el mismo archivo al procesar cada referencia. No convertir archivos privados en públicos.
6. Aplicar lotes pequeños con checkpoint transaccional después del éxito, límite de memoria/tiempo y un único escritor por entidad. Vaciar la caché estática entre lotes, no toda la caché del sitio por registro. Pausar frente a errores persistentes de proveedores.
7. Repetir una pasada de cambios desde el watermark, con margen de solape y deduplicación. Reejecutar el mismo manifiesto debe producir cero cambios adicionales.
8. Verificar integridad de campos, referencias, archivos y revisiones, publicación, roles, menús y rutas; medir tiempos y recursos con tráfico representativo. Confirmar restauración antes del corte.

No se promete una duración total de sincronización sin un ensayo: el inventario de metadatos tarda segundos, pero crear/revisar entidades ejecuta hooks, medios y cálculos cuyo coste es distinto. El ensayo debe informar entidades por segundo, memoria máxima, consultas y fallos por tipo.

## Corrección incluida

El comando genérico y su worker restauran ahora el indicador de migración y la conexión activa en `finally`, incluso frente a errores. El comando también restaura el estado de indexación y propaga el fallo para que el operador no interprete una ejecución fallida como éxito. Nueve casos aislados verifican estado ausente, FALSE y TRUE, ejecución normal y fallo del worker, sin escrituras de entidades. Esto no convierte todavía el motor en una sincronización completa con hashes y checkpoints.
