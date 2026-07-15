# Administrar la plataforma

Este capítulo es para quien mantiene el servidor donde está instalada la aplicación: la
**administración global**. Un mismo servidor puede alojar varios centros educativos con datos
completamente separados, y el administrador global es quien los crea y los mantiene. La
instalación inicial se explica en
[Instalación y puesta en marcha](01-instalacion-y-puesta-en-marcha.md).

## El panel de administración

La sección **Administración** del menú lateral, visible solo para los administradores globales,
reúne en tarjetas la gestión de toda la plataforma: centros educativos, docentes, registro de
actividad y ajustes globales.

### Centros educativos

La tarjeta **Centros educativos** lista los centros alojados en el servidor y permite crearlos,
editarlos y eliminarlos.

- **Crear un centro** solo requiere tres datos: código, nombre y localidad. Al crearlo, la
  aplicación genera automáticamente su primer curso académico (con el año actual, ya marcado como
  activo) y sus catálogos por defecto de conductas, medidas y métodos de comunicación (ver
  [Catálogos del centro](06-administrar-el-centro.md#catalogos-del-centro)).
- **Editar un centro** permite, además de corregir sus datos, designar qué docentes son sus
  **administradores de centro**.
- **Eliminar un centro** borra en cascada todos sus datos: cursos, estudiantes, partes, sanciones
  y comunicaciones. No se puede deshacer.

### Docentes

La tarjeta **Docentes** permite crear y editar cualquier cuenta de docente del servidor, con
independencia del centro al que pertenezca.

![Formulario de alta de un docente](img/admin/admin-docente-formulario.png)

El formulario de alta y edición incluye, además del nombre, apellidos, usuario y correo
electrónico, tres opciones:

- **Modo de acceso** — contraseña propia o autenticación externa vía usuario IdEA (Séneca). Con
  autenticación externa, la aplicación no gestiona ninguna contraseña para esa cuenta.
- **Administrador global** — concede acceso total a todos los centros, todos los cursos y toda la
  configuración del sistema.
- **Cuenta activa** — una cuenta inactiva no puede iniciar sesión.

Por seguridad, un administrador no puede quitarse a sí mismo el rol de administrador global ni
desactivar su propia cuenta desde este formulario.

#### Forzar cambio de contraseña

Al crear o editar un docente con acceso por contraseña, una opción adicional permite **forzar el
cambio de contraseña** en el próximo acceso: la próxima vez que ese docente inicie sesión queda
confinado a una pantalla dedicada de cambio de contraseña hasta que la cambie (o cierre sesión).
Es la vía recomendada para entregar una contraseña provisional a un docente nuevo o para forzar
la renovación de una contraseña que se sospeche comprometida.

### Registro de actividad

El registro de actividad recoge las acciones relevantes realizadas en la plataforma. Solo es
visible para los administradores globales.

![Registro de actividad con el listado de acciones](img/admin/admin-registro-actividad.png)

- **Qué se registra** — inicios y cierres de sesión (incluidos los intentos fallidos), cualquier
  operación de escritura y las importaciones y exportaciones de datos.
- **Filtros** — por usuario, centro educativo, curso académico, tipo de acción y rango de fechas,
  con ordenación por fecha ascendente o descendente.
- **Retención** — las entradas anteriores al número de días del ajuste **Retención de los
  registros** (ver [Notificaciones a familias](#notificaciones-a-familias), 90 días por defecto)
  se purgan automáticamente cada semana mediante una tarea programada.

## Correo electrónico del servidor

!!! note "No confundir con la sección «Notificaciones» de la aplicación"
    Esta sección trata de los **correos electrónicos automáticos** que envía la aplicación
    (verificación de dirección, restablecimiento de contraseña, avisos al profesorado). El
    registro de las **comunicaciones con las familias** sobre partes y sanciones se hace desde la
    sección Notificaciones del menú lateral y se explica en
    [El trabajo diario del profesorado](03-el-trabajo-diario.md#notificaciones).

### Activar el correo

El envío de correo está **desactivado por defecto** (`MAILER_DSN=null://null`: los mensajes se
descartan). Para activarlo, define en el entorno (o en `.env.local`):

```bash
# Servidor SMTP propio o de tu proveedor
MAILER_DSN=smtp://usuario:clave@servidor:587
# Dirección remitente de los correos automáticos
MAILER_FROM=no-responder@tucentro.example.org
```

Configura también `DEFAULT_URI` con la URL pública de la aplicación: es la que se usa para
construir los enlaces incluidos en los correos.

Con el correo activo, la aplicación envía dos tipos de mensaje que no se pueden desactivar:

- **Verificación de email** — cuando un docente añade o cambia su dirección en su perfil, recibe
  un enlace para confirmarla.
- **Restablecimiento de contraseña** — enlace de un solo uso con caducidad de 1 hora, solicitado
  desde la pantalla de acceso.

Además, envía los [avisos de partes y
sanciones](06-administrar-el-centro.md#avisos-de-partes-y-sanciones), que sí son configurables.

### Usar una cuenta de Gmail

Para pruebas o centros pequeños puede usarse una cuenta de Gmail con una
[contraseña de aplicación](https://support.google.com/accounts/answer/185833):

```bash
MAILER_DSN=gmail://USUARIO:CONTRASEÑA_DE_APLICACION@default
```

Gmail impone límites de envío diarios y puede bloquear la cuenta ante usos intensivos, así que
**no es recomendable en producción**: usa el SMTP institucional o un proveedor transaccional.

### Envío asíncrono

Los correos no se envían durante la petición web: se encolan y los procesa un *worker* en segundo
plano (el contenedor `worker` en Docker, o el proceso que lanzan los scripts de arranque del
binario nativo). El correo de restablecimiento de contraseña es la excepción: se envía al momento
por la urgencia del token. Los detalles de la cola, los reintentos y los comandos de diagnóstico
están en [Correos en cola (Messenger)](#correos-en-cola-messenger).

## Sistema de ajustes

La pantalla de **Ajustes** existe en tres sitios, uno por ámbito: la tarjeta **Ajustes globales**
del panel de administración (todo el servidor), la tarjeta **Ajustes** del panel de
[Centro educativo](06-administrar-el-centro.md#ajustes-del-centro) (un centro concreto) y el
apartado **Ajustes** del perfil de cada docente (valores personales).

Cuando un ajuste tiene valor en varios niveles, se resuelve en este orden de prioridad:

**docente > centro > global > valor por defecto**

### Bloqueo de ajustes

Un administrador global o de centro puede **bloquear** el valor de un ajuste con el icono del
candado, junto al campo. El bloqueo invierte el orden de prioridad habitual para forzar un valor
concreto en todos los niveles inferiores:

![Pantalla de ajustes con candados junto a los campos](img/ajustes/ajustes-candados.png)

- Un ajuste **global bloqueado** se aplica siempre, sin excepción: ignora cualquier valor de
  centro o de docente.
- Un ajuste **de centro bloqueado** se aplica a todos los docentes de ese centro, ignorando
  cualquier valor personal — pero sigue por debajo de un bloqueo global, si lo hay.

Solo se puede bloquear un ajuste que ya tiene un valor guardado explícitamente en ese nivel (no
el valor por defecto): el candado aparece deshabilitado hasta que se guarda un valor. Desbloquear
no borra el valor guardado; simplemente deja de forzarlo sobre los niveles inferiores.

## Ajustes disponibles

La pantalla de ajustes agrupa cada uno en una de estas categorías, en este mismo orden:
Visualización, Correo electrónico, Modo tablón, Notificaciones a familias, Avisos por correo y
Personalización de informes.

### Visualización

| Ajuste | Ámbito | Tipo | Rango | Por defecto |
|---|---|---|---|---|
| Resultados por página | Docente | Entero | 5-100 | 20 |

Número de elementos que se muestran en los listados paginados (partes, sanciones, estudiantes,
etc.). Es un ajuste exclusivamente personal: cada docente configura el suyo desde su propio
perfil, y no admite valor global ni de centro.

### Correo electrónico

| Ajuste | Ámbito | Tipo | Por defecto |
|---|---|---|---|
| Activar notificaciones automáticas | Global, centro, docente | Booleano (sí/no) | Activado |

Activa o desactiva todos los correos automáticos de avisos de la aplicación (ver
[Avisos de partes y sanciones](06-administrar-el-centro.md#avisos-de-partes-y-sanciones)). Es el
único ajuste con los tres ámbitos disponibles: un administrador global puede desactivarlos para
todo el servidor, uno de centro para su centro, y cada docente para sí mismo.

### Modo tablón

Tres ajustes de ámbito global o de centro controlan la alternancia de semanas y el tema de
colores del modo tablón. Se describen en
[Ajustes del modo tablón](05-calendario-y-tablon.md#ajustes-del-modo-tablon).

### Notificaciones a familias

| Ajuste | Ámbito | Tipo | Rango | Por defecto |
|---|---|---|---|---|
| Quién notifica los partes de convivencia | Global, centro | Opciones (El docente del parte / El tutor/a de grupo / Ambos) | — | Ambos |
| Quién notifica las sanciones | Global, centro | Opciones (El docente de la sanción / El tutor/a de grupo / Ambos) | — | Ambos |
| Días para la prescripción automática | Global, centro | Entero (días) | 0-365 | 14 |
| Aviso de prescripción próxima | Global, centro, docente | Entero (días) | 0-365 | 7 |
| Registrar los avisos por correo | Global, centro | Booleano | — | Activado |
| Retención de los registros | Global | Entero (días) | 0-3650 | 90 |

Los dos primeros determinan, además de los administradores, qué docentes pueden registrar una
comunicación con la familia (ver
[Notificaciones](03-el-trabajo-diario.md#notificaciones)).

El tercero y el cuarto controlan la
[prescripción automática](06-administrar-el-centro.md#prescripcion-automatica-de-partes-sin-notificar)
y el
[aviso de prescripción próxima](06-administrar-el-centro.md#aviso-de-prescripcion-proxima); en
ambos, un valor de 0 desactiva la tarea para ese nivel. El aviso de prescripción próxima es el
único de los cuatro que admite también un valor personal de docente, que prevalece sobre el de su
centro.

El quinto activa o desactiva el
[registro de avisos por correo](06-administrar-el-centro.md#registro-de-avisos-por-correo): si
está desactivado, los avisos se siguen enviando con normalidad, pero no queda constancia de
ellos.

El sexto controla cuántos días se conservan las entradas del
[registro de actividad](#registro-de-actividad) y del
[registro de avisos por correo](06-administrar-el-centro.md#registro-de-avisos-por-correo) antes
de que una tarea programada semanal (domingos a las 3:00) las elimine. Es el único de los seis
con ámbito exclusivamente global, ya que también gobierna la retención del registro de actividad,
que no está asociado a ningún centro. Un valor de 0 desactiva esta eliminación automática.

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
| Enviar parte adjunto al correo | Sí / No | No |
| Enviar sanción adjunta al correo | Sí / No | No |

Uno por cada evento de un parte o una sanción; determinan si se envía un correo y a quién. El
aviso de parte modificado no se dispara al marcar un parte como prescrito, que tiene su propio
ajuste independiente, y el aviso a la comisión de convivencia solo se envía cuando un parte queda
notificado a la familia y todavía puede ser sancionado. Los dos últimos adjuntan el PDF del parte
o de la sanción a los correos anteriores. Ninguno tiene ámbito de docente: se fijan a nivel
global o de centro, y por defecto no se envía ningún correo. El detalle de cada aviso está en
[Avisos de partes y sanciones](06-administrar-el-centro.md#avisos-de-partes-y-sanciones).

### Personalización de informes

| Ajuste | Ámbito | Tipo | Rango | Por defecto |
|---|---|---|---|---|
| Encabezado izquierdo (informe de parte) | Global, centro | Texto enriquecido | 0-5000 caracteres | `{title}` en negrita |
| Encabezado derecho (informe de parte) | Global, centro | Texto enriquecido | 0-5000 caracteres | `{centre_name}` |
| Margen superior (informe de parte) | Global, centro | Entero (mm) | 10-80 | 22 |
| Pie de contenido (informe de parte) | Global, centro | Texto enriquecido | 0-5000 caracteres | `En {city} a {current_day} de {current_month_name} de {current_year}` |
| Encabezado izquierdo (informe de sanción) | Global, centro | Texto enriquecido | 0-5000 caracteres | `{title}` en negrita |
| Encabezado derecho (informe de sanción) | Global, centro | Texto enriquecido | 0-5000 caracteres | `{centre_name}` |
| Margen superior (informe de sanción) | Global, centro | Entero (mm) | 10-80 | 22 |
| Pie de contenido (informe de sanción) | Global, centro | Texto enriquecido | 0-5000 caracteres | `En {city} a {current_day} de {current_month_name} de {current_year}` |
| Encabezado izquierdo (estadísticas por grupo) | Global, centro | Texto enriquecido | 0-5000 caracteres | `{title}` en negrita |
| Encabezado derecho (estadísticas por grupo) | Global, centro | Texto enriquecido | 0-5000 caracteres | `{centre_name}` |
| Margen superior (estadísticas por grupo) | Global, centro | Entero (mm) | 10-80 | 22 |
| Marca de agua de borrador | Global, centro | Booleano | — | Desactivada |

Los ajustes de **encabezado** controlan el contenido que se repite en cada página de los PDF de
partes, sanciones y del informe de estadísticas por grupo (ver
[Informes](06-administrar-el-centro.md#informes)). Cada tipo de informe tiene dos zonas
—izquierda y derecha, en la misma línea— que se editan con un editor de texto enriquecido
(negrita, cursiva, subrayado, títulos, listas, citas y enlaces). El margen superior determina, en
milímetros, a qué altura empieza el cuerpo del documento en cada página: conviene aumentarlo si
el encabezado personalizado ocupa más líneas que el original.

Los ajustes de **pie de contenido** (uno para partes y otro para sanciones, sin equivalente en
estadísticas por grupo) añaden un bloque de texto enriquecido al final del documento, una sola
vez. Por defecto muestran la fecha y localidad de generación del PDF (por ejemplo, «En Sevilla a
10 de julio de 2026»); se pueden sustituir por cualquier otro texto o vaciar por completo.

La **marca de agua de borrador**, cuando está activada, superpone el texto «BORRADOR» en diagonal
sobre el PDF de un parte o una sanción mientras no se haya notificado a la familia; desaparece en
cuanto se registra la notificación.

![Ajustes de personalización de informes](img/ajustes/ajustes-informes.png)

Dentro del texto se pueden escribir **marcadores** que se sustituyen por los datos reales al
generar el PDF. Un marcador desconocido (por ejemplo, con una errata) no rompe el informe: se
muestra tal cual en el PDF, lo que facilita detectar el error. Esta misma tabla está disponible
sin salir de la pantalla de ajustes, en el popover del icono ⓘ que acompaña a cada campo de
texto enriquecido de la categoría.

| Marcador | Se sustituye por | Disponible en |
|---|---|---|
| `{title}` | Título genérico del informe («Informe de parte de convivencia» / «Informe de sanción de convivencia» / «Estadísticas por grupo») | Todos |
| `{report_nr}` | Número del parte | Solo partes |
| `{student_name}` | Nombre completo del estudiante | Partes y sanciones |
| `{group_name}` | Nombre del grupo | Partes y sanciones |
| `{centre_name}` | Nombre del centro educativo | Todos |
| `{academic_year}` | Nombre del curso académico | Todos |
| `{date_from}` / `{date_to}` | Fechas de inicio y fin del rango consultado | Solo estadísticas por grupo |
| `{city}` | Ciudad del centro educativo | Todos |
| `{current_date}` | Fecha de generación del PDF (dd/mm/aaaa) | Todos |
| `{current_time}` | Hora de generación del PDF (hh:mm) | Todos |
| `{current_day}` | Día del mes de generación del PDF, sin ceros a la izquierda | Todos |
| `{current_month_name}` | Nombre del mes de generación del PDF, en minúscula (p. ej. «julio») | Todos |
| `{current_year}` | Año de generación del PDF | Todos |

## Copias de seguridad

Lo único imprescindible de respaldar es la **base de datos**: contiene todos los partes,
sanciones, comunicaciones, estudiantes y ajustes. La carpeta `data/var` (caché, logs y sesiones)
se regenera sola y no necesita copia.

### Despliegue con Docker (PostgreSQL)

Genera un volcado consistente sin parar la aplicación:

```bash
docker compose exec database pg_dump -U gestconv -Fc gestconv > gestconv-$(date +%F).dump
```

Para restaurarlo sobre una base de datos vacía:

```bash
docker compose exec -T database pg_restore -U gestconv -d gestconv --clean --if-exists < gestconv-2026-07-02.dump
```

### Binario nativo (SQLite)

La base de datos es el fichero `data/gestconv-plus.db`. Puedes copiarlo en caliente de forma
segura con:

```bash
sqlite3 data/gestconv-plus.db ".backup 'gestconv-$(date +%F).db'"
```

o simplemente copiar el fichero con la aplicación parada.

### Recomendaciones

- Automatiza la copia con una tarea programada (cron) **diaria** y conserva varias generaciones
  (por ejemplo, 7 diarias y 4 semanales).
- Guarda las copias en una máquina distinta de la del servidor.
- Al contener datos personales de menores, **cifra las copias** o almacénalas en un soporte
  cifrado.
- Prueba la restauración de vez en cuando: una copia que nunca se ha restaurado no es una copia.
- Haz siempre una copia manual **antes de actualizar** la aplicación.

## Correos en cola (Messenger)

Los correos automáticos se **encolan en la base de datos** y los procesa un *worker* en segundo
plano:

- **Docker** — el servicio `worker` de `compose.yaml` los procesa automáticamente.
- **Binario nativo** — los scripts de arranque (`start.sh`, `start.bat`, `start.ps1` y el
  servicio de `install-ubuntu.sh`) lanzan el worker junto con la aplicación.

Si un envío falla, se reintenta hasta 3 veces con esperas crecientes; agotados los reintentos,
pasa a la cola de fallidos. Comandos útiles:

```bash
php bin/console messenger:stats          # mensajes pendientes por cola
php bin/console messenger:failed:show    # ver los envíos fallidos
php bin/console messenger:failed:retry   # reintentarlos
```

(En el binario nativo, usa `./frankenphp php-cli bin/console …` en lugar de `php bin/console …`.)

## Actualización

1. Haz una **copia de seguridad** de la base de datos (ver arriba).
2. Actualiza según el tipo de despliegue:
    - **Docker** — `docker compose pull && docker compose up -d`.
    - **Binario nativo** — sigue los pasos de
      [Actualizar a una nueva versión](01-instalacion-y-puesta-en-marcha.md#actualizar-a-una-nueva-version).
3. Las **migraciones de base de datos se aplican automáticamente** al arrancar la nueva versión;
   no hay que ejecutar nada a mano.
4. Comprueba que la aplicación arranca y revisa el
   [CHANGELOG](https://github.com/reasol-edu/gestconv-plus/blob/main/CHANGELOG.md) por si alguna
   novedad requiere ajustar la configuración.

## Protección de datos (RGPD)

La aplicación trata datos personales de menores (identidad, grupo, incidencias de convivencia y
comunicaciones con las familias), por lo que el centro educativo —o la entidad que la aloje—
actúa como **responsable del tratamiento** y debe desplegarla conforme a su política de
protección de datos.

Medidas que la aplicación aporta de serie:

- **Acceso restringido** — solo el profesorado registrado puede entrar, y cada perfil ve
  únicamente lo que le corresponde (ver
  [Permisos de un vistazo](08-permisos-de-un-vistazo.md)). Los datos de cada centro están
  separados.
- **Registro de actividad** — con `APP_LOG=true` (valor por defecto) queda traza de las acciones
  de los usuarios. Para las acciones de negocio principales (altas, modificaciones y bajas de
  partes, sanciones, comunicaciones, observaciones, estudiantes, docentes, catálogos y estructura
  académica), la entrada registra qué se creó, modificó o eliminó y el valor antes/después de
  cada campo cambiado; solo se registran los campos relevantes para la auditoría (nunca, por
  ejemplo, la contraseña de un docente). Las entradas se purgan automáticamente según el ajuste
  **Retención de los registros** (ver
  [Notificaciones a familias](#notificaciones-a-familias)).
- **Contraseñas** almacenadas con algoritmos de *hashing* modernos, y verificación TLS activada
  por defecto en la autenticación externa contra iSéneca.

Responsabilidades del centro al desplegar:

- Servir la aplicación **siempre sobre HTTPS** (ver
  [Instalación y puesta en marcha](01-instalacion-y-puesta-en-marcha.md#https-con-lets-encrypt)).
- Limitar el número de administradores y revisar el registro de actividad periódicamente.
- Cifrar o custodiar adecuadamente las copias de seguridad y definir su plazo de conservación.
- Atender los derechos de acceso, rectificación y supresión: los datos de un estudiante pueden
  consultarse y corregirse desde su ficha, y la eliminación de un estudiante, curso o centro
  borra en cascada sus datos asociados.
