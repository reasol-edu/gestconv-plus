# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- La pestaña **Notificaciones pendientes** muestra ahora, al principio, un listado de estudiantes con partes pendientes de notificar (los que el docente puede notificar, ordenados de más a menos partes); al hacer clic se abre la pantalla **Notificar partes**, donde se marcan (todos por defecto) los partes a comunicar, con el detalle completo de cada uno —incluidas sus observaciones, desplegable— para rellenar una sola vez los datos de la comunicación y aplicarlos a todos los seleccionados. La pantalla de notificar un solo parte muestra también ahora todo su detalle y observaciones. Los tres listados de la pestaña (estudiantes, partes y sanciones pendientes) paginan.
- La sección **Notificaciones** incorpora una pestaña de **Historial de notificaciones** con el listado paginado y filtrable (por alumno, grupo o docente que realizó la comunicación, tipo de elemento y resultado) de todas las comunicaciones registradas en el curso académico activo sobre los partes y sanciones del docente o de los grupos que tutoriza; los administradores, la comisión de convivencia y orientación ven el historial completo del centro.
- Las observaciones de un parte pueden editarse o eliminarse: el docente que las registró puede modificarlas o eliminarlas durante la hora siguiente a su creación, y los administradores pueden editarlas o eliminarlas en cualquier momento.
- El detalle de un parte permite añadir observaciones (anotaciones con fecha/hora, docente y un texto que admite formato enriquecido) a cualquier docente o administrador con acceso al parte; se muestran en orden cronológico inverso justo antes del historial de comunicaciones.
- Al registrar una comunicación sobre un parte o una sanción se muestran ahora los datos de contacto completos del alumno (tutores legales, teléfonos y observaciones): los ve quien tenga esa visibilidad en la ficha del alumno (equipo directivo, comisión de convivencia, orientación y tutores/as del grupo) y, además, siempre el propio docente que registró el parte o la sanción.
- El detalle de un parte y el de una sanción muestran ahora, al final de la página, el historial completo de comunicaciones con la familia (fecha, método, docente, resultado y observaciones), el mismo que ya se veía al registrar una notificación.
- Las listas de conductas (formularios de parte) y de medidas (formularios de sanción) incorporan un filtro de texto local que oculta al instante las casillas que no coinciden (sin distinguir mayúsculas ni tildes) y un contador de elementos seleccionados junto al título de la sección. Además, los bloques de conductas graves se destacan con un recuadro rojo para localizarlos de un vistazo.
- La paleta de búsqueda (Ctrl+K / ⌘K) incluye ahora un grupo de **Acciones** con accesos directos a «Nuevo parte», «Ir a notificaciones» y «Cambiar de curso». Las acciones aparecen al abrir la paleta y se filtran al escribir, sin distinguir mayúsculas ni tildes.
- Nueva ficha del alumno con sus datos básicos, contadores de partes y sanciones, el historial cronológico de convivencia con el estado de notificación de cada elemento y accesos directos para registrar un nuevo parte o una nueva sanción con el alumno ya seleccionado. Se llega a ella desde el buscador global, el listado y el detalle de partes, el detalle de sanciones y la lista «Alumnado con más partes» del inicio. Los datos de contacto de la familia solo se muestran al equipo directivo, comisión de convivencia, orientación y tutores/as del grupo.
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

- Una vez notificado a la familia, un parte solo puede ser modificado por un administrador; el docente que lo registró conserva la posibilidad de añadir y gestionar sus propias observaciones, pero ya no puede editar los datos del parte. Las sanciones ya funcionaban así: su edición y eliminación han sido siempre exclusivas de administradores y comisión de convivencia.
- Los mensajes de error permanecen en pantalla hasta que se cierran manualmente; los de éxito siguen desapareciendo solos a los cuatro segundos.
- Las migas de navegación de las páginas de detalle, creación y edición (partes, sanciones y notificaciones) muestran ahora la página actual («Parte #123», «Nueva sanción»…) en lugar de terminar en una flecha sin destino.
- El título de la pestaña del navegador en el detalle y la edición de partes y sanciones incluye ahora el número de parte y el nombre del alumno, lo que facilita distinguir varias pestañas abiertas y mejora el historial de navegación.
- En pantallas pequeñas, el listado de sanciones, la selección de alumno para una sanción y las colas de notificaciones muestran ahora cada fila como una tarjeta con sus etiquetas, igual que el listado de partes, en lugar de una tabla con desplazamiento horizontal.
- La búsqueda de alumnado al registrar una sanción filtra en vivo mientras se escribe, sin botón «Filtrar», igual que el resto de listados.
- Los formularios de parte y de sanción marcan con un asterisco los campos obligatorios y muestran cada error de validación junto al campo afectado, llevando el foco al primero; el resumen de errores de la parte superior se mantiene.
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

