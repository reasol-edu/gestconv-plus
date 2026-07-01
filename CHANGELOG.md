# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Importación y exportación en JSON de conductas contrarias y medidas disciplinarias, con opción de vaciar las categorías existentes antes de importar.
- El calendario muestra las sanciones con fecha del curso académico activo como barras, con el nombre del alumno, su grupo y el detalle de la sanción; el color de cada barra depende del grupo. Solo se muestran los días lectivos (lunes a viernes) y el día actual se resalta.
- Modo tablón del calendario: vista a pantalla completa con una semana (lunes a viernes) a la vez, con las sanciones agrupadas por grupo e indicando fechas de inicio y fin. Si el contenido de un día no cabe en pantalla, se desplaza automáticamente sin que el profesorado tenga que hacer scroll. La semana actual y la siguiente se alternan automáticamente con una transición suave; la duración de cada una es configurable (ajustes globales y de centro, 0-3600 segundos, 15 y 5 por defecto) y un valor de 0 en cualquiera de las dos desactiva la semana siguiente. Al activarse bloquea el resto de la aplicación en esa sesión del navegador hasta que se cierra sesión con el botón de encendido/apagado.

### Fixed

- SQLite ahora aplica las restricciones de clave ajena (`PRAGMA foreign_keys = ON`), por lo que los borrados en cascada (por ejemplo, al eliminar una categoría) ya no dejan registros huérfanos en despliegues con SQLite.

