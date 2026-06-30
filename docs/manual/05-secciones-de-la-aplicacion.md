# Secciones de la aplicación

## Inicio

El panel de inicio muestra un resumen de la actividad del curso activo para el docente autenticado:

- **Estudiantes matriculados** en el curso activo (solo los visibles según el rol del docente).
- **Partes de convivencia recientes** (últimos 30 días) accesibles para ese docente.
- **Acceso rápido** a las acciones más frecuentes: importar estudiantes y registrar un nuevo parte.

### Cambio de curso académico (administradores)

Los administradores de centro y los administradores globales pueden consultar cursos académicos anteriores sin modificar el curso activo. El selector de curso aparece en la cabecera del menú lateral. Al visualizar un curso histórico, la aplicación muestra un aviso en ámbar y bloquea las operaciones de escritura.

## Calendario

## Centro Educativo

### Estructurar la oferta formativa

### Exportar e importar la oferta formativa

## Partes de convivencia

La sección **Partes** está accesible desde el menú lateral para cualquier docente con un centro seleccionado. Permite registrar y consultar los partes de convivencia del curso activo.

### Quién puede ver cada parte

| Perfil | Qué puede ver |
|---|---|
| Docente (sin rol especial) | Solo los partes que él mismo ha registrado |
| Tutor/a de grupo | Sus propios partes y todos los del grupo que tutoriza |
| Administrador de centro | Todos los partes del centro |
| Administrador global | Todos los partes de todos los centros |

### Registrar un nuevo parte

![Formulario de nuevo parte](img/partes/nuevo-parte-vacio.png)

1. Pulsa **Nuevo parte** en la esquina superior derecha de la sección o desde el acceso rápido del inicio.
2. **Alumnado implicado** — escribe en el campo de búsqueda el nombre o NIE del estudiante. El desplegable muestra el nombre del alumno con el grupo como información secundaria. Si en el incidente participaron varios estudiantes (incluso de grupos distintos), selecciónalos todos: se creará un parte independiente para cada uno con los mismos datos.

   ![Selector de alumnado con una pill seleccionada](img/partes/nuevo-parte-selector-alumnado.png)

3. **Fecha y hora del suceso** — por defecto se rellena con el momento actual; modifícala si el parte se registra con posterioridad al incidente.
4. **Conductas** — marca al menos una conducta de las definidas para el centro. Las conductas están agrupadas en *Contrarias a la convivencia* y *Conductas graves*. Solo aparecen las conductas activas.
5. **Descripción de lo acontecido** — campo de texto enriquecido obligatorio. Describe los hechos con detalle.
6. **Expulsión del aula** — activa el interruptor si el alumno fue expulsado. Aparecerán entonces dos campos adicionales:
   - *Tareas encargadas durante la expulsión*
   - *¿Realizó las tareas?* (opciones: No se sabe / Sí / No)
7. Pulsa **Guardar parte**.

> Cada parte queda vinculado al docente que lo registra. La fecha y hora de creación se registran automáticamente.

### Listado y filtros

![Listado de partes de convivencia](img/partes/partes-listado.png)

El listado muestra los partes accesibles según el perfil del docente, ordenados del más reciente al más antiguo, con paginación. La primera columna (`#`) indica el número de parte dentro del curso académico activo. Los filtros disponibles son:

| Filtro | Descripción |
|---|---|
| Búsqueda libre | Busca por nombre del estudiante, nombre del docente, conducta o contenido de la descripción |
| Solo mis partes | Alterna entre ver solo los propios o todos los accesibles |
| Gravedad | Muestra solo partes con conductas graves, solo contrarias, o todos |
| Expulsión | Muestra solo partes con expulsión del aula |
| Rango de fechas | Filtra por fecha del suceso (desde / hasta) |

En pantallas pequeñas, cada fila del listado se muestra como una tarjeta con las etiquetas de campo visibles.

### Ver y editar un parte

Pulsa **Ver** en cualquier fila del listado para abrir el detalle completo del parte.

Desde el detalle puedes **editar** el parte si eres el docente que lo registró o un administrador. El número de parte (`#1`, `#2`…) aparece junto al título y es de solo lectura: identifica el parte dentro del curso académico y no cambia al editar.

![Formulario de edición con número de parte](img/partes/parte-editar.png)

Los campos editables son los mismos que en la creación, salvo el alumnado implicado y el grupo (que son inmutables una vez registrado).

Un administrador puede **eliminar** el parte definitivamente desde el detalle. Esta acción es irreversible.

### Conductas contrarias a la convivencia

Los administradores de centro configuran las conductas disponibles en **Centro educativo → Conductas contrarias**.

![Administración de conductas contrarias](img/partes/admin-conductas.png)

Para cada conducta pueden:

- Activarla o desactivarla (solo las activas se pueden seleccionar al registrar un parte).
- Marcarla como *grave* o *contraria*.
- Cambiar su orden mediante las flechas.
- Editar la descripción o eliminarla.

Al crear un centro nuevo se configuran automáticamente **19 conductas por defecto** basadas en la normativa de convivencia escolar de Andalucía, ordenadas de contrarias a graves.

## Administración

### Registro de actividad

El registro de actividad recoge las acciones relevantes realizadas en la plataforma: accesos, modificaciones de datos, importaciones y exportaciones. Solo es visible para los administradores globales.

#### Qué se registra

- Inicio y cierre de sesión, intentos fallidos de autenticación.
- Operaciones de escritura sobre cualquier sección (POST, PUT, PATCH, DELETE).
- Exportaciones e importaciones de datos.

#### Filtros disponibles

El listado de actividad admite filtrado por usuario, centro educativo, curso académico, tipo de acción y rango de fechas. También permite ordenar por fecha de forma ascendente o descendente.

#### Retención y privacidad

Los registros de actividad se pueden purgar manualmente mediante el comando de consola `app:purge-activity-log`. Consulta el capítulo [Comandos de consola](08-comandos-de-consola.md) para más detalles.
