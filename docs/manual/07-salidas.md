# Salidas del aula

Este capítulo es para todo el profesorado, y para la administración del centro en lo que se refiere a los motivos de salida y al historial.

La sección **Salidas** del menú lateral controla qué estudiantes están ahora mismo fuera del
aula con permiso de un docente, adónde han ido y cuánto llevan fuera. Está pensada para usarse
sobre todo desde el móvil: el botón **Autorizar salida** queda fijo en la parte inferior de la
pantalla y también está entre los accesos rápidos de la pantalla de inicio y en la paleta de
comandos.

## Autorizar una salida {#autorizar-una-salida}

1. Pulsa **Autorizar salida** y elige el **grupo**. Primero aparecen tus grupos (aquellos en los
   que das clase o eres tutor/a) y debajo el resto.
2. Elige al **estudiante** (hay un buscador para los grupos grandes). Los estudiantes que ya están
   fuera aparecen marcados.
3. Elige el **motivo** en la cuadrícula de botones, escribe si quieres una observación y pulsa el
   botón grande **Autorizar salida**. Debajo se listan las salidas de hoy de ese estudiante, con el
   tiempo que estuvo fuera en cada una.

Si el estudiante tiene **pase prioritario** (ver más abajo), se muestra su justificación en un
aviso destacado antes de elegir el motivo. Un estudiante que ya tiene una salida abierta no puede
volver a salir hasta que se registre su regreso o la salida se cierre sola (ver [Salidas olvidadas](#salidas-olvidadas)).

![Último paso de Autorizar salida, con el aviso de pase prioritario](img/salidas/salidas-autorizar.png)

## Quién está fuera ahora

La pantalla principal muestra, en tiempo real (se actualiza sola cada pocos segundos), los
estudiantes que están fuera: nombre y grupo, docente que autorizó, motivo con sus observaciones y
la hora de salida junto a un texto como «Hace 12 minutos». Si la salida no es de hoy se añade la
fecha. A partir de los **10 minutos** el tiempo cambia de color —ámbar a los 10, naranja a los 20 y
rojo a los 30— para que las ausencias largas destaquen.

![Pantalla Salidas con las salidas propias y las de otros docentes](img/salidas/salidas-ahora.png)

Las salidas que has autorizado tú aparecen **aparte y primero**, con un botón **Ha regresado** que
cierra la salida (la sección no se muestra si no tienes ninguna). En las salidas de otros docentes
el botón **Ha regresado** solo aparece si eres tutor/a del grupo del estudiante, equipo directivo o
administración.

## Salidas olvidadas {#salidas-olvidadas}

Si nadie pulsa **Ha regresado**, el estudiante seguiría «fuera» indefinidamente y no podría volver a
salir al día siguiente. Para evitarlo, cada madrugada la aplicación **cierra automáticamente las
salidas de días anteriores** que siguen sin regreso registrado (las de hoy no se tocan). No hay
ajuste que configurar: funciona siempre que la sección Salidas esté activada.

Una salida cerrada así **no se da por regresada**: no se inventa ninguna hora de vuelta, por lo que
no cuenta en los tiempos fuera de los informes y se muestra con la etiqueta **Cierre automático** en
lugar del cronómetro. En el [historial](#historial) puede filtrarse con **Cerradas
automáticamente** (el filtro **Siguen fuera** no las incluye).

## Pase prioritario {#pase-prioritario}

Algunos estudiantes necesitan un trato especial al salir del aula (por ejemplo, por motivos de
salud). La administración del centro puede concederles un **pase prioritario** desde su
[ficha](04-convivencia-profesorado.md#ficha-del-estudiante), con una justificación escrita. Vale para el curso académico
actual y se muestra con el distintivo **PASE** junto al nombre del estudiante en todos los
listados de salidas.

![Pase prioritario y salidas en la ficha del estudiante](img/alumnado/alumnado-pase-prioritario.png)

## Historial

En la ficha de cada estudiante hay un listado de sus salidas autorizadas. El equipo directivo y la
administración disponen además de la pestaña **Historial** en la sección Salidas, con todas las
salidas del curso: se puede buscar por estudiante o docente, filtrar por grupo, motivo, estado
(siguen fuera o ya han regresado) y fechas, y ordenar por columnas. Para las salidas que han
terminado se indica el tiempo que estuvo fuera el estudiante.

La administración (global o del centro) puede además **eliminar una salida** desde ese historial,
con el botón de la papelera de su fila. La aplicación pide confirmación antes de borrarla: la salida
desaparece definitivamente de la base de datos y no se puede recuperar. Solo se puede borrar mientras
se consulta el curso activo, y cada eliminación queda en el registro de actividad.

![Pestaña Historial de la sección Salidas](img/salidas/salidas-historial.png)

## Informe de salidas {#informe-de-salidas}

**Informes › Salidas del aula**, para quienes administran el centro, resume las salidas de un
rango de fechas del curso visualizado. Se piden las fechas de inicio y fin (con rangos rápidos) y
el informe muestra:

- **Resumen**: salidas, estudiantes y grupos distintos, **tiempo medio fuera**, la salida más larga
  y cuántas están **sin regreso registrado**.
- **Por tipo de salida** (motivo): salidas de cada tipo y su porcentaje, estudiantes y grupos
  distintos, tiempo medio y máximo, y salidas sin regreso.
- **Detalle de cada tipo**: los **grupos** y el **alumnado** que más lo usan.
- **Por grupo y tipo de salida**, agrupado por curso: para cada grupo, estudiantes con salidas,
  salidas totales, cuántas de cada tipo y tiempo medio. Los grupos sin salidas aparecen con ceros,
  y cada curso lleva su subtotal.
- **Alumnado con más salidas** (los diez primeros): grupo, número de salidas, tiempo fuera en
  total y motivo más frecuente.

El tiempo fuera solo cuenta las salidas con regreso registrado (las [cerradas
automáticamente](#salidas-olvidadas) figuran como sin regreso registrado, y no suman tiempo). Los botones **Exportar a PDF**
(apaisado, con el encabezado y la plantilla personalizables, ver
[Personalización de informes](13-administrar-la-plataforma.md#personalizacion-de-informes)) y
**Exportar a hoja de cálculo** descargan el mismo contenido. El informe desaparece, con el resto de
la sección, si el módulo de Salidas está desactivado.

## Configurar los motivos de salida {#motivos-de-salida-del-aula}

En **Centro educativo › Motivos de salida del aula** (administración del centro) se configura la lista —plana, sin
categorías— de destinos entre los que el profesorado elige al
[autorizar una salida](#autorizar-una-salida). Los 9 motivos por defecto son: Servicio,
Fuente, Conserjería, Departamentos, Carro portátiles, Secretaría, Jefat. de Estudios, Dirección y
Otros; los centros que ya existían antes de esta funcionalidad los reciben automáticamente al
actualizar.

![Administración de motivos de salida del aula](img/centro/centro-motivos-salida.png)

Un motivo que ya se ha usado en alguna salida no se puede eliminar (aparece un aviso); desactívalo
en su lugar.

