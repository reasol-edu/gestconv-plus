# Primeros pasos

## 1. Crear o activar el curso académico (administrador)

## 2. Añadir los docentes del curso académico (equipo directivo)

### Cómo exportar el CSV de Séneca (perfil Dirección)

## 3. Estructurar la oferta formativa del curso académico (equipo directivo)

### Cómo registrarla, paso a paso

## 4. Asignar tutores y docentes a los grupos (equipo directivo)

### Cómo exportar el CSV de asignaciones de Séneca (perfil Dirección)

## 5. Dar de alta a los estudiantes (equipo directivo)

### Cómo exportar el CSV de Séneca (perfil Dirección)

### Formato del CSV de importación

El importador lee directamente el fichero CSV que genera Séneca sin necesidad de modificarlo.

**Columnas obligatorias** (el fichero debe contenerlas; si falta alguna, la importación se cancela):

| Columna Séneca | Dato importado |
|---|---|
| `Estado Matrícula` | Filtro: filas con valor no vacío se omiten |
| `Nº Id. Escolar` | NIE del alumno (identificador único) |
| `Nombre` | Nombre |
| `Primer apellido` | Primer apellido |
| `Segundo apellido` | Segundo apellido |
| `Unidad` | Grupo (por nombre exacto) |

**Columnas opcionales** (se importan si están presentes en el fichero; si no, ese campo se deja sin cambios en registros existentes):

| Columna(s) Séneca | Campo en la aplicación |
|---|---|
| `Nombre Primer tutor` + `Primer apellido Primer tutor` + `Segundo apellido Primer tutor` | Nombre completo del tutor/a 1 |
| `Correo Electrónico Primer tutor` | Correo electrónico del tutor/a 1 |
| `Teléfono Primer tutor` | Teléfono de contacto 1 |
| `Nombre Segundo tutor` + `Primer apellido Segundo tutor` + `Segundo apellido Segundo tutor` | Nombre completo del tutor/a 2 |
| `Correo Electrónico Segundo tutor` | Correo electrónico del tutor/a 2 |
| `Teléfono Segundo tutor` | Teléfono de contacto 2 |
| `Teléfono` | Teléfono de contacto 3 (teléfono del alumno) |
| `Observaciones de la matrícula` | Observaciones |

El nombre de los tutores se compone automáticamente en formato «Apellido1 Apellido2, Nombre».

Si el NIE ya existe en la base de datos, se actualizan el nombre completo y todos los campos opcionales que estén presentes en el CSV.
