# Informe de mejora de UX — GestConv Plus

*Fecha: 03/07/2026 · Ámbito: revisión del flujo de trabajo completo (acceso → selección de centro/curso → panel → partes → notificaciones → sanciones → calendario/tablón)*

## Resumen ejecutivo

La aplicación tiene una base de UX sólida: navegación lateral clara, filtros en vivo en el listado de partes, cola de notificaciones pendientes, banner de curso histórico, diálogos de confirmación y un trabajo reciente de accesibilidad e i18n visible en el historial. Los problemas detectados no son de estética sino de **fricción en el flujo de trabajo**: la aplicación está organizada por *tipo de documento* (partes, sanciones, notificaciones) mientras que el usuario piensa en términos de *alumno* y de *"qué me falta por hacer"*. Las mejoras de mayor impacto son las que acortan la cadena parte → notificación → sanción → notificación, que es el corazón normativo de la aplicación.

Prioridades: **A** = alto impacto en el trabajo diario · **B** = mejora notable de coherencia/eficiencia · **C** = pulido.

---

## Prioridad A — Fricción en el flujo principal

### A1. El docente raso no tiene botón «Nuevo parte» en el inicio (contradice el manual)

`templates/dashboard/index.html.twig:38` condiciona los accesos rápidos a `can_students_section` (equipo directivo). Sin embargo, el manual (`docs/manual/04-flujo-de-trabajo.md`, paso 5) dice: *«cualquier docente puede registrar partes… desde el botón de acceso rápido del inicio»*. El usuario más numeroso (docente sin cargo) aterriza en el panel sin ninguna llamada a la acción para su tarea principal y debe descubrirla en el menú lateral.

**Propuesta:** mostrar «Nuevo parte» a todo docente (con curso activo y sin estar en curso histórico) y reservar «Importar alumnado» al equipo directivo. Idealmente, promocionar «Nuevo parte» a botón primario destacado, no a chip secundario: es la acción más frecuente de la aplicación.

### A2. Tras crear un parte no se ofrece el siguiente paso: notificar a la familia

`src/Controller/IncidentReportController.php:223-225`: al guardar, se redirige al listado con un flash genérico. Pero el propio manual establece que **un parte sin comunicación exitosa no puede incorporarse a una sanción**: notificar es el paso obligado inmediato. Hoy el docente debe ir a «Notificaciones», localizar el parte en la cola y entrar en él.

**Propuesta:** tras crear el parte, redirigir a una pantalla de confirmación (o al propio parte) con acciones directas:
- «Notificar a la familia ahora» (enlace a `app_notifications_register_report`) — acción primaria si el usuario tiene permiso `incident_report.notify`.
- «Crear otro parte» (caso habitual: incidente con varios grupos/momentos).
- «Volver al listado».

Con varios alumnos implicados se crean N partes; la confirmación debería listar los N con su enlace de notificación respectivo, porque hoy se pierden en el listado general.

### A3. No existe la «ficha del alumno» (expediente)

Todo el modelo mental de tutores y jefatura de estudios es *por alumno* («¿cómo va Fulanito?»), pero no hay ninguna vista que agregue sus partes, sanciones y comunicaciones. Síntomas:

- La búsqueda global (⌘K) devuelve alumnos, pero el enlace lleva al **formulario de edición administrativa** (`src/Controller/SearchController.php:57`, `app_admin_students_edit`), una pantalla de datos personales a la que además muchos docentes no tienen acceso.
- Desde un parte no se puede saltar al historial del alumno; desde una sanción tampoco.
- La única vista agregada por alumno es la tabla intermedia de «Nueva sanción» (`new_select_student`), que ya calcula partes sancionables/graves/prescritos… pero solo sirve para crear sanciones.

**Propuesta:** crear una vista `app_students_show` (solo lectura, respetando los voters actuales) con: datos básicos, contadores (partes, graves, prescritos, sanciones activas), listado cronológico de partes y sanciones con su estado de notificación, y accesos «Nuevo parte» / «Nueva sanción» precargados. Enlazar el nombre del alumno desde: resultados de búsqueda, listado de partes, detalle de parte/sanción y panel («alumnado con más partes»).

### A4. El listado de sanciones carece de búsqueda, filtros, paginación y estado de notificación

`src/Controller/SanctionController.php:38-57` carga **todas** las sanciones del centro sin paginar, y `templates/sanction/index.html.twig` no ofrece buscador ni filtros — en fuerte contraste con el listado de partes (Live Component con búsqueda, grupo, gravedad, fechas y «solo mis partes»). Además no muestra si la sanción está **notificada**, que es precisamente la condición para que entre en vigor y aparezca en el calendario/tablón: una sanción «invisible» en el tablón no da ninguna pista de por qué.

**Propuesta:** reutilizar el patrón `PaginatedListTrait` + Live Component ya unificado para los listados, añadir columna/badge «Notificada / Pendiente» (con enlace a registrar comunicación, como en partes) y filtros mínimos: alumno/grupo, vigentes hoy, pendientes de notificar.

### A5. La cola de notificaciones no encadena tareas

Tras registrar una comunicación se vuelve a la misma página del parte/sanción (`src/Controller/NotificationController.php:84,119`). Si el jefe de estudios tiene 8 pendientes, hace 8 veces el ciclo cola → elemento → registrar → volver manualmente a la cola.

**Propuesta:** tras registrar con éxito, redirigir a la cola (o mejor: ofrecer «Registrar siguiente pendiente») conservando el flash. La cola además crecerá: le vendrían bien orden por antigüedad visible (p. ej. «hace 3 días» con énfasis a partir de N días) y filtro por grupo.

---

## Prioridad B — Coherencia y eficiencia

### B1. Formularios largos con validación solo en servidor y arriba del todo

En `incident/new.html.twig` y `sanction/new.html.twig` los errores se muestran en un bloque superior; en un formulario de 3-4 tarjetas el campo erróneo queda fuera de pantalla. Ningún campo obligatorio está marcado (`occurred_at`, alumnado, conductas…).

**Propuesta:** marcar obligatorios (asterisco + `required`/`aria-required`), mostrar el error junto al campo y hacer scroll/focus al primer error. Mantener el resumen superior como complemento.

### B2. Listas de conductas y medidas sin ayuda a la selección

Un centro puede tener decenas de conductas/medidas; hoy son listas planas de checkboxes sin buscador ni contador. En móvil, marcar 2 conductas entre 25 es incómodo.

**Propuesta:** filtro de texto local (sin servidor), contador «N seleccionadas» pegado al título de sección, y en conductas destacar visualmente el bloque de graves (ya se colorea el epígrafe; falta jerarquía al escanear).

### B3. Paleta de comandos infrautilizada

Solo busca alumnos y docentes (`SearchController`). No ofrece acciones («Nuevo parte», «Ir a notificaciones», «Cambiar de curso») ni resultados de partes/sanciones por número. Con A3 resuelto, el resultado de alumno debería llevar a su ficha, no al formulario de edición.

**Propuesta:** añadir un grupo estático de «Acciones» filtrable por texto (barato: se resuelve en cliente) y, más adelante, búsqueda de partes por `#número`.

### B4. Experiencia móvil desigual entre listados

El listado de partes usa el patrón responsive `table-cards`; los de sanciones, selección de alumno para sanción y colas de notificación usan tablas con scroll horizontal. El manual describe uso en el aula (registrar un parte al momento), donde el móvil es el dispositivo natural.

**Propuesta:** extender `table-cards` (con sus `data-label`) a esos cuatro listados.

### B5. Búsqueda de alumno para sanción no es en vivo

`sanction/new_select_student.html.twig` usa GET + botón «Aplicar», mientras el resto de listados filtran al teclear. Inconsistencia pura de patrón.

### B6. Panel: la tarjeta de «pendientes de notificar» desaparece cuando es cero

`dashboard/index.html.twig:95` solo la pinta si `pendingQueue.total > 0`. Eso provoca salto de maquetación entre días y elimina el refuerzo positivo. Mejor mostrarla siempre: en verde con «Todo al día» cuando sea 0. La cifra en rojo con enlace a la cola cuando no lo esté ya funciona bien.

---

## Prioridad C — Pulido

- **C1. Títulos de pestaña genéricos en los detalles.** `incident/show.html.twig:3` titula «Partes de convivencia · Centro…» para cualquier parte; con varias pestañas abiertas son indistinguibles. Incluir alumno y nº de parte (ídem sanciones).
- **C2. Migas sin destino final.** El breadcrumb de detalle/nuevo termina en un chevron colgante; añadir el elemento actual («Parte #123») orienta y da título accesible.
- **C3. Bloques de flashes duplicados y muertos.** El layout ya consume `app.flashes` (`layouts/app.html.twig:266`), por lo que los bucles de flashes de `incident/index`, `incident/show`, `notification/index`, `register_report`, etc. nunca muestran nada. Eliminarlos evita divergencias futuras.
- **C4. Ayuda redundante en «Alumnado implicado».** `incident/new.html.twig:95-98` repite el mismo texto como placeholder y como ayuda inferior. Sustituir la ayuda por algo útil: «Se creará un parte por cada alumno seleccionado».
- **C5. Flash de creación sin enlace.** Los mensajes «Parte creado…» podrían enlazar al elemento creado (mitigado si se implementa A2).
- **C6. Autodescarte de flashes a los 4 s** (`layouts/app.html.twig:331`): para mensajes de error conviene no autodescartar; un error que desaparece solo puede pasar inadvertido.

---

## Lo que ya funciona bien (mantener)

- Selección de centro/curso con indicadores ámbar de «curso histórico» y retorno en un clic.
- Cola de notificaciones como concepto: convierte una obligación legal en una lista de tareas.
- Filtros en vivo del listado de partes con indicador de carga y estados vacíos diferenciados (sin datos vs. sin resultados).
- Formulario de comunicación: fecha precargada, resultado como tarjetas radio con ayuda contextual.
- Modo tablón para pantallas de sala de profesores, con rotación semanal automática.
- Confirmaciones de borrado centralizadas (`confirm` controller) y trabajo reciente de a11y (scope en tablas, live regions de flashes).

## Hoja de ruta sugerida

| Fase | Contenido | Esfuerzo aproximado |
|------|-----------|---------------------|
| 1 | A1, A2, B6, C3, C4, C5 (cambios acotados en plantillas/controladores) | Bajo |
| 2 | A4, A5, B1, B5 (reutilizan patrones existentes: trait de paginación, Live Components) | Medio |
| 3 | A3 (ficha del alumno) + B3 (paleta con acciones) — es la mejora estructural de mayor impacto | Alto |
| 4 | B2, B4, C1, C2, C6 | Bajo-medio |

Tras las fases 1-2 conviene actualizar el manual (`docs/manual/04-flujo-de-trabajo.md` y `05-secciones-de-la-aplicacion.md`) para que describa los nuevos atajos del flujo.
