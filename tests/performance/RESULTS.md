# Evaluación de Lighthouse: Labdoo Next

Comparación del 11/11/2026 sobre la copia aislada, partiendo de development
`861c784e`. Lighthouse 12.8.2, Chrome 157.0.8103.38, perfiles estándar móvil
y escritorio, misma base de contenido, agregación CSS/JS activada y el mismo
transporte local con gzip. Cada resultado es la mediana de tres ejecuciones
con navegador nuevo. Se mantienen las peticiones externas reales.

Son resultados de laboratorio local, no puntuaciones verificadas en AWS.
El acceso público al servidor requiere autenticación; no se ha puntuado su
respuesta de autenticación como si fuera la aplicación.

## Resultados

Orden: **rendimiento / accesibilidad / buenas prácticas / SEO**.

| Página | Perfil | Antes | Después |
| --- | --- | --- | --- |
| home | mobile | 48 / 88 / 96 / 83 | 81 / 100 / 100 / 100 |
| home | desktop | 89 / 88 / 96 / 75 | 92 / 100 / 100 / 100 |
| dashboard | mobile | 37 / 88 / 96 / 83 | 74 / 100 / 100 / 100 |
| dashboard | desktop | 73 / 88 / 96 / 75 | 90 / 100 / 100 / 100 |
| school | mobile | 53 / 93 / 96 / 85 | 76 / 100 / 100 / 100 |
| school | desktop | 98 / 94 / 96 / 77 | 100 / 100 / 100 / 100 |
| wiki | mobile | 78 / 90 / 96 / 85 | 98 / 100 / 100 / 100 |
| wiki | desktop | 100 / 91 / 96 / 77 | 100 / 100 / 100 / 100 |

Las tres categorías de accesibilidad, buenas prácticas y SEO alcanzan 100
en las páginas auditadas. El rendimiento móvil sigue siendo el área con margen
de mejora; los números anteriores corresponden a la versión final del widget
compacto, sin escoger los mejores resultados de iteraciones anteriores.

## Estabilidad de la versión final

| Página | Perfil | FCP (s) | LCP (s) | TBT (ms) | CLS |
| --- | --- | --- | --- | --- | --- |
| home | mobile | 1.66 | 3.78 | 328 | 0.006 |
| home | desktop | 0.41 | 1.79 | 0 | 0.001 |
| dashboard | mobile | 1.66 | 4.79 | 364 | 0.006 |
| dashboard | desktop | 0.42 | 2.04 | 3 | 0.001 |
| school | mobile | 1.66 | 5.39 | 176 | 0.000 |
| school | desktop | 0.42 | 0.71 | 0 | 0.034 |
| wiki | mobile | 1.51 | 2.04 | 97 | 0.020 |
| wiki | desktop | 0.37 | 0.47 | 0 | 0.000 |

## Cambios

- Omitir el renderizado de la barra de administración oculta para visitantes,
  con sus dependencias innecesarias; conservar las herramientas de usuarios
  autenticados y el tema de administración.
- Servir localmente las mismas versiones de Leaflet y Chart.js, con sus licencias.
  Cargar gráficos al acercarse al área visible y repartir la creación y agrupación
  de marcadores entre tareas cortas.
- Usar la inicialización por visibilidad del proveedor nativo de Geolocation y
  esperar su mapa real antes de añadir trayectorias, evitando un segundo mapa.
- Corregir contraste, textos alternativos, encabezados y semántica de paginación.
  Los selectores públicos simples usan controles nativos; las descripciones SEO
  se obtienen del contenido traducido o de un respaldo descriptivo.
- Usar el logotipo oficial con resolución suficiente y dimensiones explícitas.
- Mantener el autor junto a la frase y usar la altura natural del texto. El script
  de cabecera oculta el respaldo antes de pintar; se revela únicamente la cita
  AJAX final o el respaldo si falla la petición. Sin JavaScript se ve el respaldo
  traducido. Una petición bloqueada tiene un límite de ocho segundos.
- Establecer el estado del menú móvil antes de pintar para evitar que primero
  aparezca abierto y después se cierre.

## Validación

74 casos funcionales verificados entre las suites de frases, paletas, diseño
y rendimiento, en escritorio y móvil. Incluyen los veinte idiomas, contenido
AJAX y sin JavaScript, errores de red, ausencia de cita provisional visible,
altura compacta, autor cercano, administración de paletas, filtros, galerías,
menús, etiquetas imprimibles, mapa con 2.000 marcadores y trayectoria sobre
el proveedor nativo con dos marcadores numerados y su línea.

También pasan la sintaxis PHP/JavaScript y la comprobación de diferencias.
Los recursos de terceros conservan sus bytes originales y licencias.

## Límites de la medición

La portada muestra actualmente el dashboard; se mide de forma independiente.
La ficha de escuela usa `/en/node/749`, que redirige a su alias en ambas
revisiones. Lighthouse señala esta redirección en sus seis ejecuciones.

Las auditorías aún detectan CSS y JavaScript heredados sin utilizar, trabajo
de terceros y coste de renderizado de los mapas. Se conservan la analítica
y los datos completos de los mapas. La red externa produce variaciones
apreciables en LCP; los tres valores de cada combinación están disponibles
en los JSON. Estas puntuaciones no garantizan resultados idénticos en AWS.

[Reproducción y condiciones](README.md). Los informes HTML/JSON completos
quedan privados en `.e2e-lab/state/lighthouse-next/final/`; los JSON publicados
contienen exclusivamente puntuaciones, métricas y metadatos de ejecución.
