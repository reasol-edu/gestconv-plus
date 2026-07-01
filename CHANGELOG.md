# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Importación y exportación en JSON de conductas contrarias y medidas disciplinarias, con opción de vaciar las categorías existentes antes de importar.

### Fixed

- SQLite ahora aplica las restricciones de clave ajena (`PRAGMA foreign_keys = ON`), por lo que los borrados en cascada (por ejemplo, al eliminar una categoría) ya no dejan registros huérfanos en despliegues con SQLite.

