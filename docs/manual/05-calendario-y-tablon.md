# Calendario y tablón

Cuando una sanción se comunica a la familia, entra en la parte más visible de la aplicación: el
**calendario** de sanciones y su versión para pantallas del centro, el **modo tablón**. El
objetivo de ambos es el mismo: que todo el claustro sepa, de un vistazo, qué sanciones están en
vigor cada día.

## Calendario

La sección **Calendario** del menú lateral muestra, en una vista mensual, las sanciones del curso
activo que tienen fecha de inicio (y, opcionalmente, de fin).

![Vista mensual del calendario de sanciones](img/calendario/calendario.png)

- Se muestran **todas las sanciones del curso ya comunicadas a la familia y con fecha**, sin
  filtrar por autoría ni tutoría: cualquier docente del centro las ve todas. Las pendientes de
  comunicar no aparecen hasta que se registra la comunicación (ver
  [Notificaciones](03-el-trabajo-diario.md#notificaciones)).
- Cada sanción aparece como una barra horizontal que puede abarcar varios días. Dentro de la
  barra se muestra el nombre del estudiante, su grupo y la
  **descripción para calendario y tablón** de la sanción — o, si ese campo se dejó en blanco, el
  texto completo del campo Detalle. Por eso conviene rellenarlo siempre con algo corto y
  descriptivo, tipo «Expulsión» o «Aula de convivencia» (ver
  [Registrar una sanción](04-sanciones-y-comision.md#registrar-una-sancion)).
- El color de la barra depende del grupo: todas las sanciones de estudiantes del mismo grupo
  comparten color, lo que permite distinguir de un vistazo a qué grupos pertenecen las sanciones
  de una misma semana.
- Solo se muestran los días de lunes a viernes; los fines de semana no aparecen en la cuadrícula.
- El día actual se resalta con un color de fondo distinto en toda su columna.

## Modo tablón

El botón **Modo tablón**, junto al botón *Hoy* del calendario, abre una vista a pantalla completa
pensada para dejarse fija en una pantalla del centro — por ejemplo, en la sala de profesorado.
Solo pueden activarlo los administradores; el resto de docentes no ve el botón ni puede acceder a
la vista directamente.

![Modo tablón con la semana actual](img/calendario/calendario-tablon.png)

- Muestra una semana (lunes a viernes) en cinco columnas, de modo que todo se lea sin tocar la
  pantalla.
- Para cada día se listan las sanciones que lo cubren, agrupadas por grupo, con el estudiante, la
  descripción para calendario y tablón (o el detalle, si aquella está en blanco) y las fechas de
  inicio y fin. Si el contenido de un día no cabe en la columna, se desplaza automáticamente
  arriba y abajo.
- La semana actual y la siguiente **se alternan automáticamente** con una transición suave, para
  que también se vea lo que viene.
- Un botón en la esquina superior alterna la pantalla completa del navegador; otro, con icono de
  encendido/apagado, cierra la sesión.

!!! warning "El tablón es una sesión sin salida"
    Una vez activado el modo tablón, esa sesión del navegador no puede navegar a ninguna otra
    parte de la aplicación: cualquier intento redirige de vuelta al tablón. La única salida es
    cerrar sesión con el botón de encendido/apagado. Así, la pantalla del centro puede quedarse
    encendida sin riesgo de que alguien la use para consultar otros datos.

## Ajustes del modo tablón

Los administradores pueden afinar el comportamiento del tablón desde **Ajustes** (ver
[Sistema de ajustes](07-administrar-la-plataforma.md#sistema-de-ajustes)):

| Ajuste | Rango | Por defecto |
|---|---|---|
| Duración de la semana actual | 0-3600 segundos | 15 |
| Duración de la semana siguiente | 0-3600 segundos | 5 |
| Tema del modo tablón | Claro / Oscuro / Según el sistema | Claro |

Las dos duraciones controlan cuántos segundos se muestra cada semana antes de alternar a la otra.
Si cualquiera de las dos vale 0, el tablón deja de alternar y muestra solo la semana actual.

El tema controla la combinación de colores: claro, oscuro, o **según el sistema**, que sigue en
todo momento la preferencia de color del dispositivo que muestra el tablón, incluidos los cambios
que se produzcan con la pantalla ya abierta, sin recargarla.

Los tres ajustes se fijan a nivel global o de centro; no existen como preferencia individual de
docente.
