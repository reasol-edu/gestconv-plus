---
marp: true
title: GestConv+ — Presentación
author: GestConv+
lang: es
paginate: true
header: 'GestConv+'
footer: 'v{{VERSION}} ({{PUB_DATE}}) · GestConv+'
style: |
  :root {
    --nx-ink: #1c2b1a;
    --nx-accent: #3e885b;
    --nx-accent-soft: #bedcfe;
    --nx-muted: #6b7280;
  }
  section {
    font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 26px;
    color: var(--nx-ink);
    padding: 56px 64px;
  }
  h1 { color: var(--nx-ink); font-size: 52px; }
  h2 { color: var(--nx-accent); font-size: 38px; border-bottom: 2px solid #c0d7bb; padding-bottom: 8px; }
  h3 { color: var(--nx-ink); font-size: 28px; }
  strong { color: var(--nx-accent); }
  table { font-size: 20px; }
  th { background: var(--nx-accent); color: #fff; }
  tr:nth-child(even) { background: var(--nx-accent-soft); }
  code { background: var(--nx-accent-soft); color: var(--nx-ink); }
  header { color: var(--nx-muted); font-size: 16px; }
  footer { color: var(--nx-muted); font-size: 14px; }
  section.lead { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
  section.lead h1 { font-size: 60px; margin-bottom: 0.2em; }
  section.sep { background: #2f4b26; color: #fff; justify-content: center; }
  section.sep h1 { color: #fff; }
  section.sep h2 { color: #85bda6; border: none; }
  section.tight { font-size: 23px; }
---

<!-- _class: lead -->

# GestConv+

## Guía práctica para el profesorado

---

## El problema

- La convivencia escolar genera **mucho papel**: partes en cuadernos, avisos por
  WhatsApp, sanciones en carpetas sueltas, hojas de cálculo por grupo…
- Cada docente lleva su propio registro y **nadie tiene la foto completa** del
  alumno: ni el tutor/a, ni la comisión de convivencia, ni la familia.
- Notificar a las familias es una tarea manual, fácil de olvidar y difícil de
  demostrar después ("¿se avisó o no se avisó?").
- El seguimiento de sanciones y medidas disciplinarias se pierde entre correos
  y documentos sin relacionar.

---

## ¿Qué es GestConv+?

Una aplicación web para gestionar toda la convivencia escolar del centro, con
un flujo único:

**Parte de convivencia → Notificación a la familia → Sanción (si procede)**

- Cada perfil ve **solo lo que le corresponde**: sus grupos, su centro, o todo
  el centro, según el rol.
- Todo queda **registrado y fechado**: quién hizo qué y cuándo.
- Funciona en **varios centros educativos** con una sola cuenta de acceso.
- Incluye calendario, búsqueda global, notificaciones pendientes e informes en
  PDF listos para imprimir o archivar.

---

## Los cuatro perfiles de esta guía

Esta presentación está organizada por lo que **necesita saber cada perfil**,
no por pantallas sueltas:

1. **Docente normal** — registra partes de sus grupos y notifica a familias.
2. **Tutor/a de grupo** — además, ve todo lo de su grupo, incluidas sus sanciones.
3. **Comisión de convivencia y orientación** — visión de todo el centro.
4. **Administradores de centro / equipo directivo** — configuran el curso y
   los ajustes de convivencia.

Cada bloque solo añade lo que ese rol tiene **de más** sobre el anterior.

---

## Cómo moverte por la aplicación

![width:360px](../manual/img/inicio.png)

- El **panel de inicio** muestra tarjetas con lo pendiente: partes sin
  notificar, tareas de seguimiento, avisos del centro.
- La **búsqueda global** (`⌘K` / `Ctrl+K`) encuentra un alumno, un grupo o un
  parte en segundos, sin navegar por menús.
- Si tienes acceso a **varios centros educativos**, un selector en la cabecera
  permite cambiar de centro sin cerrar sesión.
- El menú lateral se adapta a tu rol: solo muestra las secciones que puedes
  usar.

---

<!-- _class: sep -->

# Bloque 1

## Docente normal

---

## Qué puede hacer un docente normal

Sin ningún rol especial, todo docente puede:

- **Registrar partes de convivencia** de los estudiantes de sus grupos.
- Consultar y filtrar **los partes que él mismo ha registrado**.
- **Notificar a la familia** los partes cuyo aviso le corresponde (según el
  ajuste "quién notifica" del centro).
- Consultar la **ficha del alumno** con la información básica de su historial
  de convivencia.
- Ver su **calendario** y las **notificaciones pendientes** que le afectan.

Todo lo que sigue en este bloque está disponible para cualquier docente, sin
necesidad de ser tutor/a ni de pertenecer a la comisión de convivencia.

---

<!-- _class: tight -->

## Registrar un parte de convivencia (paso a paso)

![width:300px](../manual/img/partes/nuevo-parte-vacio.png)

1. Desde el listado de partes, pulsa **"Nuevo parte"**.
2. Selecciona el **grupo** y, dentro de él, el o los **estudiantes** implicados.
3. Elige la **conducta** del catálogo del centro (o varias, si aplica).
4. Redacta la **descripción de los hechos** en el editor de texto.
5. Guarda el parte: queda visible de inmediato para quien deba notificarlo.

Un mismo parte puede implicar a **varios estudiantes** a la vez si el
incidente los afecta conjuntamente.

---

## Consultar y filtrar "Mis partes"

![width:360px](../manual/img/partes/partes-listado.png)

- El listado muestra los partes **registrados por ti**, con filtros por
  grupo, estudiante, fecha y estado de notificación.
- El **estado de notificación** se ve de un vistazo: notificado o pendiente.
- Puedes ordenar por antigüedad para priorizar los partes más urgentes de
  notificar.
- Desde aquí se accede al detalle de cada parte con un clic.

---

## Ver el detalle de un parte y añadir observaciones

![width:360px](../manual/img/partes/parte-editar.png)

- El detalle reúne los datos del incidente: estudiantes, conducta, fecha y
  descripción original.
- Se pueden añadir **observaciones posteriores** sin alterar el relato inicial
  de los hechos (por ejemplo, cómo se resolvió).
- Cualquier cambio queda **fechado y registrado**.
- Desde aquí también se genera el **informe en PDF** del parte.

---

<!-- _class: tight -->

## Notificar un parte a la familia

![width:300px](../manual/img/notificaciones/notificaciones-registrar-parte.png)

1. Entra en **Notificaciones → Pendientes** o accede desde el propio parte.
2. Pulsa **"Notificar"** y elige el **método** utilizado (llamada, correo,
   entrevista presencial…).
3. Indica la **fecha, hora y resultado**: informada o no localizada.
4. Añade observaciones opcionales y registra la comunicación.

El parte pasa a **"Notificado"** y queda en el historial.

---

<!-- _class: tight -->

## Notificar varios partes a la vez de un mismo estudiante

![width:300px](../manual/img/notificaciones/parte-badge-notificado.png)

- Si un estudiante acumula **varios partes pendientes de notificar**,
  cualquier docente que pueda notificarlos puede hacerlo **en un solo paso**,
  en lugar de repetir el proceso parte a parte.
- Se seleccionan los partes que se quieren incluir en la comunicación y se
  registra **un único método, fecha y resultado** para todos ellos.
- Esta opción **no es exclusiva de los tutores/as**: está disponible para
  cualquier docente con permiso de notificación sobre esos partes, según el
  ajuste "quién notifica" configurado por el centro.

---

## Ficha del alumno: qué ve un docente normal

- Datos básicos del estudiante y su grupo.
- Listado de **partes y sanciones** en los que aparece, con su estado.
- Acceso directo para registrar un nuevo parte sobre ese mismo estudiante.
- Un docente normal ve aquí **la misma información que en sus listados**: solo
  lo relativo a los grupos donde imparte docencia.

---

<!-- _class: sep -->

# Bloque 2

## Tutor/a de grupo

---

## Qué añade el rol de tutor/a de grupo

Sobre todo lo del docente normal, el tutor/a de grupo obtiene una **visión
completa de su grupo**, no solo de los partes que él mismo registra:

- Ve **todos los partes y sanciones** de los estudiantes de su tutoría,
  los haya registrado él/ella u otro docente.
- Puede quedar habilitado como **quien notifica** partes y sanciones del
  grupo, según el ajuste del centro.
- Accede al **seguimiento** de firmas y tareas pendientes de su tutoría.
- Ve el **estado de las sanciones** de su alumnado, aunque crearlas es
  competencia de la comisión de convivencia.

---

## Visión de grupo: partes y sanciones de mis estudiantes

- El tutor/a filtra por **su grupo completo**, no solo por lo que él mismo ha
  registrado: ve la actividad de todos los docentes que atienden a su
  alumnado.
- Esto permite detectar **patrones**: un estudiante con partes repetidos de
  distintos docentes, por ejemplo.
- Desde este listado se accede igual que un docente normal al detalle de
  cada parte y a su notificación.

---

<!-- _class: tight -->

## Historial de notificaciones del grupo

![width:300px](../manual/img/notificaciones/notificaciones-registrar-parte-historial.png)

- Registro completo de **todas las comunicaciones** ya realizadas a las
  familias del grupo: método, fecha, resultado y quién la registró.
- Filtros por tipo (parte o sanción), resultado y búsqueda por nombre de
  estudiante, grupo o docente.
- Sirve como evidencia documental ante cualquier consulta posterior de la
  familia, la dirección o la inspección educativa.

---

## Seguimiento de firmas y tareas pendientes

- El tutor/a recibe avisos de tareas propias de su grupo, por ejemplo:
  - Estudiantes **sin puesto asignado** en el calendario de convivencia.
  - **Firmas pendientes** próximas a vencer.
  - Partes o sanciones **todavía sin notificar** a la familia.
- Estos avisos aparecen tanto en el **panel de inicio** como en la campana de
  notificaciones, para no perder de vista lo urgente.

---

## Sanciones de mi alumnado: seguimiento, no creación

- El tutor/a **ve las sanciones** ya impuestas a su alumnado, con su medida,
  estado y notificación a la familia — igual que ve los partes.
- **Crear una sanción es competencia de la comisión de convivencia**, no del
  tutor/a: así la decisión la valora un grupo, no una sola persona.
- Si el tutor/a forma parte además de la comisión (perfil ampliado, ver
  Bloque 4), sí puede crear sanciones desde su propia cuenta.
- Ante un caso que lo requiera, el tutor/a traslada la situación a la
  comisión para que valore la sanción.

---

## En resumen: el día a día de un tutor/a

- Revisar cada mañana el **panel de inicio**: pendientes de notificar y
  tareas del grupo.
- Consultar el **historial del grupo** para tener contexto antes de hablar
  con una familia o con dirección.
- Notificar partes y sanciones **en cuanto se registran**, para no acumular
  pendientes.
- Usar la **notificación conjunta** cuando un estudiante tenga varios partes
  del mismo periodo.

---

<!-- _class: sep -->

# Bloque 3

## Comisión de convivencia y orientación

---

## Qué ven la comisión y orientación

A diferencia de docentes y tutores/as, estos perfiles tienen **visión de todo
el centro**, no solo de sus propios grupos:

- Todos los **partes de convivencia** registrados en el centro, de cualquier
  grupo y docente.
- Todas las **sanciones** aplicadas, con su motivo y periodo.
- El **historial completo** de notificaciones a las familias.
- Acceso a **informes y exportaciones** para preparar reuniones de
  seguimiento.

---

## Consultar y filtrar todos los partes del centro

- El listado permite filtrar por **grupo, docente, estudiante, conducta y
  fecha**, sin restringirse a un grupo concreto.
- Es la herramienta habitual para preparar las **reuniones de la comisión de
  convivencia**: detectar estudiantes o grupos con más incidencias.
- El acceso de solo consulta no impide que, si el perfil también es docente
  de algún grupo, pueda además registrar partes propios.

---

## Registrar y editar sanciones

- La comisión y orientación pueden registrar sanciones sobre **cualquier
  estudiante del centro**, no solo de sus propios grupos.
- El catálogo de **medidas disciplinarias** es el mismo que usan los
  tutores/as, definido por el centro.
- Las sanciones quedan enlazadas a los partes que las motivaron, de forma que
  el historial del estudiante se lee como un relato completo.

---

## Historial completo de notificaciones

- Vista de centro de **todas las comunicaciones** registradas: partes y
  sanciones, de todos los grupos.
- Permite comprobar que ninguna familia se ha quedado sin ser informada,
  independientemente de qué docente gestionó el caso.
- Los mismos filtros de búsqueda, tipo y resultado que en la vista de grupo
  del tutor/a, pero sin restricción de grupo.

---

## Informes y exportaciones

- Cada parte y cada sanción se puede **exportar a PDF** con un formato
  homogéneo, listo para archivar o entregar.
- Los datos principales (alumno, grupo, docente, fecha, estado) aparecen en
  una tabla al comienzo, y las conductas marcadas se agrupan por categoría en
  columnas.
- El centro puede **personalizar el encabezado** de estos informes (título,
  marcadores como el nombre del centro o del estudiante, y el margen
  superior) desde los ajustes de centro.
- Útil para expedientes, reuniones con familias o traslados de expediente
  entre centros.

---

## El calendario

![width:360px](../manual/img/calendario/calendario.png)

- Muestra la actividad de convivencia distribuida por fechas: seguimientos,
  firmas y tareas pendientes de cada grupo.
- Filtra por grupo o por estudiante para preparar una reunión o una tutoría.

---

## Modo tablón

![width:360px](../manual/img/calendario/calendario-tablon.png)

- Vista simplificada del calendario, pensada para **pantallas compartidas**
  en sala de profesores o pasillos.
- No muestra datos sensibles de estudiantes: solo la actividad agregada del
  centro.

---

<!-- _class: sep -->

# Bloque 4

## Administradores de centro / equipo directivo

---

## Preparar el curso: año académico y oferta formativa

- El administrador de centro **activa el año académico** con el que va a
  trabajar todo el profesorado.
- Se define la **oferta formativa**: familias profesionales, enseñanzas y
  cursos, mediante un editor de tres columnas encadenadas.
- Esta estructura es la base sobre la que luego se organizan los **grupos**
  del centro.

---

## Importar docentes y estructurar grupos

- Los **docentes** se dan de alta importando el listado exportado de Séneca
  en formato CSV, evitando la introducción manual uno a uno.
- A partir de la oferta formativa se crean los **grupos** del curso (por
  ejemplo, 2º ESO A, 1º Bachillerato B…).
- Cada grupo queda asociado a su enseñanza y curso correspondientes.

---

## Importar estudiantes

- El alumnado se importa igualmente desde un **CSV exportado de Séneca**,
  con columnas obligatorias (nombre, grupo…) y opcionales (datos de
  contacto familiar, si se quieren aprovechar).
- La importación **valida los datos** antes de confirmarlos y avisa de
  filas con errores para corregirlas antes de dar de alta al alumnado.

---

## Asignar tutores y docentes a grupos

- Se importa, también vía CSV de Séneca, la relación de **qué docente
  imparte en qué grupo** y **quién es el tutor/a** de cada uno.
- Un mismo docente puede impartir en **varios grupos**; un grupo tiene
  **un único tutor/a** de referencia.
- Estas asignaciones son las que determinan qué ve cada docente al entrar en
  la aplicación.

---

<!-- _class: tight -->

## Configurar quién notifica partes y sanciones

![width:300px](../manual/img/notificaciones/ajustes-notificaciones.png)

El centro decide, con un ajuste global, quién se encarga de notificar a las
familias:

- **El docente que registra el parte.**
- **El tutor/a del grupo.**
- **Ambos** (opción por defecto, más flexible en el día a día).

Este ajuste es el que determina si la notificación conjunta de varios partes
está disponible para un docente normal, para el tutor/a, o para ambos.

---

<!-- _class: tight -->

## Métodos de comunicación con las familias

![width:300px](../manual/img/notificaciones/admin-metodos-comunicacion.png)

- El centro define su propio catálogo de **métodos de comunicación**:
  llamada telefónica, correo electrónico, entrevista presencial, etc.
- Solo los métodos **activos** aparecen como opción al registrar una
  notificación.
- Si el centro no tiene ningún método activo, no se puede registrar ninguna
  comunicación hasta configurarlo.

---

## Personalización de informes PDF

- Los encabezados de los informes de **partes** y **sanciones** son
  configurables de forma independiente, con texto enriquecido a la izquierda
  y a la derecha, y un margen superior en milímetros.
- Se pueden usar **marcadores** que la aplicación sustituye automáticamente:
  título del informe, número de parte, nombre del estudiante, grupo, centro
  o curso académico.
- El ajuste admite un valor **global** (para todos los centros) y uno
  **de centro**, que lo sobrescribe si está definido.

---

<!-- _class: tight -->

## Ajustes de centro: candados y catálogos

![width:300px](../manual/img/partes/admin-conductas.png)

- Cada ajuste puede **bloquearse** a nivel global para impedir que un centro
  lo modifique, o dejarse libre para que cada centro lo personalice.
- El **catálogo de conductas**, el de **medidas disciplinarias** y el de
  **métodos de comunicación** también se gestionan desde aquí, adaptándolos a
  las normas de convivencia propias del centro.
- Los tres catálogos se pueden **exportar e importar en JSON** para copiar la
  configuración entre centros, con la opción de vaciar lo existente antes de
  importar.

---

## Ajustes de centro: avisos y modo tablón

- Los **avisos por correo** (nueva tutoría asignada, puestos creados,
  recordatorio de firma…) se activan o desactivan por centro.
- Los avisos de parte y de sanción pueden llevar además el **PDF adjunto**,
  con un ajuste independiente para cada uno.
- El **modo tablón** del calendario también se configura desde aquí, por si
  el centro prefiere no mostrarlo en pantallas compartidas.

---

## Perfiles y roles especiales

- Además de docente y tutor/a, el administrador de centro puede conceder los
  roles de **comisión de convivencia** y **orientador/a** a cualquier
  docente del centro.
- Estos roles amplían el alcance de visibilidad a **todo el centro**, tal y
  como se ha visto en el bloque anterior, sin necesidad de ser tutor/a de
  ningún grupo.
- Un mismo docente puede combinar varios roles a la vez (por ejemplo, ser
  tutor/a de un grupo y además miembro de la comisión).

---

## Registro de actividad y avisos por correo

- El **registro de actividad** deja constancia de las acciones relevantes
  realizadas en la aplicación, disponible para la administración global.
- El **registro de avisos por correo** permite comprobar qué avisos se han
  enviado, a quién y si la entrega ha tenido éxito.
- Ambos registros son la referencia para resolver dudas de tipo "¿esto se
  llegó a notificar de verdad?".

---

<!-- _class: sep -->

# En resumen

## Un flujo, cuatro perfiles

---

## En resumen: un flujo, cuatro perfiles

- **Parte → Notificación → Sanción**: el mismo flujo para todo el centro.
- Cada perfil ve exactamente lo que necesita:
  - Docente normal: sus grupos.
  - Tutor/a: todo su grupo.
  - Comisión y orientación: todo el centro.
  - Administración: la configuración de todo lo anterior.
- Todo queda **registrado, fechado y trazable**, sin depender de cuadernos ni
  hojas sueltas.

---

## Recursos y soporte

- El **manual de usuario completo** detalla cada pantalla y cada opción de
  configuración, con capturas paso a paso.
- Ante cualquier duda sobre un permiso o un ajuste concreto, consulta primero
  la sección de **ajustes** o el capítulo de **roles y permisos** del manual.
- El equipo del centro que administra GestConv+ es el primer punto de
  contacto para incidencias o solicitudes de cambio de configuración.

---

<!-- _class: lead -->

# Gracias

## GestConv+ · Gestión de la convivencia escolar
