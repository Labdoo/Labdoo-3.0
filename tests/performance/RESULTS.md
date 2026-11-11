# Evaluación de rendimiento móvil

URL exacta facilitada por el usuario, como visitante:

`http://127.0.0.1:18381/es/content/dootronics-dashboard?palette=ocean&theme=labdoo_next`

Medición directa en el puerto 18381, sin el proxy adicional de la primera
evaluación. Chrome 157.0.8103.38, perfiles estándar de Lighthouse, navegador
nuevo por ejecución y red externa real. Las ejecuciones se realizan de forma
secuencial y se informa la mediana, conservando todos los valores.

## Iteración posterior: caché interna y trabajo del navegador

La puntuación de 81 comunicada por el usuario en v3 no queda resuelta por
los resultados anteriores. La nueva tanda local, con Lighthouse 13.6.0,
obtiene **82, 81 y 85** (mediana **82**); las otras tres categorías mantienen
100. El TBT es 347, 437 y 311 ms. No se atribuye una mejora de puntuación a
esta iteración ni se presenta el 96 histórico como resultado actual.

Se ha identificado una causa independiente de latencia: la política del tema
experimental anulaba también Dynamic Page Cache. Ahora se permite almacenar
el shell con los contextos y placeholders de Drupal antes de impedir el
almacenamiento en Page Cache. Las respuestas continúan siendo privadas y
`no-store`; la frase AJAX sigue sin caché. Los enlaces de retorno separan
todos los argumentos de consulta para evitar reutilizar enlaces de otra URL.
En local las peticiones repetidas dan DPC HIT en unos 30 ms. Antes del cambio,
cuatro peticiones internas al servidor AWS tardaron 1.687, 0.770, 0.941 y
1.395 segundos y todas indicaron DPC UNCACHEABLE. Estas medidas son del
backend y no equivalen a Lighthouse a través de CloudFront.

También se cargan los plugins de fechas y etiquetas del gráfico únicamente
cuando la configuración los requiere; se omite la animación inicial si no
hay una animación explícita. La tabla de Views recibe su contenedor accesible
desde Twig, evitando moverla después del primer renderizado y permitiendo
el desplazamiento horizontal sin JavaScript. El observador de la frase deja
de recorrer el documento al terminar de analizar el widget inicial.

La regresión funcional completa aprueba **83 pruebas**, sin omisiones ni fallos.
Incluye desplazamiento sin JavaScript, plugins opcionales y aislamiento de
argumentos de consulta entre respuestas con DPC HIT.

La comprobación de control, sirviendo el código de `f60c5b2b` en el mismo
entorno y sesión de trabajo, obtiene **80, 79 y 80** (mediana 80). La
diferencia hasta 82 es pequeña y no resuelve el objetivo móvil. También
confirma que el 96 histórico no es reproducible en esta tanda.

La traza todavía muestra tareas largas de diseño, inicialización del gráfico
y analítica. La mejora móvil sigue pendiente. Informes privados:
`.e2e-lab/state/lighthouse-next/phase3-table-final/`.

## Resultado de la evaluación anterior

| Versión / perfil | Rendimiento de cada ejecución | Mediana | Accesibilidad | Buenas prácticas | SEO |
| --- | --- | --- | --- | --- | --- |
| 13.6.0 / mobile | 96, 95, 97 | **96** | 100 | 100 | 100 |
| 13.6.0 / desktop | 91, 91, 91 | **91** | 100 | 100 | 100 |
| 12.8.2 / mobile | 94, 93, 93 | **93** | 100 | 100 | 100 |

La referencia inicial reproducida con Lighthouse 12.8.2 fue **53** en móvil
(una ejecución), con FCP de 7.66 s
y LCP de 13.30 s. La comprobación
final con esa misma versión alcanza una mediana de **93**. La versión reciente
13.6.0 obtiene **96**. Se indican las versiones para no mezclar métodos.

La descarga inicial móvil pasa de **5.93 MB** a
**0.51 MB**, aproximadamente un **91% menos**.
Las peticiones pasan de **217 a 21**, y los estilos de **120 archivos a 2**.
El mapa fuera de pantalla no descarga su biblioteca ni sus puntos al inicio.

## Correcciones

- La configuración exportada activa la agregación de CSS y JavaScript; el
  entorno aislado también la tiene activa, incluyendo sus overrides locales.
- El router local sirve los recursos estáticos y las respuestas HTML/JSON con
  gzip, conservando las cabeceras privadas de Drupal. La compresión dinámica se
  inicia después de preparar la respuesta del kernel, como transporte externo a
  su renderizado. Las descargas binarias mantienen su tratamiento habitual.
- Las páginas públicas anónimas omiten la piel administrativa de Gin y componentes
  administrativos de Claro. Conservan los componentes públicos de formularios,
  tablas, paginación y diálogos. La caché de recursos distingue este perfil del
  de los usuarios autenticados, que conservan sus estilos editoriales.
- El gráfico reserva su tamaño real mientras llega Chart.js. Esto evita que el
  mapa situado debajo entre temporalmente en pantalla y descargue todos los puntos.
- Leaflet y la integración del mapa se cargan cuando el mapa se hace visible.
  Los mapas nativos esperan a su proveedor, sin cargar otra implementación.
- La petición de la frase empieza al analizar el HTML, sin esperar a las
  bibliotecas de Drupal del pie de página. Conserva el autor cercano, el tamaño
  compacto, el respaldo sin JavaScript y la protección frente a la cita provisional.
- Los enlaces del panel lateral usan un color de la paleta con contraste suficiente.

## Verificación y alcance

**78 pruebas E2E aprobadas, sin omisiones ni fallos**, después del cambio de
transporte. Incluyen los veinte idiomas, AJAX sin los scripts de Drupal,
respaldo sin JavaScript y por fallo de red, paletas, formularios, filtros,
galerías, menús, mapas, 2.000 marcadores y trayectorias sobre el proveedor nativo.
Se comprueba que el mapa fuera de pantalla no descargue datos ni Leaflet antes
del desplazamiento y que funcione al entrar en pantalla.

La mejora incluye cambios del tema/módulo y la corrección de la configuración y
el transporte del entorno local. Apache ya contempla compresión en producción;
no se atribuye al código del tema toda la diferencia de puntuación del servidor local.
Los estilos y scripts innecesarios, la descarga prematura del mapa y el inicio
tardío de la frase sí se han corregido en el código del proyecto.

Los datos completos del mapa, la analítica y el aviso de cookies siguen activos.
En escritorio el mapa está visible al cargar, por lo que sus datos y las teselas
externas influyen en el LCP. Son resultados de laboratorio sobre esta URL local;
no representan una verificación del despliegue AWS.

[Reproducción](README.md) · [Datos publicados](current.json). Los informes
completos quedan privados en `.e2e-lab/state/lighthouse-next/phase2-final-13/`
y `phase2-final-12/`. Los JSON `before.json` y `after.json` conservan la primera
evaluación incluida en el commit `4ef2f78d`, con rutas y transporte distintos.
