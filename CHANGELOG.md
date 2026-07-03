# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- El listado de sanciones incorpora búsqueda por alumno o grupo, filtros de «vigentes hoy» y «pendientes de notificar», paginación y una columna con el estado de la notificación a la familia, con enlace directo para registrar la comunicación.
- Al registrar un parte, una pantalla de confirmación muestra los partes creados (uno por alumno) con acceso directo para notificar a la familia, crear otro parte o volver al listado.
- Nuevos perfiles especiales de centro, asignables desde la card **Perfiles** del hub de centro educativo: **Comisión de convivencia** (acceso a todos los partes del centro y permiso para registrar sanciones de cualquier estudiante, con los mismos permisos que un administrador de centro) y **Orientador/a** (visibilidad de todos los partes y sanciones del centro, sin permiso para modificarlos si no los ha registrado).
- Importación y exportación en JSON de conductas contrarias y medidas disciplinarias, con opción de vaciar las categorías existentes antes de importar.
- El calendario muestra las sanciones con fecha del curso académico activo como barras, con el nombre del alumno, su grupo y el detalle de la sanción; el color de cada barra depende del grupo. Solo se muestran los días lectivos (lunes a viernes) y el día actual se resalta.
- Modo tablón del calendario: vista a pantalla completa con una semana (lunes a viernes) a la vez, con las sanciones agrupadas por grupo e indicando fechas de inicio y fin. Si el contenido de un día no cabe en pantalla, se desplaza automáticamente sin que el profesorado tenga que hacer scroll. La semana actual y la siguiente se alternan automáticamente con una transición suave; la duración de cada una es configurable (ajustes globales y de centro, 0-3600 segundos, 15 y 5 por defecto) y un valor de 0 en cualquiera de las dos desactiva la semana siguiente. Al activarse bloquea el resto de la aplicación en esa sesión del navegador hasta que se cierra sesión con el botón de encendido/apagado.
- Nueva sección **Notificaciones**: cola de partes y sanciones pendientes de comunicar a la familia, con registro de cada intento de comunicación (método, docente, fecha y hora, descripción y resultado) e historial completo por elemento. Un parte sin comunicación exitosa no puede incorporarse a una sanción, y una sanción sin notificar no aparece en el calendario ni en el modo tablón aunque tenga fechas de vigencia.
- Catálogo de **métodos de comunicación** configurable por centro (llamada telefónica, Pasen, correo, SMS, WhatsApp…), gestionable desde el hub de centro educativo igual que las conductas y medidas.
- Dos nuevos ajustes (global y de centro) que determinan quién puede notificar los partes y las sanciones: el docente que los registró, el tutor/a del grupo o ambos. Los administradores siempre pueden.
- Nueva sección de **Partes de convivencia** con listado filtrable, vista de detalle y edición.
- La importación de estudiantes desde el CSV de Séneca rellena también el correo electrónico de los tutores legales, además de teléfonos y observaciones.
- Los administradores pueden reasignar el docente y el estudiante de un parte ya registrado.

### Changed

- Tras registrar una comunicación se vuelve a la cola de notificaciones, lista para continuar con el siguiente elemento pendiente. Además, la cola muestra la antigüedad de cada elemento, destacada en ámbar a partir de tres días y en rojo a partir de siete.
- La tarjeta «Pendientes de notificar» del inicio se muestra siempre: en verde cuando no queda nada por comunicar a las familias.

### Fixed

- El texto de ayuda del campo de alumnado del formulario de parte repetía el placeholder; ahora explica que se crea un parte por cada alumno seleccionado.
- El acceso rápido «Nuevo parte» del inicio solo aparecía al equipo directivo; ahora lo ve todo el profesorado, como describe el manual.
- SQLite ahora aplica las restricciones de clave ajena (`PRAGMA foreign_keys = ON`), por lo que los borrados en cascada (por ejemplo, al eliminar una categoría) ya no dejan registros huérfanos en despliegues con SQLite.

### Security

- El contenido HTML introducido con el editor de texto enriquecido (descripción de los partes, detalle y motivo de las sanciones) se sanea ahora al mostrarse, eliminando cualquier etiqueta o atributo no permitido.
- Actualizadas dependencias con avisos de seguridad: `symfony/ux-icons` 3.2.0 (CVE-2026-55877) y `lodash-es` 4.18.0.
- Nueva cabecera `X-Frame-Options: DENY` en las configuraciones de Caddy incluidas (Docker y binario nativo) para impedir el embebido de la aplicación en iframes de terceros.
- Nuevo `robots.txt` que excluye toda la aplicación de los indexadores de los buscadores.

