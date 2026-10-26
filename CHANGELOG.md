# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.15.0] - 2026-10-26

### Changed

- **Actividades en su propia sección**: las actividades tienen ahora su entrada «Actividades» en el menú lateral, bajo «Calendario», en lugar de una pestaña dentro del calendario. Quien puede publicarlas las encuentra en la nueva dirección `/actividades`; con el interruptor de Actividades desactivado, la entrada no aparece.

### Fixed

- Las etiquetas de los grupos seleccionados en el formulario de una actividad se mostraban mal encuadradas; ahora se ven como las de los demás selectores múltiples.

## [1.14.0] - 2026-10-26

### Added

- **Ausencias con docente sustituto/a**: una ausencia puede marcarse como «existe docente sustituto/a». Entonces no aparece en el calendario, no pide actividades y no sale en el panel de inicio ni en el tablón; solo se ve en el listado de ausencias de quien tiene permiso para consultarlas. Tampoco figura de guardia en un tramo quien tiene ese día una ausencia con sustituto/a.
- **Sustituciones de profesorado**: al añadir un docente a mano o importar un fichero (en la vista previa) se puede indicar, con un buscador, a quién sustituye; el nuevo docente hereda sus perfiles, tutorías, materias y guardias. El listado de docentes del centro tiene además la acción «Sustituir», que copia toda esa información de otro docente del curso a uno ya incluido.

## [1.13.0] - 2026-10-26

### Added

- **Actividades de centro** (los antiguos «eventos»): cada actividad tiene ahora un **tipo** obligatorio de un catálogo editable por centro (*Complementaria*, *Extraescolar* y *Otros* por defecto, cada uno con su color), y datos opcionales: lugar, departamento organizador, coordinador/a y profesorado acompañante. El listado se puede filtrar por tipo y los distintivos de tipo se ven en el calendario, la vista de día, las guardias, el panel de inicio y el tablón. Las actividades que ya existían pasan a ser *Complementaria*.
- **Interruptor de Actividades**: un ajuste global y de centro («Actividades de centro», en Secciones de la aplicación) las activa o desactiva de golpe, con el catálogo de tipos, el perfil de coordinación y sus direcciones. Viene activado.
- **Perfil «Coordinación de actividades»** (Centro educativo › Perfiles): gestiona todas las actividades del centro como un administrador.
- **Aula en las ausencias**: las tareas que un docente deja al ausentarse admiten un aula opcional, que se muestra en el tablón.
- **Ajuste de antelación en el tablón** («Días de antelación de las actividades en el tablón», 15 por defecto).

### Changed

- **Modo tablón rediseñado en paneles**: la pantalla «Hoy» reparte al 50 % el alumnado sancionado y el profesorado ausente por tramos horarios (quien no dejó tareas aparece en todos los tramos) y lista debajo las actividades de hoy y de los próximos días. «Esta semana» y «Semana que viene» se sustituyen por **«Próximos días»**: las 5 jornadas lectivas siguientes al día actual. La duración de «Esta semana» se conserva como la de «Próximos días» y el ajuste de «Semana que viene» desaparece.
- **Quién gestiona las actividades**: con el ajuste «Todos», un docente solo lista, modifica y elimina las que ha publicado él (antes gestionaba todas las que veía), y siempre ve las suyas en su calendario aunque las restrinja a grupos en los que no imparte. Las anteriores a esta versión no tienen autor: solo las gestionan la dirección y la coordinación.
- **Direcciones**: `/eventos` pasa a `/actividades` (las antiguas redirigen) y el ajuste «Quién puede publicar eventos» pasa a llamarse «…actividades».
- **Color de grupo**: el color personalizado se activa con la casilla «Color personalizado», que muestra el selector partiendo del color calculado; desmarcarla vuelve al calculado (ya no hay botón de reinicio).

### Fixed

- Editar una actividad ya no pierde a la coordinación ni al profesorado acompañante que dejó de pertenecer al curso.
- El enlace «Editar» de la vista de día solo se ofrece a quien puede modificar esa actividad.

## [1.12.1] - 2026-10-26

### Changed

- **Compilación de los ejecutables**: se desactiva de momento la comprobación de las sumas SHA-256 de los binarios de FrankenPHP, porque upstream vuelve a subir los de una versión ya publicada y la build fallaba sin que hubiera un problema en el repositorio.

## [1.12.0] - 2026-10-26

### Added

- **Secciones desactivables**: Salidas, Ausencias, Notas y tareas de sanción pueden desactivarse para toda la plataforma o para un centro (ajustes globales y de centro, categoría «Secciones de la aplicación»; vienen activadas). Una sección desactivada desaparece del menú, de los accesos rápidos, de la ficha del alumno, del calendario y de las alertas, y sus direcciones —también los catálogos de motivos de salida y tipos de nota— dejan de funcionar. Con las tareas de sanción desactivadas, las sanciones nuevas no piden tareas, no hay recordatorios y el enlace público para el alumno deja de abrirse. Los datos ya registrados se conservan.
- **Quién puede publicar eventos**: un ajuste global y de centro (categoría «Eventos») para elegir entre «Equipo directivo» (por defecto, incluye a los administradores) y «Todos». Con «Todos», cualquier docente del centro puede crear, editar y eliminar los eventos que ve.
- **Color de grupo**: cada grupo puede tener un color personalizado en la oferta formativa. Si no lo tiene, usa uno calculado automáticamente, que se muestra junto a un botón para quitar el personalizado y volver a él. El color se aplica al calendario, el panel de inicio, las guardias, el modo tablón y los listados de partes, sanciones, notas y salidas.
- **Eventos dentro de su tramo horario**: los eventos que coinciden con un tramo se muestran en el propio tramo, en la vista de día, en las guardias y en la pantalla «Hoy» del tablón.

### Changed

- **Eventos y grupos**: un evento puede asociarse a grupos aunque lo vea todo el centro. Sin grupos es general automáticamente y el formulario no muestra nada que elegir; al seleccionar al menos un grupo aparecen «General» (lo ve todo el profesorado) y «Restringido a grupos» (solo quien se relaciona con ellos). Las ayudas explican la nueva semántica.

## [1.11.0] - 2026-10-24

### Added

- **Modo mantenimiento**: un ajuste global (Ajustes globales → Mantenimiento) que cierra la aplicación a todo el mundo salvo a los administradores globales, para actualizar, restaurar una copia o intervenir sin que nadie encuentre datos a medias. El resto del profesorado, con o sin sesión iniciada, recibe una página 503 animada con el estilo de las páginas de error; la pantalla de acceso lo avisa, y los administradores (también con «Acceder como») ven una franja que les recuerda que está activado. Se bloquea todo menos el acceso, la salida y los estáticos, incluidos las actualizaciones en vivo, el enlace público de tareas y la recuperación de contraseña. Los avisos por correo y las tareas automáticas siguen funcionando. Documentado en el manual, con capturas.

### Fixed

- **Instalaciones con SQLite** (el binario nativo y la demostración): guardar cualquier ajuste fallaba con un error de clave foránea, porque el catálogo de ajustes se sembró con ids que Doctrine no reconoce. Además, en las bases que ya tenían centros cuando se ejecutaron las migraciones antiguas, los métodos de comunicación, las ubicaciones, los tipos de nota y las asignaciones de docentes a grupos quedaban sin poder localizarse por su id. Una migración nueva repara todos esos ids y las referencias a ellos; en una base sin el problema no hace nada.
- **Actualizar con SQLite desde antes de la 1.9.0** con algún centro creado: la migración de Salidas fallaba con «FOREIGN KEY constraint failed» y la actualización no se completaba. Corregida.
- El formulario de nueva nota del día compara correctamente el tipo seleccionado al volver a mostrarse con datos, y se eliminan los avisos de obsolescencia de Twig.

### Changed

- Los binarios descargables pasan a **FrankenPHP 1.13.3** (PHP 8.5.12, con PHP2Shell corregido), que corrige el fallo `status=4/ILL` de los binarios de Linux de la 1.13.2 en CPUs sin SHA-NI. La compilación verifica ahora el SHA-256 de cada binario de FrankenPHP que empaqueta.
- Ya no se publica el binario para Mac con procesador Intel: FrankenPHP dejó de ofrecerlo en la 1.13.2. La última versión con descarga para Intel es la 1.10.5; quien lo use puede recurrir a Docker, y el manual lo explica.

## [1.10.5] - 2026-10-23

### Fixed

- Los binarios descargables vuelven a FrankenPHP 1.13.1. Los binarios de Linux de la 1.13.2 (la que llevaban la 1.10.3 y la 1.10.4) se compilaron para la CPU del servidor de CI y hacían que el servicio muriera con `status=4/ILL` en cuanto recibía una petición, en CPUs sin instrucciones SHA-NI (habitual en máquinas virtuales). La 1.13.1 lleva el mismo PHP 8.5.12, así que sigue corrigiendo **PHP2Shell**.

### Changed

- Se vuelve a publicar el binario para Mac con procesador Intel, que la 1.13.1 sí ofrece.

## [1.10.4] - 2026-10-23

### Fixed

- El binario nativo para macOS y Linux vuelve a arrancar: el precalentamiento de la caché con `php-cli` se quedaba sin memoria (128 MB por defecto de PHP) y `start.sh` abortaba. Los scripts de arranque fijan ahora el límite en 256 MB, igual que el servidor. Afectaba también a 1.10.2 y 1.10.3.
- En el formulario de nueva nota del día, el tipo seleccionado se compara ahora correctamente al volver a mostrar el formulario con datos (la expresión carecía de paréntesis).
- Se eliminan los avisos de obsolescencia de Twig: las macros del menú lateral pasan a la raíz de la plantilla.

## [1.10.3] - 2026-10-22

### Security

- Los binarios descargables pasan a FrankenPHP 1.13.2, que lleva PHP 8.5.12, y la imagen Docker se reconstruye siempre sobre la última imagen base de PHP 8.4 (8.4.27 o posterior). Con ello se corrige **PHP2Shell** (CVE-2026-109618 y CVE-2026-109619), dos fallos críticos que permiten ejecutar código en el servidor sin autenticarse. Las versiones anteriores, también las descargables, son vulnerables: actualiza cuanto antes. Cambiar el `php.ini` no lo evita.
- FrankenPHP 1.13.2 corrige además otras vulnerabilidades propias del servidor.

### Changed

- Ya no se publica el binario para Mac con procesador Intel: FrankenPHP dejó de ofrecerlo. Quien lo use puede recurrir a Docker; el manual lo explica.
- Las pruebas automáticas se ejecutan con PHP 8.4 y 8.5, porque los binarios llevan ahora PHP 8.5.

## [1.10.2] - 2026-10-15

### Fixed

- La pantalla de login muestra la versión de la aplicación junto al nombre cuando el viewport es pequeño (en pantallas grandes ya aparecía en el panel lateral).
- En el manual en PDF, los enlaces a títulos numerados, a anclas explícitas y a capítulos cuyo nombre de fichero no coincide con su título ya apuntan a su destino. El filtro de enlaces construye ahora una tabla con los identificadores reales de los encabezados, y los identificadores dejan de contener bytes inválidos.

## [1.10.1] - 2026-10-14

### Fixed

- La administración (global o del centro) puede eliminar una salida del aula desde la pestaña Historial de Salidas, con confirmación previa. Se borra definitivamente de la base de datos, solo en el curso activo, y queda constancia en el registro de actividad.

## [1.10.0] - 2026-10-14

### Changed

- El menú lateral agrupa ahora las secciones por lo que se hace con ellas, con un título para cada grupo: **Día a día** (Salidas, Guardias, Ausencias y Calendario), **Convivencia** (Partes, Notas, Sanciones, Tareas de sanción, Notificaciones y Mi tutoría) y **Gestión** (Informes y Centro educativo, solo para el equipo directivo y la administración). Un grupo sin ninguna entrada visible para el perfil no aparece.
- Los accesos rápidos del inicio siguen los mismos bloques y el mismo orden que el menú. En el móvil son baldosas compactas de dos en dos, para llegar a cada acción con un toque; en pantallas mayores son tarjetas del mismo ancho que se reparten en tantas columnas como caben, sin desbordes ni líneas saturadas.
- La paleta de comandos (`Ctrl+K`) incluye «Ver quién está fuera» y «Nueva nota».
- Se regeneran todas las capturas del manual y de las fichas con el nuevo menú, se añaden las de la sección Salidas y se actualizan el manual y la presentación.

## [1.9.0] - 2026-10-14

### Added

- Nueva sección **Salidas** para controlar qué estudiantes están fuera del aula con permiso de un docente, pensada para usarse desde el móvil. Se autoriza una salida en tres pasos (grupo, con los del docente primero; estudiante, con buscador; y motivo, en una cuadrícula de botones con observaciones opcionales), con un botón fijo «Autorizar salida» también en el inicio y en la paleta de comandos.
- La pantalla de salidas muestra en tiempo real quién está fuera, con estudiante, grupo, docente, motivo, hora de salida y «Hace X minutos»; a partir de los 10 minutos el tiempo pasa a ámbar, naranja (20) y rojo (30). Las salidas propias van aparte con el botón «Ha regresado»; en las de otros solo lo ven el tutor/a del grupo, la dirección y la administración.
- Historial de salidas por estudiante en su ficha y, para el equipo directivo y la administración, una pestaña con todas las del curso, filtrable por estudiante, docente, grupo, motivo, estado y fechas, ordenable y con el tiempo fuera en minutos.
- Catálogo de **motivos de salida** en «Centro educativo» (ordenable, activable, exportable e importable), con los motivos por defecto: Servicio, Fuente, Conserjería, Departamentos, Carro portátiles, Secretaría, Jefat. de Estudios, Dirección y Otros. Los centros existentes los reciben al actualizar.
- **Pase prioritario** por estudiante y curso, que concede la administración desde la ficha con una justificación: el estudiante aparece con el distintivo PASE en los listados de salidas y la justificación se muestra al autorizar su salida.
- Fichas rápidas «Autorizar una salida del aula» y «Quién está fuera y registrar el regreso» para el profesorado.

### Changed

- Al eliminar una materia de un docente que tiene tareas de sanción asociadas, la aplicación ya no lo bloquea: avisa de cuántas tareas se perderían y, si se confirma, borra la materia junto con sus tareas y adjuntos (desde el formulario del grupo y desde las materias del docente).

### Fixed

- Los accesos rápidos del inicio generan las clases de columnas necesarias cuando hay cinco o más.

Incluye la migración `Version20260101000048` (tablas `exit_reason`, `student_exit` y `student_priority_pass`, y siembra de los motivos por defecto en los centros existentes).

## [1.8.1] - 2026-10-14

### Changed

- Al registrar una sanción, el listado de estudiantes se ordena poniendo primero a quienes tienen más partes graves y, en caso de empate, a quienes tienen más partes sancionables.

## [1.8.0] - 2026-10-13

### Changed

- La campana de notificaciones agrupa ahora los avisos en secciones por prioridad: primero los partes y sanciones **próximos a prescribir** (con los días que les quedan; el contador se pone en rojo si hay alguno), después partes pendientes, sanciones pendientes y tareas de sanción. Cada sección muestra sus 4 primeros elementos y un «y N más», para que una sección con muchos avisos no tape a las demás.
- Las notas que alcanzan el umbral para generar un parte solo aparecen en la campana de la administración del centro; los tutores las siguen viendo en el panel de inicio y en su pantalla de notas.
- En la pantalla de gestión de docentes del centro, el formulario «Añadir docente al curso académico» pasa a estar después del listado.

## [1.7.2] - 2026-10-13

### Fixed

- En la ficha de la sanción, el botón de copiar el enlace de tareas y el enlace «Abrir la página del alumno» ya muestran su icono.

## [1.7.1] - 2026-10-13

### Changed

- Quien puede gestionar el enlace de tareas de una sanción (tutor/a del grupo, comisión de convivencia y administradores) puede seguir abriéndolo con su sesión iniciada cuando la sanción ha terminado; el alumno sigue sin acceso pasado el último día. La ficha de la sanción muestra el enlace también cuando ha caducado y el botón de copiar queda junto al propio enlace.

## [1.7.0] - 2026-10-13

### Added

- **Enlace público a las tareas de una sanción.** El tutor/a del grupo, la comisión de convivencia y los administradores pueden generarlo, regenerarlo o eliminarlo desde el detalle de la sanción. El alumno accede sin iniciar sesión mediante una dirección corta (`/t/XXXXX-XXXXX`, un código Base32 de 10 caracteres) o escribiendo el código en `/t`, y ve su nombre, grupo, fechas de la sanción y las tareas de cada materia (texto y adjuntos), con descarga de todo en un ZIP. El acceso se cierra al terminar la sanción y los códigos erróneos se limitan por IP.
- El PDF de la sanción incluye la dirección y el código del enlace cuando la sanción tiene tareas y el enlace ya existe.
- Nueva ficha rápida para el alumnado y las familias sobre cómo consultar las tareas, y apartados nuevos en el manual y la presentación.

**Al actualizar:** hay que ejecutar las migraciones (`make migrate`); añaden una columna a las sanciones.

## [1.6.1] - 2026-10-12

### Changed

- Menos consultas a la base de datos en el menú, las fichas de sanción y de estudiante, los catálogos de conductas, medidas y ubicaciones, la parrilla de tramos horarios y el calendario: el número de consultas ya no crece con la cantidad de datos mostrados. Los papeles del docente en el curso (tutor, docente, guardia) que usa el menú se resuelven ahora con una sola consulta.

### Fixed

- En los ajustes con una plantilla PDF, el botón «Subir» solo aparece cuando se ha seleccionado un fichero.

## [1.6.0] - 2026-10-09

### Added

- Resumen diario de los avisos por correo: en lugar de un correo por cada aviso, el profesorado recibe un único correo al día por centro, con las novedades agrupadas por secciones (próximos a prescribir, partes, sanciones, tareas de sanción y notas) y un enlace a cada una. Se controla con los nuevos ajustes «Forma de recibir los avisos por correo» (resumen diario o inmediata) y «Hora del resumen diario» (7 por defecto), que siguen el esquema global › centro › docente con sus bloqueos.

### Changed

- **El resumen diario es ahora la forma por defecto**: tras actualizar, quien no tenga fijado un valor propio dejará de recibir un correo por aviso y recibirá uno al día. Quien prefiera los correos inmediatos puede elegirlo en sus ajustes (o el administrador fijarlo para todo el centro).
- Los avisos de prescripción próxima de partes y de sanciones y el recordatorio de tareas de sanción pendientes se envían siempre dentro del resumen, también a quien reciba el resto de forma inmediata: un correo diario en lugar de tres.
- En el resumen no se incluyen los PDF adjuntos, solo los enlaces. Los correos de verificación de correo y de recuperación de contraseña siguen enviándose al momento.

## [1.5.0] - 2026-10-09

### Added

- Un administrador puede marcar una sanción como prescrita (o quitar la prescripción) a mano desde su pantalla de edición, en el nuevo apartado «Prescripción», igual que con los partes. Al marcarla se envía el aviso «Sanción prescrita» (si está activado), nombrando a quien la marcó, y queda constancia en el registro de actividad. Está disponible aunque el centro no haya activado la prescripción automática de sanciones, y una sanción prescrita puede volver a notificarse quitando la fecha.

## [1.4.12] - 2026-10-09

### Added

- Las sanciones sin notificar a la familia pueden prescribir. Se controla con el nuevo ajuste «Días para la prescripción automática de sanciones» (global o por centro), que vale 0 por defecto: con ese valor las sanciones no prescriben y todo funciona como hasta ahora. Al activarlo, una tarea diaria marca como prescritas las sanciones fuera de plazo, otro aviso diario («Aviso de prescripción próxima de sanciones», con valor personal por docente) avisa de las que están a punto de prescribir y el nuevo aviso por correo «Sanción prescrita» informa de las prescritas. Las sanciones prescritas salen de las notificaciones pendientes y se muestran atenuadas con la etiqueta «Prescrita».
- Con la prescripción de sanciones activada hay además estadísticas: la columna «Sanciones prescritas» en Mi tutoría, la tarjeta «Sanciones próximas a prescribir» en el inicio y el recuento de sanciones prescritas en las estadísticas por grupo (pantalla, PDF y Excel). La sección roja de Notificaciones muestra también las sanciones próximas a prescribir.

### Changed

- Los ajustes de prescripción de partes se llaman ahora «Días para la prescripción automática de partes» y «Aviso de prescripción próxima de partes», para distinguirlos de los de sanciones.

### Fixed

- Al sancionar a un estudiante cuyos partes tenían observaciones que el usuario podía borrar, el formulario no mostraba el rango de fechas de las medidas que lo requieren ni permitía guardar la sanción. La lista de observaciones se muestra ahora en solo lectura dentro de los formularios de sanción (alta y edición) y de notificación en bloque de partes.

## [1.4.11] - 2026-10-09

### Added

- La pestaña «Notificaciones pendientes» muestra, arriba del todo y sobre fondo rojo, la sección «Partes próximos a prescribir», con los días que quedan a cada parte y los botones «Ver» y «Notificar». Solo aparece si hay partes en esa situación (los mismos que cuenta el inicio y avisa el correo de prescripción próxima).

## [1.4.10] - 2026-10-09

### Fixed

- Los partes prescritos ya no aparecen en las notificaciones pendientes (cola, campana y pantallas de notificación en bloque), ni en el listado de estudiantes con notificaciones pendientes, ni cuentan como «Sin notificar» en Mi tutoría.
- En el listado de estudiantes con notificaciones pendientes, los botones «Notificar partes» y «Notificar sanciones» caben ahora en una sola línea: se estrechan las columnas de recuento y se da más sitio a las acciones.

## [1.4.9] - 2026-10-09

### Fixed

- En ventanas estrechas, las medidas de una sanción con nombre largo ya no se ven como un círculo: se muestran como etiquetas rectangulares de esquinas suaves en el listado de sanciones, la ficha, el panel de inicio y la pantalla de notificar sanciones.
- Las etiquetas de estado del listado de sanciones («Pendiente de notificar», «En Séneca», «No efectiva», «Reclamada») tampoco se ven ovaladas cuando se parten en dos líneas.

## [1.4.8] - 2026-10-09

### Fixed

- El listado de partes muestra ya la columna de acciones en pantallas de 1280 px, sin necesidad de desplazarse hacia los lados.
- El registro de avisos por correo muestra la columna de resultado sin desplazamiento horizontal; el asunto se acorta y el evento puede ocupar dos líneas.
- Los campos numéricos de los ajustes ya no cortan el rango permitido.
- En el móvil, las pantallas de docentes del centro, conductas, ubicaciones, medidas disciplinarias, métodos de comunicación, tipos de nota, días no lectivos y tramos horarios ya no desbordan la página: los botones de la cabecera pasan a otra línea.

## [1.4.7] - 2026-10-09

### Added

- Al abandonar un formulario con cambios sin guardar (partes, sanciones, ausencias, notas, eventos, alumnado…) el navegador pide confirmación.
- Enlace «Saltar al contenido principal» para quien navega con teclado o lector de pantalla.

### Changed

- Las pantallas de edición de los catálogos (métodos de comunicación, conductas, ubicaciones, tipos de nota) tienen ahora un título de pestaña propio, distinto del de su listado.

### Fixed

- El panel de un docente, el listado de sanciones, el de partes, el de notificaciones y el de alumnado cargan mucho más rápido: se han eliminado cientos de consultas repetidas a la base de datos.
- Mejoras de accesibilidad: las etiquetas de los filtros y los ajustes quedan asociadas a sus campos, las cabeceras de las tablas se anuncian como columnas, el buscador rápido y las pantallas de acceso tienen su zona principal y su nombre accesible, y el campo «ir a página» ya no duplica identificadores.
- Los textos del registro de actividad y de la portada de Administración pasan por las traducciones.

## [1.4.6] - 2026-10-08

### Added

- Dos ajustes nuevos en «Subida de ficheros»: tamaño máximo por fichero (10 MB por defecto) y tamaño máximo total por envío (50 MB), para controlar las subidas de varios ficheros a la vez.

### Fixed

- Subir ficheros grandes (por ejemplo, adjuntos de tareas de sanción) ya no deja la pantalla en blanco: la aplicación avisa antes de enviar si se superan los límites y, si aun así llegan al servidor, vuelve al formulario indicando el fichero afectado y el máximo permitido. En el binario nativo, el servidor admite ahora hasta 16 MB por fichero y 64 MB por envío. Al actualizar, sustituye también el `Caddyfile` (o `php.ini`) de la instalación.

## [1.4.5] - 2026-10-04

### Fixed

- El registro de avisos por correo muestra ahora el nombre de los eventos de tarea de sanción asignada, recordatorio de tarea de sanción pendiente y nota que implica registro de parte, que aparecían como una clave sin traducir.

## [1.4.4] - 2026-10-04

### Added

- Los listados «Docentes del centro» y «Administración > Docentes» indican, bajo el correo, si el docente tiene un cambio de correo pendiente de verificar, y su buscador encuentra también por correo electrónico.

### Changed

- En «Docentes del centro» el correo (y el resto de columnas) se ve también en pantallas estrechas: la tabla se adapta a tarjetas, como la de Administración.

### Fixed

- El registro de actividad ya no muestra textos sin traducir: faltaban las etiquetas de varios tipos de acción (inicio y cierre de sesión, notas, ubicaciones, días no lectivos, eventos…), los nombres de los campos modificados y valores como el resultado de una comunicación (`notified`), el origen de una importación o las fechas en formato técnico. El filtro de tipo de acción muestra además la etiqueta junto al código.

## [1.4.3] - 2026-10-04

### Changed

- El manual, las fichas rápidas y la presentación tienen todas las capturas de pantalla actualizadas (menú lateral con la sección activa destacada, listado de notificaciones con sanciones, registro de actividad legible…) y nuevas pantallas: la vista previa de la importación de docentes, la notificación de partes y sanciones en bloque y el detalle de una sanción con sus tareas. El manual describe además la columna «Datos» del registro de actividad.

## [1.4.2] - 2026-10-04

### Added

- La importación de docentes desde Séneca muestra ahora una **vista previa** antes de importar: se elige qué docentes se crean o añaden al curso (todos marcados por defecto, con «Seleccionar todo» y «Seleccionar nada») y se ve qué ocurrirá con cada uno.
- Si el fichero incluye la columna «Cuenta Google/Microsoft», se puede importar como correo electrónico del docente. Solo se rellena el de quien no tiene ninguno: nunca se sobrescribe un correo existente ni se asigna uno que ya use otro docente.
- Opción de retirar del curso a los docentes que no figuran en el listado de Séneca y no tienen ninguna vinculación este curso (no tutorizan ni imparten en ningún grupo, no tienen guardias ni ausencias previstas, no han registrado partes, sanciones ni notas y no tienen un rol en el centro). Siguen existiendo en el sistema y en otros cursos.

## [1.4.1] - 2026-10-03

### Changed

- Los informes en PDF (partes, sanciones, estadísticas por grupo y profesorado de guardia) usan ahora el tipo de letra Source Sans Pro en lugar de DejaVu Sans, con el mismo tamaño de texto que antes.
- En el menú lateral, la sección en la que estás se destaca ahora con una franja de color a la izquierda, además del fondo.
- La sección "Tramos horarios" pasa a llamarse "Tramos horarios y guardias".

## [1.4.0] - 2026-09-24

### Changed

- El registro de actividad (administración) muestra ahora el detalle de cada entrada como una lista legible de campo y valor, con las claves traducidas y los valores formateados (booleanos, listas, ausencia de dato), en vez del JSON crudo truncado de antes.

## [1.3.3] - 2026-09-24

### Added

- El listado "Estudiantes con partes pendientes de notificar" de la sección Notificaciones pasa a llamarse "Estudiantes con notificaciones pendientes" e incluye una columna de sanciones pendientes junto a la de partes, mostrando también a los estudiantes que solo tienen sanciones sin notificar. Cada fila ofrece un botón "Notificar sanciones" (además del ya existente "Notificar partes") para notificar en bloque todas las sanciones pendientes de ese estudiante.

## [1.3.2] - 2026-09-24

### Fixed

- En la ficha de sanción, los badges de estado de las tareas ("Pendiente", "Cumplida", "No aplica") ya quedan alineados verticalmente entre sí, en vez de desplazarse a izquierda o derecha según si esa fila tenía o no el botón de editar.

## [1.3.1] - 2026-09-24

### Fixed

- En la pantalla de creación/edición de sanciones, el listado de partes seleccionables ya muestra la insignia roja de "Grave" junto al número de parte cuando tienen alguna conducta grave asociada, en vez de estar oculta dentro del desplegable de detalles.

## [1.3.0] - 2026-09-22

### Added

- El PDF de **profesorado de guardia** (en "Centro educativo › Tramos horarios") muestra ahora una pantalla previa de opciones antes de generarse: permite elegir qué tramos horarios incluir (todos seleccionados por defecto) y el tamaño del texto de los nombres del profesorado (80%, 90%, 100%, 110% o 120%, siendo 100% el valor por defecto).

### Changed

- En el PDF de profesorado de guardia, la tabla incorpora líneas verticales entre columnas para separar mejor los días de la semana, y los nombres del profesorado pasan a mostrarse como «Nombre Apellidos» en vez de «Apellidos, Nombre».

## [1.2.2] - 2026-09-21

### Fixed

- En **Oferta formativa**, los mensajes de confirmación (guardado, eliminado…) ya no se quedaban ocultos hasta cambiar de sección: aparecían todos de golpe al abrir otra pantalla en vez de mostrarse al momento. Ahora se muestran de inmediato, igual que en el resto de la aplicación.

## [1.2.1] - 2026-09-21

### Fixed

- En la ficha del alumno, las notas diarias de un tipo de nota ya desactivado en la configuración del centro dejan de mostrarse en el historial y en los contadores por tipo.

## [1.2.0] - 2026-09-20

### Added

- Las búsquedas y filtros por texto (partes, sanciones, estudiantes, docentes, centros, notas diarias, eventos de centro, ubicaciones, comunicaciones y registro de avisos por correo) ya no distinguen tildes en PostgreSQL: buscar «Jose» encuentra también a «José». No requiere ninguna acción manual — se activa solo al actualizar, y si por algún motivo no puede activarse (permisos insuficientes en el servidor de base de datos), la aplicación sigue funcionando con normalidad, simplemente sin esta mejora. No aplica a instalaciones que usen SQLite.

## [1.1.0] - 2026-09-13

### Added

- Nuevo ajuste **Prefijo del asunto de los correos** (categoría «Avisos por correo», global y de centro, vacío por defecto): el texto configurado se antepone, seguido de un espacio, al asunto de todos los avisos por correo sobre partes, sanciones, tareas de sanción y notas diarias. Un centro con valor propio anula el prefijo global para sus correos; si ninguno de los dos está definido, el asunto no cambia. No afecta a los correos de restablecimiento de contraseña ni de verificación de email.

## [1.0.4] - 2026-09-12

### Fixed

- El desplegable de docente con búsqueda, recién añadido en Oferta formativa, no filtraba correctamente: al hacer clic se precargaba una lista de docentes sin filtrar que nunca desaparecía, y cada búsqueda solo añadía resultados encima en vez de sustituirla. Se corrige en ese desplegable y en los otros 9 sitios de la aplicación que usan el mismo componente de búsqueda de docente (ausencias, notas diarias, partes, perfiles de centro, administradores de centro, listado de ausencias y registro de actividad).

## [1.0.3] - 2026-09-11

### Fixed

- Al añadir un docente a un grupo desde **Centro educativo › Oferta formativa**, el botón "+" no hacía nada: el mini-formulario de alta rápida (docente + materia) estaba anidado dentro del formulario del panel de detalle del grupo, y un `<form>` anidado es HTML inválido, así que el navegador lo ignoraba y el botón acababa enviando el formulario exterior en vez de dar de alta al docente. Se aprovecha además para sustituir el desplegable de docente por uno con búsqueda, igual que en el resto de la aplicación.
- `dist/update-ubuntu.sh` no borraba, al actualizar, los ficheros que ya no formaban parte de una versión más reciente (p. ej. una clase eliminada en un refactor): al extraer con `tar` solo se añaden o sobrescriben ficheros, nunca se borran los sobrantes, así que se iban acumulando en el servidor y podían hacer fallar el arranque de la aplicación («Expected to find class ... but it was not found»). Ahora la actualización compara lo instalado con el paquete nuevo y borra lo que ya no exista en este último.

## [1.0.2] - 2026-09-11

### Fixed

- `dist/update-ubuntu.sh` fallaba con «Cannot open: Permission denied» al extraer el paquete descargado: el fichero temporal lo crea `mktemp` con permisos 600 propiedad de `root`, y hacerlo legible con `chmod 644` no bastaba en todos los servidores (p. ej. si `/tmp` tiene un ACL por defecto que anula el bit «other») para que el usuario `gestconvplus` pudiera abrirlo por su cuenta al extraerlo. Ahora es `root` quien abre el fichero descargado y le pasa el descriptor ya abierto al `tar` que corre como `gestconvplus`, sin que este necesite permiso propio sobre el fichero.

## [1.0.1] - 2026-09-11

### Fixed

- Un docente dado de alta en el curso académico activo pero que todavía no tenía ningún grupo asignado (ni como profesorado ni como tutor/a) no veía ningún centro en la pantalla de selección de centro y no podía acceder a la aplicación. Ahora basta con estar incorporado al curso académico activo del centro para poder acceder, independientemente de si ya se tiene grupo asignado.

## [1.0.0] - 2026-08-05

### Fixed

- El script de instalación automatizada (`install-ubuntu.sh`) fallaba al ejecutarse vía `curl | bash`. Cuando bash lee el script desde la tubería de curl, cualquier `read` ve EOF en cuanto curl termina. Redirigir stdin globalmente con `exec </dev/tty` tampoco funciona: bash lee el script en fragmentos y, tras el `exec`, intenta leer las siguientes líneas del script desde el terminal en lugar de desde curl. La solución correcta es añadir `</dev/tty` a cada uno de los cinco `read` interactivos; así bash sigue leyendo el script desde curl y solo los prompts toman la entrada del terminal.
- La sentencia `CREATE DATABASE` en el heredoc SQL del mismo script omitía el nombre de la base de datos en el `SELECT` pasado a `\gexec`, lo que provocaba «syntax error at end of input» al intentar crear la base de datos.
- El fichero `.env.local` generado por `install-ubuntu.sh` escribía `DATABASE_URL` (y el resto de valores interpolados) sin comillas. Como este fichero se carga con `source` en bash, el `&` sin comillas de `serverVersion=16&charset=utf8` se interpretaba como el operador de segundo plano: la asignación de `DATABASE_URL` se ejecutaba en una subshell en segundo plano y nunca llegaba al proceso padre, provocando «DATABASE_URL: Falta DATABASE_URL en .env.local» al arrancar el servicio. Ahora todos los valores del `.env.local` generado van entre comillas dobles, y la validación de la contraseña de base de datos rechaza también comillas dobles, barra invertida, signo dólar y acento grave, no solo la comilla simple.
- `doctrine:fixtures:load --append` (usado para recargar los datos de demostración, p. ej. con `LOAD_FIXTURES=true` en Docker) fallaba con un error de clave foránea si la base de datos ya tenía ausencias, tramos horarios o días no lectivos registrados: `AppFixtures::wipeDatabase()` no incluía esas tablas, más recientes que el resto, en su lista de borrado. Se añaden `absence`, `time_slot` y `non_working_day` en el orden correcto.
- `gestconv-start.sh`/`gestconv-worker.sh` (generados por `install-ubuntu.sh`) comprobaban con `-f` si `data/.secret` existía antes de generar `APP_SECRET`, sin comprobar que tuviera contenido. Una ejecución anterior interrumpida a mitad de camino podía dejar ese fichero creado pero vacío (0 bytes), y el secreto vacío se propagaba a `app/.env` en cada arranque posterior sin regenerarse nunca, provocando «A non-empty secret is required.» al renderizar cualquier página con componentes UX Live. Ahora se comprueba con `-s` (existe y no está vacío), que se autorregenera solo en el siguiente arranque si detecta el fichero vacío.
- Las páginas de error (404, 403, 500 y la genérica) tenían su propia paleta `--forest-*` embebida en el `<style>`, congelada en el verde bosque original de la aplicación; el tema real (`assets/styles/app.css`) migró hace tiempo a una paleta azul/pizarra bajo el mismo nombre. Se actualizan los 8 tonos y los `rgba()` de brillos/sombras en los cuatro ficheros para que coincidan exactamente con la paleta actual.
- El fragmento de script de actualización de la [guía de despliegue continuo](docs/despliegue/despliegue-continuo.md) tenía dos bugs: anteponía una `v` extra a un `.version` que ya la incluía (por lo que la comparación de versiones nunca coincidía y reinstalaba en cada disparo del timer) y construía la URL de descarga sin el prefijo `v` en el nombre del paquete (404 al descargar). Ambos se corrigen en `dist/update-ubuntu.sh` (ver «Added»). La sección «Actualización en Ubuntu Server» del manual tenía el mismo bug del prefijo `v` en su comando de ejemplo.
- Al registrar un parte, un administrador de centro con permiso para elegir el docente pero que no imparte clase en el curso académico activo (no está dado de alta como profesorado de ese curso) veía el desplegable de «Registrado por» preseleccionado con su propio usuario. Como ese usuario no es un docente válido del curso, guardar el parte sin cambiar la selección fallaba con «El docente seleccionado no es válido.». Ahora solo se preselecciona al usuario actual si es un docente válido del curso activo; en caso contrario el desplegable aparece vacío y, si se guarda sin elegir a nadie, se muestra el mismo error de validación en vez de crear el parte.
- El radio de tipo del listado de estudiantes de Notas usaba `data-action`+`data-live-id-param` en vez de `data-model`: al hacer clic, el navegador cambiaba la propiedad `checked` del radio al instante, pero el morphing de la respuesta del servidor comparaba esa misma propiedad para decidir si actualizaba el atributo `checked` y el resaltado de la etiqueta, así que a veces se quedaban anclados en el tipo anterior aunque los datos mostrados ya fueran los del tipo recién seleccionado. Se corrige usando `data-model`, igual que el resto de filtros del mismo componente.
- `NonWorkingDayChecker::isNonWorkingDay()`/`descriptionFor()` consultaban la base de datos por cada día visible de la cuadrícula del calendario (hasta ~75 consultas por vista mensual). Ahora cargan los días no lectivos del curso en una sola consulta, memoizada por curso académico durante el resto de la petición.

### Added

- **Plantillas PDF de informes**: cada centro puede subir un PDF de una sola página (por ejemplo, un membrete oficial ya maquetado) que se usa como fondo de cada página de sus informes. Dos nuevos ajustes de centro son las plantillas **generales**, una por orientación (vertical y apaisada); cuatro ajustes más permiten sustituir, solo para un tipo de informe concreto (parte, sanción, estadísticas por grupo o profesorado de guardia), la plantilla general de su orientación. Si un informe no tiene plantilla propia se usa la general de la orientación que necesita; si tampoco hay ninguna, se genera sin fondo, igual que hasta ahora. La subida admite arrastrar el fichero a un recuadro o elegirlo con el selector del sistema, y se valida antes de guardarlo: debe ser un PDF legible de una sola página (una plantilla de varias páginas se repetiría de forma confusa como fondo de todas las páginas del informe), de hasta 10 MB, y con la orientación que corresponde al ajuste. Mientras un ajuste tenga una plantilla guardada, se muestra su nombre y tamaño con botones para verla o eliminarla, en vez del recuadro de subida — hay que eliminarla primero para poder sustituirla por otra.

  Técnicamente, se introduce un nuevo tipo de ajuste (`pdf`) cuyo valor no es un texto sino la referencia a un fichero, almacenado en una tabla genérica y deduplicada por hash de contenido (si el mismo PDF se sube en más de un ajuste, solo se guarda una vez; al reemplazar o eliminar una referencia, el fichero se borra si queda huérfano). El fondo se aplica en la generación del PDF mediante la función de mPDF para importar páginas de otro documento, sin necesitar ninguna dependencia nueva.

- **Calendario unificado y eventos de centro**: las antiguas pestañas «Calendario de sanciones» y «Calendario de ausencias» se fusionan en una sola cuadrícula mensual (sanciones para todo el profesorado, ausencias solo para administradores, como hasta ahora, ahora en la misma vista), cada tipo de elemento con su propio icono y color. Al hacer clic en un día se abre su **vista de detalle**, con navegación al día anterior/siguiente y el desglose completo (tramos horarios y guardias, sanciones, ausencias y eventos de ese día). Nuevo catálogo de **eventos de centro** (fecha, hora de inicio y fin, nombre, descripción y enlace opcionales, y visibilidad general o restringida a uno o varios grupos, elegida con radio buttons descriptivos), gestionado por administradores desde la nueva pestaña **Eventos** (listado paginado con búsqueda y filtro por grupo). Los eventos se muestran también en el modo tablón (pantalla «Hoy», incluso en días no lectivos) y en una nueva tarjeta **Eventos de hoy** del panel de inicio.
- Nueva sección **Notas**, para registrar y gestionar avisos de comportamiento del día a día (retraso, salida al baño, uso del móvil…) sin necesidad de un parte de convivencia formal:
  - Catálogo de **tipos de nota**, administrable por centro en **Centro educativo › Tipos de notas diarias** (nombre, reordenar, activar/desactivar, exportar/importar en JSON), con un umbral configurable de **ocurrencias que dan lugar a parte** por tipo (0 = nunca) y una **caducidad** en días (0 = no caduca nunca); pasados esos días desde su registro, una nota activa se desactiva sola mediante una tarea programada diaria. Los centros ya existentes reciben automáticamente los 4 tipos iniciales (Salida al baño: 0 ocurrencias/no caduca, Uso del móvil: 2/30 días, Retraso a primera hora: 3/no caduca, Otros: 0/no caduca); los centros nuevos los reciben igual que el resto de catálogos por defecto.
  - Al registrar una nota nueva, los tipos se muestran como opciones y se resaltan en rojo aquellos que, de añadirse un nuevo aviso, alcanzarían el umbral; al elegir un tipo, se muestra sin recargar la página el historial de ese tipo para el estudiante seleccionado (fecha/hora, docente y observaciones, con las notas desactivadas atenuadas). Si un administrador registra la nota y no es docente del curso activo, el campo de docente se deja en blanco para forzar una selección válida, igual que en los partes. Tras guardar, la pantalla de resumen muestra cuántas ocurrencias lleva el estudiante de ese tipo.
  - La pestaña **Listado de notas** admite búsqueda, filtro por grupo (solo los que tienen avisos) y por tipo, y los botones **Mis notas** y, si se tutoriza algún grupo, **De mi tutoría**. La visibilidad sigue el mismo criterio que los partes: cada docente ve las suyas y las de los grupos que tutoriza; solo los administradores ven todas.
  - Un docente puede editar las observaciones o eliminar sus propias notas durante los 30 minutos posteriores al registro; los tutores del grupo del estudiante pueden marcar cualquier aviso como **desactivado** (no cuenta para el umbral); los administradores pueden modificar o eliminar cualquier nota sin límite de tiempo.
  - La ficha del alumno muestra, justo antes del historial de convivencia, un histórico paginado de notas diarias y, tras él, las ocurrencias acumuladas por tipo. El botón **Nueva nota** aparece junto al de **Nuevo parte**, tanto en la ficha del alumno como entre los accesos rápidos del panel de inicio.
  - Nueva pestaña **Listado de estudiantes** (solo para quien tutoriza algún grupo o es administrador/a de centro): una fila por estudiante y grupo con notas del tipo elegido con radio buttons, con el número de notas activas/desactivadas y el rango de fechas de las activas; resaltada en rojo si se alcanza el umbral y en gris si todas las notas de ese tipo están desactivadas. Dos botones por fila: **Desactivar todo** (con confirmación) y **Registrar parte**, que abre el alta de un parte con el estudiante y el grupo ya fijados y, al guardarlo, desactiva automáticamente todas las notas activas del estudiante (de cualquier tipo), indicando cuántas en la pantalla de éxito. Con esto, quien decide registrar el parte al alcanzar el umbral es el tutor/a del grupo o administración, no cualquier docente.
  - Nuevo ajuste de correo **Nota que implica registro de parte** (nadie / tutor de grupo / equipo directivo / ambos), que avisa la primera vez que un estudiante alcanza o supera el umbral de un tipo, con el detalle completo de las notas activas de ese tipo. La campana de notificaciones y una nueva tarjeta **Notas que implican parte** del panel de inicio muestran también estos estudiantes a quien tutoriza su grupo o es administrador/a.
- La **búsqueda global** (⌘K/Ctrl+K) muestra ahora el grupo de cada estudiante encontrado, y separa los resultados entre **Tu alumnado** (a quienes se imparte clase o se tutoriza) y **Otro alumnado**: antes esta búsqueda solo devolvía el propio alumnado y ocultaba por completo al resto.
- La **ficha del estudiante** es ahora accesible para cualquier docente del centro, no solo para quien lo tutoriza, le da clase o ha registrado algo suyo: quien no tiene ninguna de esas relaciones con el estudiante ve una versión reducida, sin contadores, datos de contacto ni historial de convivencia, con solo las notas diarias y los accesos directos para registrar un parte o una nota.
- La campana **Tareas pendientes** de la cabecera muestra ahora también las [tareas de sanción](docs/manual/03-el-trabajo-diario.md#tareas-de-sancion) propias todavía sin cumplimentar, junto a los partes y sanciones pendientes de notificar que ya mostraba; cada una enlaza directamente a su formulario.
- Nuevo script `dist/update-ubuntu.sh`, pensado para ejecutarse con `curl ... | sudo bash` igual que `install-ubuntu.sh`: compara la versión instalada (fichero `.version`) con la última publicada en GitHub Releases y, si hay una más reciente, para los servicios, descarga y extrae el paquete nuevo (conservando `data/` y `.env.local`) y los vuelve a arrancar. Si ya está actualizado, no hace nada y termina en `0`, así que es seguro invocarlo repetidamente desde un cron o un systemd timer. Admite `--force` para reinstalar aunque la versión publicada parezca igual (útil tras una re-release que mueve la misma etiqueta a otro commit). La [guía de despliegue continuo](docs/despliegue/despliegue-continuo.md) ahora reutiliza este script en vez de duplicar su lógica.

  El paquete se descarga primero a un fichero temporal (`mktemp`, propiedad de `root` con permisos `600`) y luego se extrae ejecutando `tar` como `gestconvplus` (para que los ficheros extraídos queden con el propietario correcto); sin `chmod 644` sobre ese temporal, `gestconvplus` no tenía permiso para leerlo y la extracción fallaba con «Permission denied». Se corrige haciendo el temporal legible antes de extraer.

- Nuevo script `dist/setup-update-timer.sh`, que activa la opción de despliegue continuo por sondeo periódico (sin webhooks) descrita en la [guía de despliegue continuo](docs/despliegue/despliegue-continuo.md): descarga `update-ubuntu.sh`, crea el servicio y el timer de systemd (`gestconv-update.service`/`.timer`, cada 15 minutos) y activa el timer. Es idempotente, igual que el resto de scripts de `dist/`. Se ejecuta igual que `install-ubuntu.sh`/`update-ubuntu.sh`, tanto localmente como con `curl ... | sudo bash`.
- Nueva opción de exposición a Internet mediante **Cloudflare Tunnel**, para servidores detrás de un NAT o cortafuegos sin posibilidad de abrir los puertos 80/443: `install-ubuntu.sh` pregunta si usarlo y, en ese caso, instala y registra `cloudflared`, deja el servidor escuchando solo en local y ajusta `SYMFONY_TRUSTED_PROXIES` automáticamente; el despliegue con Docker incorpora el overlay opcional `compose.cloudflare.yaml`, que añade el sidecar `cloudflared` sin modificar `compose.yaml`. La nueva [guía de Cloudflare Tunnel](docs/despliegue/cloudflare-tunnel.md) detalla ambas vías, incluyendo el caso de un centro que solo dispone de un subdominio delegado dentro del dominio de su organización (Consejería, ayuntamiento…).

- Cada release incluye ahora un `gestconv-plus-documentacion-<versión>.zip` con todos los PDF publicados (manual, presentación y las fichas de referencia rápida) en un único archivo, además de seguir publicándose cada uno por separado.

- Los **días no lectivos** se pueden importar ahora también desde el CSV que genera Séneca en **Centro › Organización del centro › Calendario y Jornada › Calendario escolar › Días festivos**. Solo se importan los días marcados con «Sí» en la columna *Afecta al personal docente*; las filas restantes se contabilizan como descartadas. La pantalla de importación ofrece ambas vías (CSV de Séneca e iCal) y acepta ficheros en UTF-8 y Windows-1252.
- Nuevo catálogo de **días no lectivos** (festivos, puentes…), gestionado por los administradores de centro en **Centro educativo › Días no lectivos** con ámbito de curso académico: alta, edición y borrado manual (fecha y descripción opcional), o importación masiva desde un fichero **iCal (.ics)**, con enlace directo desde la propia pantalla de importación a la Secretaría Virtual de la Consejería de Educación, donde se puede descargar el calendario escolar oficial del centro en ese formato. Al importar, las fechas que ya existan en el curso se cuentan como ya existentes y no se duplican, por lo que el mismo fichero puede volver a importarse sin riesgo.
- Los días no lectivos se tienen en cuenta ahora en el resto de la aplicación: el calendario y el modo tablón los marcan visualmente, con un aviso en la pantalla «Hoy» del tablón y en guardias cuando el día no es lectivo (con la descripción del festivo si existe), y la navegación día a día de guardias salta el fin de semana. El alta y edición de una sanción muestra un contador de días lectivos entre el inicio y el fin de vigencia (con botones de incrementar/decrementar) que se recalcula al cambiar cualquiera de las tres fechas. Ningún selector de fecha de sanciones, ausencias de docente o actividades dentro de una ausencia permite ya elegir una fecha no lectiva: el formulario revierte la selección con un aviso, y el servidor repite la misma validación de forma autoritativa.
- Widget en el panel de inicio con las **sanciones notificadas y vigentes** de la semana actual y la siguiente, filtradas a los grupos donde el docente imparte clase o es tutor/a. Si el docente no pertenece a ningún grupo del curso activo, el widget no aparece.
- **Seguimiento de sanciones**: nuevos campos opcionales en el formulario de sanción («¿Las medidas tuvieron efecto?», «¿La familia reclamó?», «Actitud de la familia» y «¿Registrada en Séneca?») que permiten completar el resultado de la sanción una vez ejecutada; solo editable por quien tenga permiso de vista sobre la sanción.
- **Observaciones en sanciones**: cualquier docente o administrador con acceso a una sanción puede añadir anotaciones de texto enriquecido; el autor puede modificarlas o eliminarlas durante la hora siguiente a su creación, y los administradores en cualquier momento.
- Badges de estado en el listado de sanciones bajo el nombre del alumno: **En Séneca**, **Sin efecto** y **Familia reclamó**, derivados de los nuevos campos de seguimiento.
- El comando `app:setup` ya no crea por defecto el centro educativo de demostración «IES Test»: solo crea el usuario `admin`, pensado para despliegues en producción reales. La nueva opción `--demo-data` recupera el comportamiento anterior (crea también el centro de demostración con sus catálogos) para quien quiera probar la aplicación (ver [Comandos de consola](docs/manual/01-instalacion-y-puesta-en-marcha.md#comandos-de-consola)). Además, la pantalla de selección de centro ofrece ahora a los administradores globales, cuando todavía no hay ningún centro creado, un enlace directo a **Administración › Centros › Nuevo centro** y, en el pie, un acceso a **Administrar centros**.
- Los administradores globales pueden marcar en el formulario de un docente, tarjeta **Docentes** del panel de administración, un **cambio de contraseña obligatorio** que solo aplica a cuentas con contraseña propia (no a las de autenticación externa). El docente marcado queda confinado, la próxima vez que inicie sesión, a una pantalla dedicada de cambio de contraseña (contraseña actual, nueva contraseña y su repetición) hasta completarlo, con la única salida posible de cerrar sesión; el resto de la aplicación permanece inaccesible mientras tanto. Los comandos `app:setup` y `app:create-admin` fuerzan este cambio por defecto en las cuentas de administrador que crean, con la nueva opción `--no-force-password-change` para omitirlo (ver [Comandos de consola](docs/manual/01-instalacion-y-puesta-en-marcha.md#comandos-de-consola)).
- Nuevo catálogo de **ubicaciones** (dónde sucedió), configurable por los administradores de centro en **Centro educativo › Ubicaciones** y organizado en categorías igual que las conductas contrarias y las medidas disciplinarias (activar/desactivar, reordenar, editar, eliminar, y exportar/importar en JSON). Al registrar o editar un parte, el nuevo campo obligatorio **Dónde sucedió** ofrece este catálogo agrupado por categoría en un desplegable de búsqueda. Al crear un centro se siembra automáticamente un catálogo por defecto (*En clase*, *En el intercambio de clases*, *Entrada/Salida*, *Recreo*, *Durante las actividades extraescolares* y *Otros*); los centros ya existentes reciben el mismo catálogo por defecto al actualizar, y sus partes anteriores a esta funcionalidad se marcan como *Otros*. La ubicación se muestra en el detalle del parte y en su PDF.
- Los PDF de partes y sanciones admiten ahora un pie de contenido personalizado, añadido una sola vez al final del documento (a diferencia del encabezado, que se repite en cada página): dos nuevos ajustes de texto enriquecido (globales y de centro), **Pie de contenido de informe de partes** y **Pie de contenido de informe de sanciones**, en la categoría **Personalización de informes**, con una fecha y localidad de generación por defecto («En {city} a {current_day} de {current_month_name} de {current_year}», por ejemplo «En Sevilla a 10 de julio de 2026») que puede sustituirse por cualquier otro texto o vaciarse por completo. Se añaden además cinco marcadores nuevos, disponibles en los encabezados y pies de todos los tipos de informe (partes, sanciones y estadísticas por grupo): `{city}` (ciudad del centro educativo), `{current_date}` (fecha de generación del PDF), `{current_time}` (hora de generación), `{current_day}`, `{current_month_name}` y `{current_year}` (día, mes y año de generación por separado). Cada campo de texto enriquecido de esta categoría incorpora un icono de información que muestra, en un popover, la lista completa de marcadores disponibles y dónde se puede usar cada uno.
- Nueva sección **Informes** en la barra de navegación, tras Calendario, con el primer informe disponible: **Estadísticas por grupo**. A partir de un rango de fechas, muestra para cada grupo del curso académico activo —agrupados por enseñanza— el número de alumnos únicos con partes, y los partes registrados, notificados, sancionados y prescritos (desglosados entre normales y graves) junto con el total de sanciones distintas (una sanción asociada a varios partes cuenta una sola vez); cada enseñanza muestra un subtotal y el informe termina con el total general del centro. El informe puede exportarse a PDF (con el mismo encabezado personalizable que los partes y sanciones) o a una hoja de cálculo Excel. Los encabezados y el margen del PDF se configuran con tres nuevos ajustes (globales y de centro) en la categoría **Personalización de informes**.
- Mejoras de navegación: el nombre/logotipo de la aplicación en la cabecera (barra lateral y cabecera móvil) enlaza ahora al panel de inicio, y todas las migas de pan incorporan al principio un icono de inicio con el mismo enlace. Además, todas las pantallas del hub de centro educativo comparten ahora el prefijo de ruta `/centro/*` y una nomenclatura de ruta coherente (`app_centre_*`), y la sección **Centro educativo** de la barra de navegación se resalta correctamente en todas ellas.
- Los avisos por correo de partes y sanciones pueden llevar ahora el PDF del elemento adjunto, con dos nuevos ajustes booleanos (globales y de centro, desactivados por defecto) en la categoría **Avisos por correo**: **Enviar parte adjunto al correo** (se aplica a los seis avisos de un parte) y **Enviar sanción adjunta al correo** (al aviso de sanción notificada). Solo afecta al correo enviado, no al registro de avisos.
- Los encabezados de los PDF de partes y sanciones son ahora personalizables mediante seis nuevos ajustes (globales y de centro) de la nueva categoría **Personalización de informes**: contenido izquierdo y derecho de cada tipo de informe —con formato enriquecido y marcadores sustituibles como `{title}`, `{report_nr}`, `{student_name}`, `{group_name}`, `{centre_name}` o `{academic_year}`— y el margen superior de la página en milímetros (10-80, 22 por defecto). El título por defecto del encabezado pasa a ser genérico («Informe de parte de convivencia» / «Informe de sanción de convivencia») en lugar de repetir el número de parte que ya encabeza el cuerpo del documento. Para estos ajustes se estrena un nuevo tipo de campo de texto enriquecido con editor visual en la pantalla de ajustes.
- La marca de agua diagonal «BORRADOR» que se mostraba en el PDF de un parte o una sanción mientras no se hubiera notificado a la familia pasa a ser opcional, mediante un nuevo ajuste booleano (global y de centro, **Marca de agua de borrador**, en la categoría **Personalización de informes**) desactivado por defecto.
- El registro de actividad detalla ahora las acciones de negocio principales (altas, modificaciones, eliminaciones, notificaciones, comunicaciones y observaciones de partes y sanciones, cambios de datos de estudiantes y docentes —incluidos los de contacto—, y gestión de catálogos y estructura académica) en lugar de solo la ruta y el código de estado HTTP: cada entrada indica qué se creó, modificó o eliminó y, en las modificaciones, el valor antes/después de cada campo cambiado. Los borrados en cascada solo generan la entrada de la acción principal.
- El detalle de un parte y el de una sanción muestran ahora un botón **Notificar** junto a los de editar y eliminar mientras estén pendientes de notificar a la familia (y, en el caso del parte, no esté prescrito), visible solo para quien tenga permiso para registrar esa comunicación.
- Al crear o editar una sanción, y al notificar uno o varios partes, se muestran también las conductas contrarias seleccionadas del parte junto al resto de su información; la pantalla de notificar un parte concreto muestra además el docente que lo registró.
- Avisos por correo electrónico sobre los partes y sanciones: parte registrado, notificado a la familia, modificado, eliminado, prescrito o incorporado a una sanción, y sanción notificada a la familia. Cada tipo de aviso tiene su propio ajuste (global y de centro) para elegir si se envía a nadie, al docente que registró el elemento, al tutor/a de grupo o a ambos; están desactivados por defecto. Además, la comisión de convivencia puede recibir un aviso cuando un parte queda notificado a la familia y todavía puede ser sancionado.
- Una tarea programada diaria prescribe automáticamente los partes que llevan sin notificar a la familia más días de los indicados en el nuevo ajuste **Días para la prescripción automática** (global y de centro, 0-365, 14 por defecto; 0 desactiva la prescripción automática), contados desde la fecha en la que ocurrió el incidente. Si el aviso de «Parte prescrito» está activado, se envía igual que si lo hubiera marcado un administrador, pero sin nombrar a ningún docente como autor.
- Otra tarea programada diaria avisa con antelación de los partes próximos a prescribir: cada docente que puede notificar partes recibe un único correo diario con los que le corresponden y prescribirán en el número de días indicado en el nuevo ajuste **Aviso de prescripción próxima** (global, de centro y de docente, 0-365, 7 por defecto; 0 lo desactiva) o menos. No se envía en el momento en que un parte se marca como prescrito ni incluye partes ya prescritos.
- Nuevo **registro de avisos por correo**, visible desde la card **Registro de avisos por correo** del hub de centro educativo (solo administradores): historial paginado y filtrable (por texto libre, tipo de evento, resultado y rango de fechas, con accesos rápidos a las últimas 24 horas, la última semana o el último mes) de cada aviso enviado sobre partes y sanciones, con su resultado —entregado o fallido, con el motivo del error en este último caso—. Se activa o desactiva con el nuevo ajuste **Registrar los avisos por correo** (global y de centro, activado por defecto).
- Las entradas del registro de avisos por correo se purgan ahora automáticamente cada semana, igual que el registro de actividad, según el número de días indicado en el nuevo ajuste **Retención de los registros** (exclusivamente global, 0-3650, 90 por defecto; 0 desactiva la purga). Este mismo ajuste sustituye a la antigua variable de entorno `APP_LOG_RETENTION_DAYS`, que controlaba solo la retención del registro de actividad.
- La pestaña **Notificaciones pendientes** muestra ahora, al principio, un listado de estudiantes con partes pendientes de notificar (los que el docente puede notificar, ordenados de más a menos partes); al hacer clic se abre la pantalla **Notificar partes**, donde se marcan (todos por defecto) los partes a comunicar, con el detalle completo de cada uno —incluidas sus observaciones, desplegable— para rellenar una sola vez los datos de la comunicación y aplicarlos a todos los seleccionados. La pantalla de notificar un solo parte muestra también ahora todo su detalle y observaciones. Los tres listados de la pestaña (estudiantes, partes y sanciones pendientes) paginan.
- La sección **Notificaciones** incorpora una pestaña de **Historial de notificaciones** con el listado paginado y filtrable (por alumno, grupo o docente que realizó la comunicación, tipo de elemento y resultado) de todas las comunicaciones registradas en el curso académico activo sobre los partes y sanciones del docente o de los grupos que tutoriza; los administradores, la comisión de convivencia y orientación ven el historial completo del centro.
- Las observaciones de un parte pueden editarse o eliminarse: el docente que las registró puede modificarlas o eliminarlas durante la hora siguiente a su creación, y los administradores pueden editarlas o eliminarlas en cualquier momento. Solo un administrador puede corregir la fecha y hora de una observación ya registrada; el resto del profesorado solo puede editar el texto.
- El detalle de un parte permite añadir observaciones (anotaciones con la fecha/hora actual —no editable—, el docente que las registra y un texto que admite formato enriquecido) a cualquier docente o administrador con acceso al parte; se muestran en orden cronológico inverso justo antes del historial de comunicaciones.
- Al registrar una comunicación sobre un parte o una sanción se muestran ahora los datos de contacto completos del alumno (tutores legales, teléfonos y observaciones): los ve quien tenga esa visibilidad en la ficha del alumno (equipo directivo, comisión de convivencia, orientación y tutores/as del grupo) y, además, siempre el propio docente que registró el parte o la sanción.
- El detalle de un parte y el de una sanción muestran ahora, al final de la página, el historial completo de comunicaciones con la familia (fecha, método, docente, resultado y observaciones), el mismo que ya se veía al registrar una notificación.
- Las listas de conductas (formularios de parte) y de medidas (formularios de sanción) incorporan un filtro de texto local que oculta al instante las casillas que no coinciden (sin distinguir mayúsculas ni tildes) y un contador de elementos seleccionados junto al título de la sección. Además, los bloques de conductas graves se destacan con un recuadro rojo para localizarlos de un vistazo.
- La paleta de búsqueda (Ctrl+K / ⌘K) incluye ahora un grupo de **Acciones** con accesos directos a «Nuevo parte», «Ir a notificaciones» y «Cambiar de curso». Las acciones aparecen al abrir la paleta y se filtran al escribir, sin distinguir mayúsculas ni tildes.
- Nueva ficha del alumno con sus datos básicos, contadores de partes y sanciones, el historial cronológico de convivencia con el estado de notificación de cada elemento y accesos directos para registrar un nuevo parte o una nueva sanción con el alumno ya seleccionado. Se llega a ella desde el buscador global, el listado y el detalle de partes, el detalle de sanciones y la lista «Alumnado con más partes» del inicio. Los datos de contacto de la familia solo se muestran al equipo directivo, comisión de convivencia, orientación y tutores/as del grupo.
- El listado de sanciones incorpora búsqueda por alumno o grupo, filtros de «vigentes hoy» y «pendientes de notificar», paginación y una columna con el estado de la notificación a la familia, con enlace directo para registrar la comunicación.
- Al registrar un parte, una pantalla de confirmación muestra los partes creados (uno por alumno) con acceso directo para notificar a la familia, crear otro parte o volver al listado.
- Nuevos perfiles especiales de centro, asignables desde la card **Perfiles** del hub de centro educativo: **Comisión de convivencia** (acceso a todos los partes del centro y permiso para registrar sanciones de cualquier estudiante, con los mismos permisos que un administrador de centro) y **Orientador/a** (visibilidad de todos los partes y sanciones del centro, sin permiso para modificarlos si no los ha registrado).
- Importación y exportación en JSON de conductas contrarias, medidas disciplinarias y métodos de comunicación, con opción de vaciar las categorías o los métodos existentes antes de importar (los métodos con comunicaciones registradas se conservan aunque se marque esta opción).
- El calendario muestra las sanciones con fecha del curso académico activo como barras, con el nombre del alumno, su grupo y el detalle de la sanción; el color de cada barra depende del grupo. Solo se muestran los días lectivos (lunes a viernes) y el día actual se resalta.
- Modo tablón del calendario: vista a pantalla completa con una semana (lunes a viernes) a la vez, con las sanciones agrupadas por grupo e indicando fechas de inicio y fin. Si el contenido de un día no cabe en pantalla, se desplaza automáticamente sin que el profesorado tenga que hacer scroll. La semana actual y la siguiente se alternan automáticamente con una transición suave; la duración de cada una es configurable (ajustes globales y de centro, 0-3600 segundos, 15 y 5 por defecto) y un valor de 0 en cualquiera de las dos desactiva la semana siguiente. Al activarse bloquea el resto de la aplicación en esa sesión del navegador hasta que se cierra sesión con el botón de encendido/apagado.
- Nueva sección **Notificaciones**: cola de partes y sanciones pendientes de comunicar a la familia, con registro de cada intento de comunicación (método, docente, fecha y hora, descripción y resultado) e historial completo por elemento. Un parte sin comunicación exitosa no puede incorporarse a una sanción, y una sanción sin notificar no aparece en el calendario ni en el modo tablón aunque tenga fechas de vigencia.
- Catálogo de **métodos de comunicación** configurable por centro (llamada telefónica, Pasen, correo, SMS, WhatsApp…), gestionable desde el hub de centro educativo igual que las conductas y medidas.
- Dos nuevos ajustes (global y de centro) que determinan quién puede notificar los partes y las sanciones: el docente que los registró, el tutor/a del grupo o ambos. Los administradores siempre pueden.
- Nueva sección de **Partes de convivencia** con listado filtrable, vista de detalle y edición.
- La importación de estudiantes desde el CSV de Séneca rellena también el correo electrónico de los tutores legales, además de teléfonos y observaciones.
- Los administradores pueden reasignar el docente y el estudiante de un parte ya registrado.
- Al crear o editar una sanción, cada parte asociado o disponible muestra ahora siempre la fecha y hora, el docente que lo registró y la descripción completa de lo acontecido; un desplegable permite consultar además sus observaciones (si las tiene) y su historial de notificaciones a la familia, en lugar de una descripción truncada a una línea.
- Nueva sección **Ausencias** en el menú lateral, disponible para cualquier docente: permite registrar las propias ausencias previstas (un rango de fechas) y, dentro de cada una, una o varias **actividades** —fecha (con la de inicio de la ausencia sugerida por defecto), tramo horario (filtrado dinámicamente según el día de la semana de la fecha elegida), grupo/asignatura opcional de entre los que imparte y una descripción con instrucciones en texto enriquecido— para dejar organizado el trabajo de quien cubra la clase. Cada actividad admite adjuntar ficheros (hasta 10 MB cada uno; PDF, imágenes, Word, Excel, PowerPoint, OpenDocument, texto o ZIP) con el material necesario, descargables junto a su tamaño desde el propio detalle de la actividad. Cada docente propietario puede crear, editar o eliminar sus propias ausencias, pero deja de poder modificarlas (aunque sí consultarlas) en cuanto pasa su fecha de fin; el resto del profesorado no tiene acceso a las ausencias ajenas. Los administradores globales y los de centro, en cambio, pueden registrar, editar y eliminar una ausencia de cualquier docente en cualquier momento, sin verse afectados por ese bloqueo de fecha, desde una nueva pestaña **Ausencias del centro** —filtrable por fecha de hoy, rango de fechas o docente (solo se ofrecen quienes tienen alguna ausencia registrada en el curso), y ordenada de más a menos reciente por fecha de fin— que se muestra junto a la de **Ausencias propias** cuando el administrador también pertenece al curso académico activo. La escritura queda bloqueada mientras se consulta un curso académico distinto del activo, igual que en el resto de la aplicación. Todas las altas, modificaciones y bajas quedan reflejadas en el registro de actividad.
- Los ficheros adjuntos a las actividades de una ausencia se purgan automáticamente cada semana (domingos a las 5:00), según el número de días transcurridos desde la fecha de la actividad indicado en el nuevo ajuste **Retención de adjuntos de actividades** (global y de centro, 0-3650, 7 por defecto; 0 desactiva la purga para ese ámbito). La actividad y su descripción no se eliminan: al purgar un adjunto se añade al final de la descripción una nota con la fecha, hora, nombre y tamaño del fichero eliminado, para dejar constancia de que existió.
- Nueva pestaña **Calendario de ausencias**, disponible solo para administradores junto a la de **Calendario de sanciones**: reutiliza la misma vista mensual para mostrar las ausencias del profesorado del curso activo, cada una como una barra de color con únicamente el nombre del docente.
- El modo tablón incorpora una nueva pantalla **Hoy**, con los tramos horarios del día, el profesorado de guardia y las ausencias con actividad de cada tramo (descripción y adjuntos descargables), el alumnado sancionado y un reloj en tiempo real; el tramo horario en curso se resalta automáticamente sin recargar la pantalla. Activar el tablón pide ahora confirmación (advirtiendo de que hay que cerrar sesión para salir), y aparecen botones para avanzar o retroceder de pantalla sin esperar a la rotación automática cuando hay más de una activa.
- Nueva sección **Mi tutoría** en la barra lateral, visible para cualquier docente que sea tutor de al menos un grupo en el curso académico visualizado: lista el alumnado de esos grupos —apellidos, nombre, grupo, partes totales (desglosados entre normales y graves), partes sin notificar, partes prescritos, sanciones totales y sanciones sin notificar—, con buscador por nombre/apellidos, filtro por grupo, y ordenación por cualquier columna (creciente/decreciente). Desde cada fila se accede a la ficha del alumno, donde el tutor puede editar sus datos de contacto (tutores, teléfonos y observaciones) mediante un nuevo diálogo, no disponible para el resto de docentes ni administradores de centro que no sean tutores del grupo; el cambio queda reflejado en el registro de actividad y bloqueado mientras se consulta un curso académico distinto del activo.
- Rediseño del panel de inicio: los accesos rápidos incorporan **Cumplimentar tareas** (docentes de materia, con el número de tareas de sanción pendientes) y **Registrar ausencia** (todo el profesorado que pertenece al curso activo o los administradores de centro, para anotar la propia o la de otro docente respectivamente), y las tarjetas de estadísticas dejan de duplicar la información ya visible en los propios botones (se eliminan **Estudiantes**, **Pendientes de notificar** y **Tareas de sanción pendientes** propias) e incorporan una nueva tarjeta **Partes próximos a prescribir**, calculada con los mismos ajustes de prescripción automática y aviso de prescripción próxima que ya existían. El bloque de sanciones en vigor en los grupos del docente pasa a mostrarse justo a continuación de las tarjetas de estadísticas.
- Nueva sección **Guardias**/**Mis guardias** en la barra lateral —el nombre depende de si quien accede es administrador de centro o no—, visible para cualquier docente que tenga guardia en algún tramo horario del curso académico visualizado, además de para los administradores (que ven todos los tramos, no solo los propios). Muestra, con navegación día a día y el tramo en curso resaltado igual que en el modo tablón cuando se visualiza el día de hoy, una sección por cada tramo horario con el profesorado de guardia asignado y las ausencias del día —descripción de la actividad registrada y sus adjuntos descargables cuando la hay— y, una sola vez para todo el día, el alumnado sancionado agrupado por grupo con sus tareas de sanción desplegables (materia, estado, detalle y adjuntos). Cuando hay más de un tramo ese día, o alumnado sancionado que mostrar, un grupo de botones al principio de la página permite saltar directamente a cada uno. Quien administra el centro ve además, junto al título de cada tramo, un botón para modificar sus datos —incluida la asignación de docentes de guardia— sin salir de la pantalla. Un acceso rápido a esta pantalla se añade al panel de inicio, justo después de **Registrar un parte**. Cuando hay varios adjuntos que descargar juntos, aparece un botón para bajarlos en un único `.zip`: por actividad (con más de un adjunto), por tramo horario (agrupando los de todos los docentes ausentes ese tramo en una carpeta por cada uno) y por sanción (agrupando los de todas las tareas en una carpeta por materia).
- **Tareas de sanción**: cuando una sanción lleva asociada una medida con rango de fechas (el alumno pasa un periodo fuera de su aula habitual), se genera automáticamente una tarea por cada docente y materia del grupo, para que cada uno deje constancia del trabajo asignado —texto enriquecido y adjuntos, hasta 10 MB cada uno con el mismo catálogo de tipos admitidos que en las actividades de ausencia— o indique explícitamente que no procede. Cada docente ve y cumplimenta únicamente sus propias tareas desde una nueva sección **Tareas de sanción** en la barra lateral, sin acceso a la pantalla completa de la sanción; el tutor, la comisión de convivencia y los administradores hacen el seguimiento desde un nuevo bloque en el detalle de la sanción y un nuevo filtro «Solo con tareas pendientes» en el listado. El panel de inicio muestra un contador a cada lado: tareas propias pendientes para el docente de materia, y sanciones con alguna materia sin cumplimentar para tutor/comisión/administración. Un administrador con permiso de edición sobre la sanción puede **refrescar las materias** de sus tareas para que reflejen la oferta docente actual del grupo, con una vista previa de altas y bajas (avisando si alguna baja tiene datos ya cumplimentados) antes de confirmar. Los adjuntos de las tareas se purgan automáticamente cada semana (domingos a las 5:30) según el nuevo ajuste **Retención de adjuntos de tareas de sanción** (global y de centro, 0-3650, 30 por defecto), contando desde el fin de la sanción. Dos avisos por correo nuevos, activables en **Avisos por correo**: al asignar tareas nuevas a un docente, y un recordatorio diario configurable (**Días de antelación del recordatorio de tareas de sanción**, 0-365, 3 por defecto) mientras queden pendientes cerca de empezar la sanción. El listado de sanciones muestra, junto a cada una con tareas generadas, una columna con el ratio de tareas cumplimentadas sobre el total (por ejemplo «3/6»), resaltando sutilmente la fila mientras queden pendientes.
- Nueve **fichas de referencia rápida** en PDF: ocho para el profesorado (una por función básica —registrar un parte, notificar un parte, registrar una ausencia, cumplimentar tareas de sanción, registrar una sanción, la sección «Mis guardias», editar los datos de contacto de un estudiante desde la tutoría de grupo y añadir la app a la pantalla de inicio—) con capturas de tamaño móvil pensadas para consultarse desde el teléfono, más una para el equipo directivo sobre cómo configurar un curso académico nuevo, con capturas de escritorio. Se generan junto al manual y la presentación (`make docs`/`make cheatsheets`) y se publican en cada release junto a ellos.
- Los formularios para adjuntar ficheros (actividades de ausencia y tareas de sanción) permiten ahora arrastrar y soltar los ficheros desde el explorador, además de seleccionarlos con el buscador habitual del sistema; cada fichero añadido se muestra en una vista previa con su nombre y tamaño, con la opción de quitarlo antes de enviar el formulario, y se avisa al momento si alguno supera el tamaño máximo de 10 MB. Este límite sigue siendo por fichero, no por el conjunto de todos los adjuntos: se han revisado los límites de subida de PHP para que puedan enviarse varios ficheros sin toparse con un error de envío ajeno al tamaño de cada uno.

### Changed

- La relación entre grupos y cursos pasa de **muchos-a-muchos a muchos-a-uno**: cada grupo pertenece ahora a un único curso. La oferta formativa muestra los cursos en la columna izquierda y los grupos del curso seleccionado en la derecha, sin casillas de verificación de pertenencia múltiple. Se eliminan las entidades `Programme` y `ProgrammeYear`, que duplicaban esta estructura (migración v29).
- La vista previa de importación de estudiantes, cuando un mismo nombre de grupo aparece en el CSV bajo cursos distintos, muestra ahora **botones de radio** (uno por cada curso detectado) y un campo de texto editable al final; al seleccionar un radio se copia su valor en el campo para que pueda personalizarse. Antes se usaba un desplegable de búsqueda Tom Select.
- Los desplegables de selección de tutores y docentes en la oferta formativa muestran el mensaje **«Sin resultados»** en español cuando ningún docente coincide con el texto buscado.
- En el formulario de un docente, las opciones **Administrador global** y **Cuenta activa** pasan de casillas de verificación a pares de botones de radio en cajas, igual que el resto de opciones del formulario (modo de acceso y cambio de contraseña obligatorio).
- Los PDF de partes y sanciones tienen un aspecto más formal: los datos principales (alumno, grupo, docente, fecha y estado) se muestran en una única tabla al comienzo, y las conductas contrarias marcadas se agrupan por categoría en columnas, con el nombre de la categoría como cabecera y las conductas concretas debajo en una lista.
- La pantalla de ajustes agrupa ahora cada ajuste en secciones por categoría (Visualización, Correo electrónico, Modo tablón, Notificaciones a familias y Avisos por correo), en lugar de mostrarlos todos en un único listado alfabético.
- El modo tablón del calendario incorpora un tema claro, además del oscuro existente, y un nuevo ajuste (global y de centro) para elegir entre claro, oscuro o según el sistema; este último sigue en todo momento la preferencia de color del navegador o sistema operativo, incluidos los cambios mientras la pantalla permanece abierta. El tema claro es ahora el valor por defecto.
- Una vez notificado a la familia, un parte solo puede ser modificado por un administrador; el docente que lo registró conserva la posibilidad de añadir y gestionar sus propias observaciones, pero ya no puede editar los datos del parte. Las sanciones ya funcionaban así: su edición y eliminación han sido siempre exclusivas de administradores y comisión de convivencia.
- Los mensajes de error permanecen en pantalla hasta que se cierran manualmente; los de éxito siguen desapareciendo solos a los cuatro segundos.
- Las migas de navegación de las páginas de detalle, creación y edición (partes, sanciones y notificaciones) muestran ahora la página actual («Parte #123», «Nueva sanción»…) en lugar de terminar en una flecha sin destino.
- El título de la pestaña del navegador en el detalle y la edición de partes y sanciones incluye ahora el número de parte y el nombre del alumno, lo que facilita distinguir varias pestañas abiertas y mejora el historial de navegación.
- En pantallas pequeñas, el listado de sanciones, la selección de alumno para una sanción y las colas de notificaciones muestran ahora cada fila como una tarjeta con sus etiquetas, igual que el listado de partes, en lugar de una tabla con desplazamiento horizontal.
- La búsqueda de alumnado al registrar una sanción filtra en vivo mientras se escribe, sin botón «Filtrar», igual que el resto de listados.
- Los formularios de parte y de sanción marcan con un asterisco los campos obligatorios y muestran cada error de validación junto al campo afectado, llevando el foco al primero; el resumen de errores de la parte superior se mantiene.
- Tras registrar una comunicación se vuelve a la cola de notificaciones, lista para continuar con el siguiente elemento pendiente. Además, la cola muestra la antigüedad de cada elemento, destacada en ámbar a partir de tres días y en rojo a partir de siete.
- La tarjeta «Pendientes de notificar» del inicio se muestra siempre: en verde cuando no queda nada por comunicar a las familias.
- El listado de partes muestra ahora una pastilla de notificación (notificado/pendiente, con enlace directo para notificar) y otra de prescrito («no sancionable»); los partes prescritos se atenúan visualmente en toda la fila para indicar que ya no tienen efecto real.
- En el registro y edición de un parte, la casilla "El estudiante fue expulsado del aula" se
  sustituye por un selector Sí/No de dos tarjetas, más cómodo de usar en móvil.
- La pantalla **Centro educativo › Docentes › Grupos asignados** pasa a llamarse **Materias**: en vez de un formulario con casillas para marcar grupos, permite añadir, modificar o eliminar cada asignación de grupo y materia una a una. Al modificar o eliminar una asignación con tareas de sanción cumplimentadas o con adjuntos, se bloquea esa operación en concreto y se muestra un aviso indicando que hay que usar «Refrescar materias» desde la sanción correspondiente, igual que ya ocurre al quitar la asignación desde la oferta formativa.
- Los despliegues Docker y binario nativo (Caddy) cachean ahora los ficheros estáticos igual que ya hacía Apache/Plesk: `public/assets/` indefinidamente (el hash de contenido en el nombre de fichero garantiza que un cambio siempre genera una URL nueva) y `public/static/` (favicons, logo, fuentes, sin hash) durante 30 días.

### Fixed

- El texto de ayuda del campo de alumnado del formulario de parte repetía el placeholder; ahora explica que se crea un parte por cada alumno seleccionado.
- El acceso rápido «Nuevo parte» del inicio solo aparecía al equipo directivo; ahora lo ve todo el profesorado, como describe el manual.
- SQLite ahora aplica las restricciones de clave ajena (`PRAGMA foreign_keys = ON`), por lo que los borrados en cascada (por ejemplo, al eliminar una categoría) ya no dejan registros huérfanos en despliegues con SQLite.
- El inicio y la pantalla de «Nueva sanción» ya no muestran alumnado con 0 partes pendientes de sanción: un parte solo cuenta como pendiente de sanción cuando la familia ya ha sido notificada, y ese mismo criterio se aplicaba al recuento pero no al filtro que decidía qué alumnado aparecía en la lista.
- El inicio, el listado de partes y sanciones, y la sección de notificaciones (pendientes e historial) mostraban datos de otros cursos académicos del mismo centro además del curso que se está viendo; ahora cada curso académico queda completamente aislado de los demás en estas pantallas.
- La ficha del alumno mostraba el historial de convivencia y los grupos de todos los cursos académicos del centro en lugar de limitarse al curso que se está viendo; ahora queda aislada igual que el resto de listados.
- El modo tablón bloqueaba la descarga de los adjuntos de una actividad de ausencia al no tener su ruta en la lista de rutas permitidas dentro de esa sesión.
- La descarga de un adjunto con nombre de archivo no-ASCII fallaba por falta de un nombre de reserva válido para la cabecera `Content-Disposition`.
- Al crear un centro educativo desde el panel de administración no se sembraba su catálogo de ubicaciones por defecto, a diferencia del resto de catálogos (conductas contrarias, medidas y vías de comunicación).
- La barra lateral no reflejaba las secciones del centro educativo al crear el primer centro (cuando no existía ninguno) ni al eliminar el centro activo mientras quedaba otro registrado: en ambos casos había que navegar manualmente a otra pantalla para que se seleccionara el centro correspondiente.
- El editor de tramos horarios no mostraba el mensaje de confirmación al guardar o eliminar un cambio hasta navegar a otra pantalla, por tratarse de una recarga parcial (LiveComponent) que no repinta el resto de la página.
- La administración (global o de centro) no podía editar las tareas de guardia de sanciones asignadas a otro docente; ahora tiene acceso completo aunque no sean propias, igual que ya ocurría con las actividades de una ausencia.

### Security

- El contenido HTML introducido con el editor de texto enriquecido (descripción de los partes, detalle y motivo de las sanciones) se sanea ahora al mostrarse, eliminando cualquier etiqueta o atributo no permitido.
- Actualizadas dependencias con avisos de seguridad: `symfony/ux-icons` 3.2.0 (CVE-2026-55877) y `lodash-es` 4.18.0.
- Nueva cabecera `X-Frame-Options: DENY` en las configuraciones de Caddy incluidas (Docker y binario nativo) para impedir el embebido de la aplicación en iframes de terceros.
- Nuevo `robots.txt` que excluye toda la aplicación de los indexadores de los buscadores.

