# Roles y permisos

## Acceso a la plataforma

Solo los docentes registrados en el sistema pueden acceder. El acceso se realiza con usuario y contraseña propios o mediante autenticación externa (iSéneca).

## Los perfiles

### Administrador global

Acceso total a todos los centros, todos los cursos y toda la configuración del sistema. Es el único que puede ver el registro de actividad y gestionar la configuración global.

### Administrador de centro

Gestiona un centro educativo concreto: docentes, oferta formativa, estudiantes, conductas y partes de convivencia. Puede ver y editar todos los partes de su centro.

### Comisión de convivencia

Perfil especial que se asigna a docentes concretos de un centro desde la card **Perfiles** del hub de centro educativo. Pueden ver todos los partes de convivencia del centro y registrar sanciones para cualquier estudiante, con los mismos permisos que un administrador de centro sobre partes y sanciones (pero sin acceso al resto de secciones del hub, como oferta formativa o estudiantes).

### Orientador/a

Perfil especial, asignado igual que el de comisión de convivencia. Puede ver todos los partes de convivencia y todas las sanciones del centro, pero no puede crear, editar ni eliminar los que no ha registrado él mismo.

### Tutor/a de grupo / Docente de grupo

Docente asignado a un grupo como tutor/a. Puede ver todos los partes de convivencia de ese grupo, además de los suyos propios de cualquier grupo.

### Docente (sin rol específico)

Accede a la sección de partes y puede registrar nuevos partes. Solo ve los partes que él mismo ha registrado.

## Tabla de permisos

### Partes de convivencia

| Acción | Docente | Tutor del grupo | Comisión | Orientador/a | Admin de centro | Admin global |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Ver la sección Partes | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Registrar un nuevo parte | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver sus propios partes | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver todos los partes del grupo | — | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver todos los partes del centro | — | — | ✓ | ✓ | ✓ | ✓ |
| Editar sus propios partes | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Editar partes ajenos | — | — | — | — | ✓ | ✓ |
| Eliminar partes | — | — | — | — | ✓ | ✓ |

La comisión de convivencia y el orientador/a solo tienen esta visibilidad ampliada sobre partes que no son suyos; no pueden editarlos ni eliminarlos.

### Sanciones

| Acción | Docente | Tutor del grupo | Orientador/a | Comisión | Admin de centro | Admin global |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Ver sanciones de partes propios | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver sanciones del grupo tutorizado | — | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ver todas las sanciones del centro | — | — | ✓ | ✓ | ✓ | ✓ |
| Registrar una nueva sanción | — | — | — | ✓ | ✓ | ✓ |
| Editar o eliminar sanciones | — | — | — | ✓ | ✓ | ✓ |

Solo la comisión de convivencia tiene los mismos permisos que un administrador de centro para registrar, editar y eliminar sanciones de cualquier estudiante. El orientador/a solo puede verlas.

### Notificaciones a las familias

Registrar una comunicación con la familia (ver [Notificaciones](05-secciones-de-la-aplicacion.md#notificaciones)) está controlado por dos ajustes de centro — *Quién notifica los partes* y *Quién notifica las sanciones* — que admiten tres valores: **el docente que registró** el parte o la sanción, **el tutor/a del grupo**, o **ambos** (valor por defecto). Consulta [Ajustes](07-ajustes.md#notificaciones).

| Acción | Docente autor | Tutor del grupo | Comisión | Orientador/a | Admin de centro | Admin global |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Ver la cola de Notificaciones | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Notificar un parte | según ajuste | según ajuste | — | — | ✓ | ✓ |
| Notificar una sanción | según ajuste | según ajuste | ✓ | — | ✓ | ✓ |

La cola de pendientes muestra a cada docente los elementos que puede ver según las reglas de visibilidad de partes y sanciones; el botón **Notificar** solo aparece en los que además puede notificar.

### Centro educativo

| Acción | Docente | Admin de centro | Admin global |
|---|:---:|:---:|:---:|
| Ver el hub del centro | — | ✓ | ✓ |
| Gestionar docentes del curso | — | ✓ | ✓ |
| Gestionar oferta formativa | — | ✓ | ✓ |
| Gestionar estudiantes | — | ✓ | ✓ |
| Gestionar conductas contrarias | — | ✓ | ✓ |
| Gestionar perfiles (comisión y orientador/a) | — | ✓ | ✓ |

### Administración global

| Acción | Admin global |
|---|:---:|
| Gestionar centros educativos | ✓ |
| Gestionar docentes | ✓ |
| Ver registro de actividad | ✓ |
| Configuración global | ✓ |

### Otras acciones y permisos generales

- Cualquier docente puede ver y editar su propio perfil (nombre, contraseña, correo electrónico).
- La sección de **Inicio** y **Calendario** son accesibles para todos los docentes autenticados con un centro seleccionado.
