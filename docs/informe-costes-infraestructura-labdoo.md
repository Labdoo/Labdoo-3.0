# Informe de costes e inventario de infraestructura de Labdoo

**Fecha de consulta:** 28 de septiembre de 2026<br>
**Cuenta AWS:** [AWS account ID redacted]<br>
**Región principal:** eu-central-1 (Fráncfort)<br>
**Moneda:** USD

## Resumen

- El gasto real de los 11 meses completos entre octubre de 2025 y agosto de 2026 fue **5.600,21 USD**, una media de **509,11 USD/mes**. Agosto de 2026, el último mes cerrado, fue **605,59 USD**.
- Cost Explorer estima **842,38 USD** para el 1–28 de septiembre de 2026; **146,20 USD** aparecen como impuestos. El gasto de servicios antes de impuestos es **696,18 USD**. El periodo todavía está abierto y puede cambiar.
- Los principales conceptos de agosto fueron EC2 (**180,17 USD** entre cómputo y cargos asociados), RDS (**140,24 USD**), SES (**116,96 USD**) y ElastiCache (**113,45 USD**). El mayor cargo nuevo de EC2 en septiembre es una reserva Standard de tres años para m4.2xlarge.
- El inventario principal es de tres instancias EC2, una base de datos RDS Multi-AZ, un grupo de replicación Redis Multi-AZ con dos nodos, una distribución CloudFront protegida por WAF, volúmenes EBS y sus instantáneas, y servicios de correo y monitorización.
- Hay oportunidades concretas que conviene revisar: Virtual Deliverability Manager de SES (~52,36 USD/mes en agosto), una Elastic IP sin asociar (~3,72 USD/mes), las horas de la instancia de desarrollo y 26 instantáneas de más de un año. Las reducciones condicionadas se detallan abajo.

## Alcance y método

Los datos proceden de AWS CLI con acceso a la cuenta indicada. Cost Explorer devuelve costes UnblendedCost en USD; los impuestos aparecen como un servicio separado. La serie histórica incluye octubre de 2025–agosto de 2026, todos meses cerrados. Septiembre es parcial y estimado. Los datos de uso y recursos se consultaron el 28 de septiembre de 2026.

El inventario cubre los recursos encontrados en esta cuenta, principalmente en eu-central-1, y los recursos globales de CloudFront/WAF gestionados mediante us-east-1. No se encontraron zonas alojadas de Route 53, balanceadores ELB ni NAT Gateways. No hay buckets en la respuesta actual de S3. La ausencia de esos recursos en esta cuenta no excluye que DNS u otros servicios estén gestionados fuera de ella.

## Evolución mensual

| Mes | Coste unblended (USD) | Estado |
|---|---:|---|
| 2025-10 | 194,23 | Cerrado |
| 2025-11 | 527,70 | Cerrado |
| 2025-12 | 582,52 | Cerrado |
| 2026-01 | 582,32 | Cerrado |
| 2026-02 | 521,14 | Cerrado |
| 2026-03 | 588,43 | Cerrado |
| 2026-04 | 579,85 | Cerrado |
| 2026-05 | 741,08 | Cerrado |
| 2026-06 | 677,36 | Cerrado |
| 2026-07 | -0,00 | Cerrado; anómalo |
| 2026-08 | 605,59 | Cerrado |
| 2026-09 (1–28) | 842,38 | Estimado; incluye 146,20 USD de impuestos |

> Julio de 2026 aparece prácticamente a cero aunque las instancias figuran actualmente en ejecución. No lo uso como referencia de gasto normal: conviene cotejar ese periodo con la factura y los ajustes/créditos de Billing. Septiembre muestra 828,35 USD amortizados frente a 842,38 USD unblended; ambas cifras tienen definiciones contables distintas.

## Desglose por servicio

| Servicio / concepto | Agosto 2026 cerrado | Septiembre 2026 parcial, estimado |
|---|---:|---:|
| Amazon EC2 – Compute | 113,77 | 285,82 |
| EC2 – Other (principalmente EBS, instantáneas y transferencia) | 66,39 | 60,22 |
| Amazon RDS | 140,24 | 124,26 |
| Amazon ElastiCache | 113,45 | 99,44 |
| AWS WAF | 25,49 | 22,98 |
| Amazon VPC | 14,88 | 13,08 |
| Amazon SES | 116,96 | 89,46 |
| Amazon CloudWatch | 1,17 | 0,78 |
| Amazon SNS | 0,33 | 0,12 |
| Amazon SQS | 0,15 | 0,02 |
| Impuestos | 12,74 | 146,20 |
| **Total Cost Explorer** | **605,59** | **842,38** |

Los importes inferiores a un céntimo de S3, CloudFront, solicitudes y otros conceptos menores no se muestran aquí. El desglose de septiembre incluye una cuota de reserva Standard para m4.2xlarge, activa desde el 5 de septiembre; las horas de EC2 fueron menores que en agosto porque septiembre es un periodo parcial. La fiscalidad de septiembre es muy superior a los meses anteriores y merece cotejo con Billing antes de proyectarla.

En los 11 meses cerrados (octubre de 2025–agosto de 2026), Cost Explorer atribuyó aproximadamente **2.663,96 USD** a EC2 Compute, **1.237,14 USD** a RDS, **667,01 USD** a EC2 Other, **505,66 USD** a ElastiCache, **220,60 USD** a WAF, **146,29 USD** a VPC y **123,32 USD** a SES. Hay ajustes de transferencia negativos por 3,01 USD en ese periodo.

## Inventario de infraestructura

### Cómputo y dominios

Las tres instancias están en eu-central-1b y actualmente en estado running.

| Instancia | Etiqueta / dominio indicado en AWS | Tipo | IP pública | Discos adjuntos | Uso observado |
|---|---|---|---|---|---|
| [EC2 instance ID redacted] | WordPress www.labdoo.org live system | t2.micro | [public IP redacted] | raíz, 30 GiB gp2 | Promedio de medias diarias de CPU: 4,68%; máximo horario del periodo: 99,58% |
| [EC2 instance ID redacted] | platform.labdoo.org | m4.2xlarge | [public IP redacted] | raíz, 8 GiB gp2; datos, 150 GiB gp3 | CPU media diaria media: 5,62%; una muestra horaria llegó a 99,78% |
| [EC2 instance ID redacted] | v3-dev.labdoo.org | t2.large | [public IP redacted] | raíz, 20 GiB gp3; datos, 120 GiB gp3 | CPU media diaria media: 4,15%; máximo horario del periodo: 40,93% |

Las métricas son de CPU AWS/EC2 durante los 31 días del 29 de agosto al 28 de septiembre de 2026. Una media baja sugiere revisar el tamaño, pero los picos de platform y www desaconsejan reducirlos sin comprobar memoria, latencia y carga punta. La cuenta no está registrada en AWS Compute Optimizer, así que no hay recomendaciones automáticas de tamaño.

**Relación dominio–instancia, según la evidencia disponible:**

- La etiqueta AWS de la instancia [EC2 instance ID redacted] identifica www.labdoo.org; la etiqueta no prueba por sí sola qué registros DNS o virtual hosts sirve.
- La etiqueta de [EC2 instance ID redacted] identifica platform.labdoo.org; tampoco hay registros DNS del dominio dentro de esta cuenta.
- CloudFront tiene el alias **v3.labdoo.org** y su origen HTTPS es [AWS origin hostname redacted], que resuelve a la instancia [EC2 instance ID redacted] (v3-dev.labdoo.org). ACM tiene un certificado emitido para v3.labdoo.org en us-east-1.
- La consulta aws route53 list-hosted-zones devolvió una lista vacía. Por ello, AWS CLI confirma la relación CloudFront–origen para v3.labdoo.org, pero no permite validar los registros DNS externos de www.labdoo.org y platform.labdoo.org, ni los virtual hosts/configuración web instalados en las máquinas. En este informe esos dos nombres se identifican como etiquetas AWS, no como DNS comprobado.

### Almacenamiento, base de datos y caché

| Componente | Inventario observado | Función |
|---|---|---|
| EBS | 5 volúmenes en uso, 328 GiB en total: dos gp2 (30 + 8 GiB) y tres gp3 (150 + 120 + 20 GiB) | Discos raíz y de datos de las EC2. Se facturan además del cómputo. |
| Snapshots EBS | 48 instantáneas; 26 tienen más de un año | Copias puntuales de volúmenes para recuperación. Coste de uso EBS:SnapshotUsage: 27,73 USD en agosto y 25,28 USD hasta el 28 de septiembre. |
| AMI | 19 imágenes privadas encontradas; 10 con más de un año | Plantillas para lanzar instancias; normalmente dependen de snapshots EBS. |
| RDS Drupal database | MariaDB 10.6.25, db.t3.medium, Multi-AZ, 50 GiB gp3, retención de backups de 14 días | Base de datos gestionada para Drupal. Coste agosto: 140,24 USD (incluye uso Multi-AZ, almacenamiento y backups). |
| ElastiCache replication group Redis replication group | Un grupo Multi-AZ, failover automático habilitado, dos miembros cache.t3.medium: Redis primary node en eu-central-1b y Redis replica node en eu-central-1a | Un primario y una réplica para alta disponibilidad de Redis; los dos miembros pertenecen al mismo grupo de replicación. |

### Red, distribución y seguridad

| Componente | Inventario observado | Función |
|---|---|---|
| VPC | [VPC ID redacted], red por defecto [private network range redacted] | Red privada que contiene EC2, RDS y caché. |
| Subredes | Tres subredes, una por AZ eu-central-1a/b/c; asignan IP pública al lanzar instancias | Separan recursos por zona de disponibilidad. |
| Grupos de seguridad | 8 grupos. Destacan RDS security group, Redis security group, CloudFront origin security group, legacy security group B y legacy security group A | Reglas de firewall con estado que permiten tráfico entre componentes y desde clientes. Las EC2 observadas usan legacy security group A o legacy security group B. |
| Direcciones IPv4 públicas | 3 direcciones en uso y una Elastic IP [public IP redacted] sin asociar | Direccionamiento público; VPC facturó 14,88 USD en agosto, de los que el uso PublicIPv4:IdleAddress fue 3,72 USD. |
| CloudFront | Distribución [CloudFront distribution ID redacted], alias v3.labdoo.org, origen HTTPS en la EC2 v3-dev, PriceClass_100 | CDN y punto de entrada de v3.labdoo.org; el cargo de CloudFront fue inferior a 0,01 USD en agosto. |
| AWS WAF | ACL global labdoo-waf, asociada a CloudFront | Filtra solicitudes web. Incluye grupos administrados Common Rules, reputación IP y Bot Control, además de reglas propias de Drupal y limitación de tasa. Coste agosto: 25,49 USD. |
| ACM | Certificado público emitido para v3.labdoo.org en us-east-1 | TLS para el dominio de CloudFront; ACM no tuvo cargo en el periodo consultado. |
| NAT Gateway / ELB / Route 53 | Ninguno encontrado en esta cuenta/región (sin zonas Route 53 globales) | No aparecen estos componentes gestionados en el inventario consultado. |

### Correo, colas y observabilidad

| Componente | Inventario observado | Función |
|---|---|---|
| SES | Envío habilitado en eu-central-1; cuota 60.400 envíos/24 h; últimos 24 h: 18.675 | Servicio de correo transaccional. Coste agosto: 116,96 USD. |
| SES Virtual Deliverability Manager (VDM) | Habilitado, con métricas de interacción y optimización de entrega compartida habilitadas | Analítica y optimización de entregabilidad. El uso facturado Recipients-VirtDelivMgr fue 52,36 USD en agosto. |
| SNS | Temas overload alert topic, health alert topic y SES events topic | Publica alertas y eventos para suscriptores. Coste agosto: 0,33 USD. |
| SQS | Cola SES events queue | Retiene eventos de SES para procesarlos de forma asíncrona. Coste agosto: 0,15 USD. |
| CloudWatch | 14 alarmas visibles; 13 en OK y una antigua alarma de CPU en INSUFFICIENT_DATA. Grupos de logs de Apache, Fail2ban, SES y salud de Labdoo. | Métricas, alarmas y logs. Coste agosto: 1,17 USD. El log group health log group almacena ~120 MB y no tiene retención configurada; los logs de Apache y Fail2ban retienen 30 días. |
| S3 | list-buckets devolvió cero buckets; Cost Explorer muestra cargos residuales inferiores a 0,01 USD | Almacenamiento de objetos; no hay buckets listados en esta cuenta. |

## Oportunidades de optimización y ahorro

Las cifras son oportunidades sujetas a validar requisitos de servicio. No se ha aplicado ningún cambio en AWS.

| Prioridad | Oportunidad | Evidencia y ahorro orientativo | Acción recomendada |
|---|---|---|---|
| Alta | Revisar SES VDM | 52,36 USD en agosto y VDM está habilitado; es un concepto facturado identificable. | Comprobar si se usan sus paneles/funciones de entregabilidad. Si no aportan valor, deshabilitar VDM y comprobar la factura siguiente; ahorro observado potencial cercano a 52 USD/mes. |
| Alta | Reducir horas o tamaño de v3-dev | La etiqueta la identifica como desarrollo; su CPU media diaria fue 4,15% y ningún pico horario superó 40,93%. El tipo t2.large costó 79,76 USD en agosto. | Si el servicio tolera interrupciones, un horario de apagado puede reducir el coste de cómputo; reducir a la mitad las horas ahorraría como máximo unos 40 USD/mes, pero no discos. Asociar la Elastic IP libre y cambiar el origen de CloudFront resolvería la IP cambiante, pero no evitaría que el sitio quede inaccesible mientras la EC2 está detenida. La cuenta tiene una reserva activa para m4.2xlarge, que debe tenerse en cuenta antes de cambiar esa otra instancia. |
| Alta | Retirar o asociar la Elastic IP libre | [public IP redacted] está sin asociación; PublicIPv4:IdleAddress costó 3,72 USD en agosto. | Confirmar que no está reservada para una migración y liberarla si está abandonada: ahorro estimado de 3,72 USD/mes. |
| Media | Revisar snapshots y AMIs históricos | 26 de 48 snapshots y 10 de 19 AMIs tienen más de un año; snapshots costaron 27,73 USD en agosto. | Definir retención y dependencias de recuperación; borrar solo imágenes y snapshots que ya no sean necesarios. El cargo agregado limita la estimación: el ahorro máximo atribuible a snapshots sería inferior o igual a esos 27,73 USD/mes. |
| Media | Dimensionar Redis para la migración a platform | El grupo de dos nodos cuesta 113,45 USD en agosto. Las métricas de los 31 días al 28 de septiembre muestran DatabaseMemoryUsagePercentage cercano al 100% en ambos nodos y 5.491 evictions en el primario; CPU media diaria baja (~2,5%). | No reducir a un nodo. Validar qué caché se comparte con platform y hacer una prueba de carga con el volumen previsto; revisar TTL/eviction policy y aumentar memoria/tamaño si las evictions afectan a la aplicación. Mantener los dos nodos conserva la réplica y el failover entre AZ. |
| Media | Ajustar platform.labdoo.org tras pruebas de carga | m4.2xlarge tiene una media de medias diarias de CPU de 5,62%, pero hubo picos horarios cercanos al 100%; hay una reserva Standard activa para esa clase hasta septiembre de 2029. | Revisar memoria, CPU, latencia y picos antes de cambiar el tamaño. Comprobar primero que la reserva seguirá utilizándose; de otro modo el compromiso podría quedar sin uso. No estimar ahorro hasta medir una prueba representativa. Compute Optimizer no está habilitado en la cuenta. |
| Media | Verificar uso de WAF Bot Control | Bot Control está activo; Global-AMR-BotControl costó 10,89 USD en agosto. | Revisar solicitudes muestreadas y eventos bloqueados. Si la protección avanzada no se necesita, comparar su retirada con el riesgo de más tráfico automatizado; ahorro potencial observado: unos 10,89 USD/mes. |
| Baja | Establecer retención para health log group | Unos 120 MB almacenados sin caducidad configurada; CloudWatch costó 1,17 USD en agosto. | Acordar retención (por ejemplo 30 o 90 días) y aplicarla. El ahorro inmediato probablemente será pequeño, pero evita acumulación indefinida. |
| Condicional | Revisar Multi-AZ de RDS | RDS costó 140,24 USD en agosto; la base está configurada Multi-AZ. | Mantenerlo si la base es producción y se requiere recuperación ante fallo de zona. Solo considerar Single-AZ o una clase menor tras validar RPO/RTO, carga y métricas; no se estima ahorro sin esa decisión de disponibilidad. |

## Análisis de variación de costes

Este apartado explica los cambios más relevantes observados entre meses y distingue las variaciones atribuibles directamente a recursos de las que solo pueden asociarse de forma probable. Septiembre de 2026 es parcial y estimado; no debe compararse como un mes cerrado.

### Fiscalidad: subida de impuestos en septiembre

Cost Explorer marca septiembre como estimado. El impuesto mostrado, 146,20 USD, es prácticamente el 21% de los 696,18 USD de servicios antes de impuestos. Además, Cost Explorer muestra que todos los servicios facturados pasaron de Amazon Web Services, Inc. en agosto a Amazon Web Services EMEA SARL en septiembre (incluido Tax: de 12,74 a 146,20 USD). Esto apunta a un cambio de entidad facturadora y de tratamiento fiscal; AWS indica que las cuentas cuyo Tax Address está en países EMEA se facturan por AWS Europe ([AWS Europe FAQs](https://aws.amazon.com/legal/aws-emea/)). El 21% coincide con el tipo general de IVA español ([AEAT](https://sede.agenciatributaria.gob.es/Sede/eu_es/ayuda/manuales-videos-folletos/manuales-practicos/manual-iva-2025/capitulo-04-sujetos-pasivos-repercusion-impositivo/tipo-impositivo.html)). La causa administrativa exacta (por ejemplo, un cambio/corrección de Tax Address o TRN) debe confirmarse en Tax Settings; AWS dice que usa TRN y dirección legal para determinar la ubicación fiscal ([AWS Billing](https://docs.aws.amazon.com/awsaccountbilling/latest/aboutv2/monthly-billing-checklist.html)). Como septiembre sigue estimado, el importe definitivo y su desglose estarán en la factura fiscal al cierre ([Cost Explorer](https://docs.aws.amazon.com/cost-management/latest/userguide/ce-exploring-data.html)).

### EC2: coste superior sin aumento de horas de instancia

Las tres instancias facturaron 744 horas cada una en agosto y unas 653 horas cada una hasta el 28 de septiembre. No hay indicios de un aumento de horas; septiembre aún es parcial. Sí aparece una reserva Standard Linux/UNIX para una m4.2xlarge, de tenancy default y ámbito regional, activa desde el 5 de septiembre de 2026 hasta el 4 de septiembre de 2029. Es No Upfront y tiene un cargo recurrente de 0,2419 USD/hora (aprox. 176,59 USD en un mes medio de 730 horas, mientras permanezca activa). Cost Explorer estima 146,89 USD de reserva para septiembre. Hasta el 27 de septiembre su utilización era del 100% (512 horas) y el ahorro neto estimado frente a On-Demand era 122,06 USD. Por tanto, el gasto EC2 subió por la entrada de ese compromiso de tres años, no porque se hayan añadido horas de instancia.

### Alta de la infraestructura v3.labdoo.org

La instancia v3-dev (t2.large) se lanzó el 22 de abril de 2026. Su uso facturado fue 194,5 horas y 20,85 USD en abril; en un mes completo costó 79,76 USD en mayo y agosto (77,18 USD en junio). Hasta el 28 de septiembre constan 652,5 horas y 69,95 USD. Añadiendo una IPv4 pública (~3,72 USD/mes según el uso observado), su coste directamente identificable queda en torno a **83,50 USD/mes** cuando está encendida todo el mes. Los volúmenes gp3 de 20 y 120 GiB asociados a la instancia se crearon en agosto y octubre de 2025, antes de lanzar esta EC2, por lo que no los cuento como coste nuevo de abril. CloudFront tuvo un coste inferior a 0,01 USD/mes; AWS WAF ya costaba 28,27 USD en marzo y 29,67 USD en abril.

El gasto total pasó de 588,43 USD en marzo a 579,85 USD en abril (−8,58 USD), por lo que no hubo crecimiento neto en el mes del lanzamiento. En mayo subió a 741,08 USD (+161,23 USD respecto a abril). La subida de mayo incluye +83,00 USD de ElastiCache, +37,20 USD de RDS y +40,62 USD de EC2 Compute; Cost Explorer no permite atribuir los incrementos compartidos de RDS y Redis a v3 solo por nombre o fecha. La parte directamente identificable de v3 es aproximadamente 83,50 USD/mes a tiempo completo (instancia más IPv4); no se debe presentar todo el aumento de mayo como coste de v3.

### SES: servicio de correo y componente VDM

Se confirma que SES es necesario para el envío de correo y no se recomienda deshabilitarlo ni reducir correo legítimo. VDM es un componente aparte de SES: costó 52,36 USD en agosto y está habilitado. Solo merece revisar si usáis sus informes/funciones de entregabilidad; si forman parte del flujo operativo, mantenedlo.

### IPv4/Elastic IP y posible parada de v3-dev

La Elastic IP [public IP redacted] libre se puede asociar a v3-dev. La instancia conservaría esa dirección al detenerla y arrancarla; su IP pública dinámica actual se liberaría al asociar la Elastic IP. AWS documenta este comportamiento y el cobro de las direcciones públicas ([EC2: detener y arrancar instancias](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/how-ec2-instance-stop-start-works.html)). Como esa Elastic IP ya está libre y se factura como dirección IPv4 ociosa, reutilizarla y soltar la IP actual reduciría el total de direcciones públicas de la cuenta en una, con ahorro observado de unos 3,72 USD/mes.

No se debe asociar la IP sin cambiar también el origen de CloudFront: ahora apunta al nombre DNS de la IP dinámica actual. Conviene crear un nombre DNS de origen controlado por Labdoo que resuelva a la Elastic IP, instalar en el servidor un certificado válido para ese nombre y cambiar el origen de CloudFront. CloudFront requiere un nombre de origen resoluble públicamente y, al usar HTTPS, que el certificado del origen cubra ese nombre ([origen CloudFront](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/DownloadDistValuesOrigin.html)).

### Redis: coste actual y margen para el futuro tráfico de platform

El recurso es un único replication group Redis replication group con dos miembros en AZ distintas, Multi-AZ y failover automático habilitados. En los 31 días consultados, ambos nodos promediaron aproximadamente 99,9% en DatabaseMemoryUsagePercentage y llegaron a unos 2,49 GB de datos de caché; el primario registró 5.491 evictions. La CPU fue baja (~2,5% de media diaria), pero la memoria está prácticamente en el límite. No recomiendo pasar a un único nodo antes de production: se perdería el failover Multi-AZ y los datos actuales muestran presión de memoria. La guía de AWS explica que la réplica puede ser promovida automáticamente en una caída ([ElastiCache Multi-AZ](https://docs.aws.amazon.com/AmazonElastiCache/latest/dg/AutoFailover.html)). Primero medir con tráfico esperado y decidir si ampliar la memoria/tamaño; un nodo puede bastar en cómputo hoy, pero los datos no indican margen seguro de memoria para la migración.

### RDS Multi-AZ: disponibilidad y cambios de configuración

Drupal database es una instancia MariaDB Multi-AZ: RDS mantiene una standby síncrona en otra zona y puede cambiar a ella ante un fallo. Mejora la disponibilidad, no la capacidad de lectura. Se puede modificar con la opción Multi-AZ de RDS CLI. AWS dice que el cambio de ese parámetro no causa una caída por sí mismo y se aplica en la ventana de mantenimiento salvo que se solicite aplicación inmediata; la conversión Single-AZ a Multi-AZ crea la standby y puede afectar temporalmente al rendimiento ([AWS CLI modify-db-instance](https://docs.aws.amazon.com/cli/latest/reference/rds/modify-db-instance.html), [conversión a Multi-AZ](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/Concepts.MultiAZ.Migrating.html)). Técnicamente se puede habilitar antes de producción, pero no trataría el cambio como un botón instantáneo: revisar modificaciones pendientes, acordar ventana y esperar a que RDS termine la operación antes de migrar tráfico.

## Glosario breve

- **EC2:** máquinas virtuales donde se ejecutan los sistemas web y de desarrollo.
- **EBS:** discos persistentes conectados a EC2; se pagan por capacidad y tipo de volumen.
- **Snapshot / AMI:** copia de un disco / plantilla de máquina que permite recuperación o nuevos lanzamientos.
- **RDS:** base de datos relacional administrada; Multi-AZ mantiene una instancia de espera en otra zona.
- **ElastiCache:** caché Redis administrada para acelerar lecturas y tareas de aplicación.
- **VPC / subred / grupo de seguridad:** red privada, segmento de red y reglas de firewall de AWS.
- **Elastic IP / IPv4 pública:** dirección pública fija o asignada; AWS cobra por direcciones públicas, incluso algunas sin asociar.
- **CloudFront:** CDN/proxy global que entrega contenido y conecta el dominio v3.labdoo.org con su origen.
- **WAF:** firewall de aplicaciones web que inspecciona y filtra peticiones HTTP/S.
- **ACM:** gestión de certificados TLS para cifrar conexiones.
- **SES / VDM:** envío de correo / herramientas de analítica y entregabilidad del correo.
- **SNS / SQS:** publicación de avisos / cola duradera para procesar eventos.
- **CloudWatch:** métricas, alarmas y logs para vigilar la infraestructura.
- **Cost Explorer:** consulta de gasto histórico y estimado por fecha, servicio, región y tipo de uso.

## Comandos AWS CLI de referencia

Las consultas principales se pueden reproducir con estos comandos (añadiendo filtros o --query para reducir salida):

```bash
aws ce get-cost-and-usage --time-period Start=2025-10-01,End=2026-09-01 --granularity MONTHLY --metrics UnblendedCost --group-by Type=DIMENSION,Key=SERVICE
aws ce get-cost-and-usage --time-period Start=2026-08-01,End=2026-09-01 --granularity MONTHLY --metrics UnblendedCost --group-by Type=DIMENSION,Key=USAGE_TYPE
aws ce get-cost-and-usage --time-period Start=2026-08-01,End=2026-09-29 --granularity MONTHLY --metrics UnblendedCost --group-by Type=DIMENSION,Key=SERVICE Type=DIMENSION,Key=LEGAL_ENTITY_NAME
aws ec2 describe-reserved-instances --region eu-central-1 --filters Name=state,Values=active
aws ce get-reservation-utilization --time-period Start=2026-09-01,End=2026-09-29 --granularity MONTHLY
aws ec2 describe-instances --region eu-central-1
aws ec2 describe-volumes --region eu-central-1
aws ec2 describe-snapshots --region eu-central-1 --owner-ids self
aws rds describe-db-instances --region eu-central-1
aws elasticache describe-cache-clusters --region eu-central-1 --show-cache-node-info
aws elasticache describe-replication-groups --region eu-central-1
aws cloudfront list-distributions
aws wafv2 list-web-acls --scope CLOUDFRONT --region us-east-1
aws acm list-certificates --region us-east-1
aws sesv2 get-account --region eu-central-1
aws ec2 describe-addresses --region eu-central-1
aws route53 list-hosted-zones
aws compute-optimizer get-ec2-instance-recommendations --region eu-central-1
```
