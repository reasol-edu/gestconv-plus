# Preparar el curso académico

Este capítulo es para quienes administran el centro educativo — normalmente, el **equipo
directivo**. Explica cómo dejar listo un curso académico: profesorado, alumnado, grupos y
tutorías. Con los ficheros de Séneca a mano, todo el proceso lleva menos de media hora, y se
repite una vez al año, al abrir cada curso nuevo.

!!! note "¿El centro todavía no existe en la aplicación?"
    El alta del centro y de la primera cuenta de administración se hace una única vez y se explica
    en [Instalación y puesta en marcha](01-instalacion-y-puesta-en-marcha.md#configuracion-inicial-del-centro-educativo).

## 1. Crear y activar el curso académico

Desde **Centro educativo › Cursos académicos**, crea el curso nuevo (por ejemplo, `2026-2027`) y
márcalo como **activo**. Solo puede haber un curso activo por centro: es el curso de referencia
para las altas de estudiantes, la oferta formativa, los partes y las sanciones. Los cursos
anteriores no se pierden: quedan disponibles para consulta como histórico (ver
[Cambio de curso académico](03-el-trabajo-diario.md#cambio-de-curso-academico-administradores)).

Al crear el centro se configuraron automáticamente las conductas contrarias, las medidas
disciplinarias y los métodos de comunicación por defecto. Este es un buen momento para revisarlos
y adaptarlos al plan de convivencia del centro (ver
[Administrar el centro educativo](06-administrar-el-centro.md#catalogos-del-centro)).

## 2. Añadir el profesorado

La vía recomendada es la importación desde Séneca, disponible en
**Centro educativo › Docentes › Importar desde Séneca**:

- Se sube el fichero CSV y la aplicación crea los docentes que no existan (con autenticación
  externa vía IdEA) y añade al curso activo tanto los recién creados como los que ya existieran
  en el sistema, sin modificar los datos de estos últimos.
- Se aceptan ficheros en UTF-8 y en Windows-1252 (la codificación habitual de las exportaciones
  de Séneca).
- Se omiten las filas sin usuario IdEA o sin nombre en la columna «Empleado/a». Los docentes con
  fecha de cese también se importan.

También se puede añadir un docente de forma manual, uno a uno, desde el mismo apartado — útil
para altas puntuales durante el curso.

![Listado de docentes del centro tras la importación](img/centro/centro-docentes.png)

!!! info "Cómo exportar el fichero desde Séneca"
    Con el perfil de Dirección: **Personal › Personal del centro › Exportar datos** (formato
    CSV). El importador usa las columnas «Empleado/a» y «Usuario IdEA»; el resto se ignora.

## 3. Importar el alumnado (y la oferta formativa, de paso)

Aquí está el mayor ahorro de tiempo: al importar el alumnado desde
**Centro educativo › Estudiantes › Importar CSV**, la aplicación **crea automáticamente los
cursos y grupos** que aparecen en el fichero de Séneca. No hace falta preparar nada antes.

Se sube el fichero CSV y la aplicación muestra una **vista previa** con todo lo que va a hacer
—altas, actualizaciones y cursos o grupos nuevos— antes de confirmar nada. Los grupos y cursos
por crear se identifican claramente y se pueden desmarcar los que no se quieran crear en ese
momento. Igual que con el profesorado, se aceptan ficheros en UTF-8 y en Windows-1252.

![Listado de estudiantes del centro tras la importación](img/centro/centro-estudiantes.png)

!!! info "Cómo exportar el fichero desde Séneca"
    Con el perfil de Dirección: **Alumnado › Alumnado del centro › Exportar datos** (formato CSV).

### Qué se importa

El importador lee directamente el fichero que genera Séneca, sin necesidad de modificarlo.

**Columnas obligatorias** (si falta alguna, la importación se cancela):

| Columna Séneca | Dato importado |
|---|---|
| `Estado Matrícula` | Filtro: las filas con valor no vacío se omiten |
| `Nº Id. Escolar` | NIE del estudiante (identificador único) |
| `Nombre` | Nombre |
| `Primer apellido` | Primer apellido |
| `Segundo apellido` | Segundo apellido |
| `Unidad` | Nombre del grupo |
| `Curso` | Nombre del curso al que pertenece el grupo |

**Columnas opcionales** (se importan si están presentes; si no, ese dato se deja sin cambios en
los registros existentes):

| Columna(s) Séneca | Campo en la aplicación |
|---|---|
| `Nombre Primer tutor` + `Primer apellido Primer tutor` + `Segundo apellido Primer tutor` | Nombre completo del tutor/a 1 |
| `Correo Electrónico Primer tutor` | Correo electrónico del tutor/a 1 |
| `Teléfono Primer tutor` | Teléfono de contacto 1 |
| `Nombre Segundo tutor` + `Primer apellido Segundo tutor` + `Segundo apellido Segundo tutor` | Nombre completo del tutor/a 2 |
| `Correo Electrónico Segundo tutor` | Correo electrónico del tutor/a 2 |
| `Teléfono Segundo tutor` | Teléfono de contacto 2 |
| `Teléfono` | Teléfono de contacto 3 (teléfono del estudiante) |
| `Observaciones de la matrícula` | Observaciones |

El nombre de los tutores legales se compone automáticamente en formato
«Apellido1 Apellido2, Nombre». Si el NIE ya existe en la base de datos, se actualizan el nombre
completo y todos los campos opcionales presentes en el fichero.

### Un mismo grupo en varios cursos

Si el mismo nombre de grupo aparece en el fichero asociado a varios cursos distintos —el caso
típico son los grupos mixtos de Bachillerato—, la vista previa muestra una sección especial donde
se elige a qué curso asignar ese grupo, o se escribe un nombre de curso diferente. Por ejemplo: la
unidad «1º BACH-MX» aparece en «1º Bachillerato (Ciencias)» y en «1º Bachillerato (Humanidades y
Ciencias Sociales)»; se puede resolver escribiendo simplemente «1º Bachillerato».

![Vista previa de importación con un grupo de nombre ambiguo](img/centro/centro-importar-preview.png)

## 4. Asignar las tutorías de grupo

Con el alumnado ya importado, conviene indicar qué docentes ejercen la tutoría de cada grupo.
Esta asignación determina, entre otras cosas, qué docentes ven los partes y sanciones de su grupo
(ver [Permisos de un vistazo](08-permisos-de-un-vistazo.md)).

Se hace desde **Centro educativo › Oferta formativa**: al seleccionar un grupo, aparece debajo su
panel de detalle, donde se asignan los tutores/as.

## 5. Indicar quién imparte clase en cada grupo (opcional)

El último paso es opcional: registrar qué profesorado da clase en cada grupo, importando desde
**Centro educativo › Docentes › Importar asignaciones a grupos** el fichero de Séneca:

- Se usan las columnas «Unidad» (grupo) y «Profesor/a» (nombre en formato «Apellidos, Nombre»).
- El docente se busca por nombre y apellidos exactos, y el grupo por nombre exacto entre los del
  curso activo; los que no coincidan se listan como no encontrados y esa fila se omite.
- Es imprescindible haber importado antes el profesorado (paso 2), para que los nombres del
  fichero puedan encontrarse.

!!! note "¿Por qué conviene hacerlo?"
    La aplicación funciona sin esta asignación, pero los docentes que no estén asignados a ningún
    grupo no verán las sanciones de sus estudiantes en la sección Inicio.

!!! info "Cómo exportar el fichero desde Séneca"
    Con el perfil de Dirección: **Personal › Personal del centro › Materia y grupos ›
    Unidad: Cualquiera › Exportar datos** (formato CSV).

## La oferta formativa a mano

Si se prefiere no usar la importación (o hay que hacer retoques después), la oferta formativa
—el catálogo de cursos y grupos del año activo— se gestiona desde
**Centro educativo › Oferta formativa**, con un editor en dos columnas: la izquierda muestra los
cursos y, al seleccionar uno, la derecha muestra sus grupos. Al seleccionar un curso o un grupo
aparece su formulario de edición debajo. Los cambios se aplican al instante, sin recargar la
página.

1. Pulsa «Añadir» bajo la lista de cursos para crear uno nuevo (por ejemplo, «1º ESO»).
2. Selecciona el curso recién creado; la columna derecha muestra sus grupos (vacía al principio).
3. Pulsa «Añadir» en la sección de grupos para crear los grupos del curso (por ejemplo, 1ºESO-A,
   1ºESO-B).
4. Al seleccionar un grupo aparece su formulario de edición: aquí se asignan tutores/as y
   docentes.

!!! warning "Los nombres deben coincidir con Séneca"
    Si creas los grupos a mano, su nombre debe coincidir exactamente con la columna «Unidad» de
    Séneca; de lo contrario, la importación de estudiantes no podrá asignar cada estudiante a su
    grupo.

## Lista de comprobación de inicio de curso

1. Curso académico creado y marcado como **activo**.
2. Conductas, medidas y métodos de comunicación revisados y adaptados al plan de convivencia.
3. Profesorado importado desde Séneca.
4. Alumnado importado (la oferta formativa se crea sola).
5. Tutorías asignadas en todos los grupos.
6. Asignaciones docente-grupo importadas (opcional, pero recomendable).
7. Perfiles de **comisión de convivencia** y **orientación** asignados (ver
   [Administrar el centro educativo](06-administrar-el-centro.md#perfiles)).
