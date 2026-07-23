# Permisos de un vistazo

Este capítulo es una referencia rápida: qué puede hacer cada perfil, resumido en tablas. Los
capítulos anteriores enlazan aquí cada vez que un permiso entra en juego.

## Los perfiles en una línea

| Perfil | En pocas palabras |
|---|---|
| **Docente** | Registra partes y ve solo los suyos |
| **Tutor/a de grupo** | Además, ve los partes y sanciones de su grupo |
| **Comisión de convivencia** | Ve todo el centro y registra las sanciones |
| **Orientador/a** | Lo consulta todo, sin editar nada |
| **Administración de centro** | Configura el centro y tiene acceso completo a sus datos |
| **Administración global** | Mantiene la plataforma y todos los centros del servidor |

Los perfiles de **comisión de convivencia** y **orientador/a** se asignan a docentes concretos
desde la tarjeta Perfiles del panel del centro (ver
[Administrar el centro educativo](06-administrar-el-centro.md#perfiles)). La condición de
**tutor/a** viene de la asignación de tutorías en la oferta formativa (ver
[Preparar el curso académico](02-preparar-el-curso-academico.md#4-asignar-las-tutorias-de-grupo)).
El resto del profesorado asignado a un grupo, sin ser su tutor/a, no obtiene por ello visibilidad
adicional sobre los partes del grupo.

## Partes de convivencia

| Acción | Docente | Tutor/a del grupo | Comisión | Orientador/a | Admin de centro | Admin global |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Ver la sección Partes | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Registrar un nuevo parte | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver sus propios partes | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver todos los partes del grupo | — | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver todos los partes del centro | — | — | ✓ | ✓ | ✓ | ✓ |
| Editar sus propios partes | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Editar partes ajenos | — | — | — | — | ✓ | ✓ |
| Reasignar el docente o el estudiante de un parte | — | — | — | — | ✓ | ✓ |
| Eliminar partes | — | — | — | — | ✓ | ✓ |

La comisión de convivencia y la orientación tienen visibilidad ampliada sobre los partes ajenos,
pero no pueden editarlos ni eliminarlos.

## Sanciones

| Acción | Docente | Tutor/a del grupo | Comisión | Orientador/a | Admin de centro | Admin global |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Ver sanciones de partes propios | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver sanciones del grupo tutorizado | — | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver todas las sanciones del centro | — | — | ✓ | ✓ | ✓ | ✓ |
| Registrar una nueva sanción | — | — | ✓ | — | ✓ | ✓ |
| Editar o eliminar sanciones | — | — | ✓ | — | ✓ | ✓ |

Solo la comisión de convivencia comparte con los administradores la capacidad de registrar,
editar y eliminar sanciones de cualquier estudiante. La orientación solo puede consultarlas.

## Notificaciones a las familias

Quién puede registrar una comunicación (ver
[Notificaciones](03-el-trabajo-diario.md#notificaciones)) depende de dos ajustes de centro —
*Quién notifica los partes* y *Quién notifica las sanciones* — con tres valores posibles: **el
docente que registró** el parte o la sanción, **el tutor/a del grupo**, o **ambos** (valor por
defecto). Ver [Ajustes del centro](06-administrar-el-centro.md#ajustes-del-centro).

| Acción | Docente autor | Tutor/a del grupo | Comisión | Orientador/a | Admin de centro | Admin global |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Ver la cola de Notificaciones | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Notificar un parte | según ajuste | según ajuste | — | — | ✓ | ✓ |
| Notificar una sanción | según ajuste | según ajuste | ✓ | — | ✓ | ✓ |

La cola de pendientes muestra a cada docente los elementos que puede ver según las reglas de
visibilidad de partes y sanciones; el botón **Notificar** solo aparece en los que además puede
notificar.

## Ausencias

Las [ausencias](03-el-trabajo-diario.md#ausencias) son privadas para el profesorado normal: cada
docente solo gestiona las suyas, y deja de poder modificarlas en cuanto pasa su fecha de fin. Los
administradores, en cambio, tienen acceso completo a las de cualquier docente del centro.

| Acción | Propietario (antes de la fecha de fin) | Propietario (tras la fecha de fin) | Otro docente | Admin de centro | Admin global |
|---|:---:|:---:|:---:|:---:|:---:|
| Ver la sección Ausencias | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver la pestaña *Ausencias del centro* | — | — | — | ✓ | ✓ |
| Ver una ausencia y sus actividades | ✓ | ✓ | — | ✓ | ✓ |
| Crear una ausencia (propia o de otro docente) | ✓ | — | — | ✓ | ✓ |
| Editar o eliminar la ausencia | ✓ | — | — | ✓ | ✓ |
| Añadir, editar o eliminar actividades y adjuntos | ✓ | — | — | ✓ | ✓ |

Los administradores de centro y globales pueden registrar una ausencia para cualquier docente que
pertenezca al curso académico visualizado, y su acceso no se ve afectado por el bloqueo de fecha que
sí aplica al profesorado normal. Ningún otro docente, ni siquiera compartiendo grupo, puede ver
las ausencias de otra persona.

## Tareas de sanción

Las [tareas de sanción](03-el-trabajo-diario.md#tareas-de-sancion) solo se generan para sanciones
con una medida de rango de fechas, una por cada docente y materia del grupo.

| Acción | Docente asignado a la tarea | Otro docente de materia | Tutor/a del grupo | Comisión | Orientador/a | Admin de centro | Admin global |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| Ver la sección Tareas de sanción (propias) | ✓ | ✓* | — | — | — | — | — |
| Cumplimentar o editar una tarea | ✓ | — | — | — | — | — | — |
| Ver el bloque de seguimiento en el detalle de la sanción | — | — | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver el contenido de una tarea ajena desde ese bloque | — | — | — | — | — | — | — |
| Refrescar las materias de una sanción | — | — | — | ✓ | — | ✓ | ✓ |

\* La sección es visible para cualquier docente que imparta materia a algún grupo en el curso
visualizado, pero cada uno ve únicamente sus propias tareas.

El bloque de seguimiento del detalle de la sanción muestra el estado de cada tarea (pendiente,
cumplimentada o no procede) a quien ya puede ver esa sanción, siguiendo las mismas reglas de
visibilidad que la [tabla de Sanciones](#sanciones) — pero sin dar acceso al contenido de las
tareas de otros docentes, que sigue siendo exclusivo de quien la tiene asignada. **Refrescar
materias** exige además el permiso de edición sobre la sanción, igual que registrar o eliminarla.

## Guardias

Las [guardias](03-el-trabajo-diario.md#guardias) muestran, para el tramo horario y día
visualizados, las ausencias con sus actividades y el alumnado sancionado del centro.

| Acción | Docente con guardia ese curso | Docente sin ninguna guardia | Admin de centro | Admin global |
|---|:---:|:---:|:---:|:---:|
| Ver la sección Mis guardias / Guardias | ✓ | — | ✓ | ✓ |
| Ver los tramos horarios propios de un día | ✓ | — | ✓ | ✓ |
| Ver todos los tramos horarios de un día (no solo los propios) | — | — | ✓ | ✓ |
| Descargar adjuntos de actividades y tareas de sanción mostrados en la pantalla | ✓ | — | ✓ | ✓ |
| Modificar los datos de un tramo horario desde el botón junto a su título | — | — | ✓ | ✓ |

El acceso a la sección no depende de tener guardia justo el día que se está viendo, sino de tener
guardia en **algún** tramo horario del curso académico visualizado; eso permite navegar a otros días
con fines de consulta. Quien entra por tener guardia en algún tramo, aunque ese día concreto no le
corresponda ninguno, ve la pantalla vacía para ese día en lugar de los tramos de otros docentes.

## Centro educativo

| Acción | Docente | Admin de centro | Admin global |
|---|:---:|:---:|:---:|
| Ver el panel del centro | — | ✓ | ✓ |
| Gestionar docentes del curso | — | ✓ | ✓ |
| Gestionar oferta formativa | — | ✓ | ✓ |
| Gestionar estudiantes | — | ✓ | ✓ |
| Gestionar conductas, medidas y métodos de comunicación | — | ✓ | ✓ |
| Gestionar días no lectivos, con importación desde iCal | — | ✓ | ✓ |
| Gestionar perfiles (comisión y orientación) | — | ✓ | ✓ |

## Administración global

| Acción | Admin global |
|---|:---:|
| Gestionar centros educativos | ✓ |
| Gestionar docentes de la plataforma | ✓ |
| Ver el registro de actividad | ✓ |
| Configuración global | ✓ |

## Otras acciones y permisos generales

- Cualquier docente puede ver y editar su propio perfil (nombre, contraseña, correo electrónico).
- Las secciones de **Inicio** y **Calendario** son accesibles para todo el profesorado
  autenticado con un centro seleccionado. El **modo tablón** solo pueden activarlo los
  administradores (ver [Calendario y tablón](05-calendario-y-tablon.md#modo-tablon)).
- En la [ficha del estudiante](03-el-trabajo-diario.md#ficha-del-estudiante), los **datos de
  contacto de la familia** solo son visibles para los administradores, la comisión de
  convivencia, la orientación y los tutores/as del grupo del estudiante; el historial muestra a
  cada docente únicamente los partes y sanciones que ya puede ver según las tablas anteriores. De
  entre todos ellos, solo el **tutor/a del grupo** puede además **editar** esos datos de contacto.
- La sección [Mi tutoría](03-el-trabajo-diario.md#mi-tutoria) del menú lateral solo es visible
  para quien sea **tutor/a de al menos un grupo** en el curso académico visualizado.
