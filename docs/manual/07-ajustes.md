# Ajustes

Cada ajuste puede tener hasta tres niveles de valor, según su ámbito: **global** (todo el
servidor), **de centro** y **de docente** (personal, solo para el propio usuario). Cuando existen
varios niveles con un valor propio, se resuelven en este orden de prioridad:

**docente > centro > global > valor por defecto**

## Bloqueo de ajustes

Un administrador global o de centro puede **bloquear** el valor de un ajuste con el icono del
candado, junto al campo. El bloqueo invierte el orden de prioridad habitual para forzar un valor
concreto en todos los niveles inferiores:

- Un ajuste **global bloqueado** se aplica siempre, sin excepción: ignora cualquier valor de centro
  o de docente, aunque existan.
- Un ajuste **de centro bloqueado** se aplica a todos los docentes de ese centro, ignorando
  cualquier valor personal que hayan guardado — pero sigue estando por debajo de un bloqueo global,
  si lo hay.

Solo se puede bloquear un ajuste que ya tiene un valor guardado explícitamente en ese nivel (no el
valor por defecto): el icono de candado aparece deshabilitado hasta que se guarda un valor.
Desbloquear un ajuste no borra el valor guardado, simplemente deja de forzarlo sobre los niveles
inferiores.

## Ajustes disponibles

La pantalla de ajustes agrupa cada uno en una de las siguientes categorías, mostradas en este
mismo orden: Visualización, Correo electrónico, Modo tablón, Notificaciones a familias y Avisos
por correo.

### Visualización

| Ajuste | Ámbito | Tipo | Rango | Por defecto |
|---|---|---|---|---|
| Resultados por página | Docente | Entero | 5-100 | 20 |

Número de elementos que se muestran en los listados paginados (partes, sanciones, estudiantes,
etc.). Es un ajuste exclusivamente personal: cada docente puede configurar el suyo desde su propio
perfil, y no admite valor global ni de centro.

### Correo electrónico

| Ajuste | Ámbito | Tipo | Por defecto |
|---|---|---|---|
| Activar notificaciones automáticas | Global, centro, docente | Booleano (sí/no) | Activado |

Activa o desactiva todos los correos automáticos de la aplicación (ver
[Notificaciones por email](06-notificaciones-y-email.md)). Es el único ajuste con los tres ámbitos
disponibles: un administrador global puede desactivarlos para todo el servidor, uno de centro para
su centro, y cada docente para sí mismo.

### Modo tablón

| Ajuste | Ámbito | Tipo | Rango | Por defecto |
|---|---|---|---|---|
| Duración de la semana actual | Global, centro | Entero (segundos) | 0-3600 | 15 |
| Duración de la semana siguiente | Global, centro | Entero (segundos) | 0-3600 | 5 |

Controlan cuántos segundos se muestra cada semana en el [modo tablón](05-secciones-de-la-aplicacion.md#modo-tablon)
del calendario antes de alternar a la otra. Si cualquiera de los dos vale 0, el modo tablón deja de
alternar y muestra solo la semana actual. No tienen ámbito de docente: se fijan a nivel global o de
centro únicamente.

### Notificaciones a familias

| Ajuste | Ámbito | Opciones | Por defecto |
|---|---|---|---|
| Quién notifica los partes de convivencia | Global, centro | El docente del parte / El tutor/a de grupo / Ambos | Ambos |
| Quién notifica las sanciones | Global, centro | El docente de la sanción / El tutor/a de grupo / Ambos | Ambos |

Determinan, además de los administradores, qué docentes pueden registrar una comunicación con la familia (ver [Notificaciones](05-secciones-de-la-aplicacion.md#notificaciones)). No tienen ámbito de docente: se fijan a nivel global o de centro únicamente.

### Avisos por correo

| Ajuste | Opciones | Por defecto |
|---|---|---|
| Parte registrado | A nadie / Al docente que lo registra / Al tutor/a de grupo / A ambos | A nadie |
| Parte notificado a la familia | A nadie / Al docente que lo registró / Al tutor/a de grupo / A ambos | A nadie |
| Parte modificado | A nadie / Al docente que lo registró / Al tutor/a de grupo / A ambos | A nadie |
| Parte eliminado | A nadie / Al docente que lo registró / Al tutor/a de grupo / A ambos | A nadie |
| Parte prescrito | A nadie / Al docente que lo registró / Al tutor/a de grupo / A ambos | A nadie |
| Parte incorporado a una sanción | A nadie / Al docente que lo registró / Al tutor/a de grupo / A ambos | A nadie |
| Sanción notificada a la familia | A nadie / A los docentes de los partes / Al tutor/a de grupo / A ambos | A nadie |
| Parte sancionable (comisión de convivencia) | A nadie / A la comisión de convivencia | A nadie |

Uno por cada evento de un parte o una sanción; determinan si se envía un correo y a quién. Los
siete primeros ajustes comparten las mismas opciones (docente que registró el elemento, tutor/a
del grupo, ambos o nadie); el aviso de parte modificado no se dispara al marcar un parte como
prescrito, que tiene su propio ajuste independiente. El último aviso a la comisión de convivencia
solo se envía cuando un parte queda notificado a la familia y todavía puede ser sancionado (no está
prescrito ni incorporado ya a una sanción). Ninguno tiene ámbito de docente: se fijan a nivel global
o de centro únicamente, y por defecto no se envía ningún correo. Más detalles sobre cada tipo de
aviso en [Notificaciones por email](06-notificaciones-y-email.md#avisos-de-partes-y-sanciones).
