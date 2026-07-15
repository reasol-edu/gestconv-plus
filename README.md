<p align="center">
  <img src="public/static/logo.svg" alt="GestConv+" width="120">
</p>

<h1 align="center">GestConv+</h1>

<p align="center">
  Plataforma web para gestionar las conductas contrarias a la convivencia en centros de ESO, Bachillerato y Formación Profesional
</p>

<p align="center">
  <strong>v1.0.0</strong> &nbsp;·&nbsp;
  <a href="https://reasol-edu.github.io/gestconv-plus/">Documentación</a> &nbsp;·&nbsp;
  <a href="CHANGELOG.md">Cambios</a> &nbsp;·&nbsp;
  <a href="CONTRIBUTING.md">Contribuir</a> &nbsp;·&nbsp;
  <a href="http://www.gnu.org/licenses/agpl.html">AGPL-3.0</a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/licencia-AGPL--3.0-blue" alt="Licencia AGPL-3.0">
  <img src="https://img.shields.io/badge/PHP-8.4+-777bb4" alt="PHP 8.4+">
  <img src="https://img.shields.io/badge/Symfony-8-black" alt="Symfony 8">
</p>

---

<p align="center">
  <img src="docs/manual/img/inicio.png" alt="Panel de inicio de GestConv+ con las métricas del curso actual" width="800">
</p>

---

GestConv+ es una aplicación web desarrollada con [Symfony] para la **gestión de conductas contrarias
a la convivencia** en centros de Educación Secundaria Obligatoria, Bachillerato y Formación Profesional. Centraliza la
tramitación de partes de incidencia, el registro de sanciones y el seguimiento del historial de
convivencia de cada estudiante.

La aplicación permite registrar y gestionar de forma ágil los partes emitidos por el profesorado,
aplicar las correcciones previstas en el Reglamento de Organización y Funcionamiento (ROF) y generar
documentación oficial en PDF para notificar a las familias y al equipo directivo.

Es **multi-centro**: un mismo servidor puede alojar varios centros educativos con datos completamente
separados. Cada docente accede únicamente a los datos del centro que tiene asignado, y los
administradores globales pueden gestionar todos los centros desde la sección **Administración**.

Consulta [CONTRIBUTING.md](CONTRIBUTING.md) para la guía de contribución y [CHANGELOG.md](CHANGELOG.md)
para el historial de cambios.

---

## ¿Solo quieres probarlo?

¿No eres una persona técnica y solo quieres ver cómo funciona GestConv+ en tu propio ordenador? No
necesitas instalar nada ni saber de informática. Entra en la
**[página de descargas](https://github.com/reasol-edu/gestconv-plus/releases)**, descarga el archivo de tu
sistema (Windows, Mac o Linux), descomprímelo y haz doble clic en el script de demostración
(`demo.bat` en Windows, `demo.command` en Mac, `demo.sh` en Linux). En unos segundos tendrás la
aplicación funcionando con datos de ejemplo: abre tu navegador en **<http://localhost:8080>** y entra
con el usuario `admin` y la contraseña `admin`.

---

## Documentación

La documentación detallada vive en el **[manual de GestConv+](docs/manual/)** (`docs/manual/`), que es la
fuente de referencia principal. Cubre instalación, roles y permisos, el flujo de trabajo completo, la referencia
de cada pantalla, notificaciones, ajustes, comandos de consola y despliegue.

La versión web navegable de la última versión estable está publicada en
**<https://reasol-edu.github.io/gestconv-plus/>**.

El manual se redacta en Markdown y se genera en dos formatos con el mismo contenido:

- **PDF**: `make docs-pdf` → `docs/manual/gestconv-plus-manual.pdf`.
- **Web navegable** (con buscador): `make docs-web` / `make docs-serve`.

Las versiones publicadas del manual (PDF), la presentación (PDF) y la web navegable (ZIP) se generan
automáticamente en cada release y están disponibles, con el número de versión en el nombre
(`gestconv-plus-manual-vX.Y.Z.pdf`, `gestconv-plus-presentacion-vX.Y.Z.pdf`, `gestconv-plus-manual-web-vX.Y.Z.zip`), entre
los activos del [GitHub Release](https://github.com/reasol-edu/gestconv-plus/releases). Los comandos `make`
anteriores sirven para previsualización local.

Capítulos:

| Capítulo | Contenido |
|----------|-----------|
| Introducción | Qué es GestConv+ y cómo usar el manual |
| Instalación y requisitos | Modos de despliegue y requisitos |
| Primeros pasos | Configurar el centro y el curso académico |
| Roles y permisos | Perfiles y tabla de permisos |
| Flujo de trabajo | Del parte a la aplicación de la sanción |
| Secciones de la aplicación | Referencia de cada pantalla |
| Notificaciones por email | Avisos automáticos y SMTP |
| Ajustes | Configuración jerárquica |
| Comandos de consola | Administración por terminal |
| Despliegue | Docker y binario nativo |
| Operación y mantenimiento | Backups, colas, recordatorios |
| Resolución de problemas | Soluciones a las dudas más habituales |
| Glosario | Términos del manual y de la aplicación |

---

## Inicio rápido

```bash
cp .env.example .env.local            # edita APP_SECRET y DB_PASSWORD
export COMPOSE_ENV_FILES=.env.local   # Compose usará .env.local (no el .env versionado)
docker compose up -d
```

Accede a **http://localhost** con `admin` / `admin`.

Para el resto de modos de despliegue (binario nativo, Plesk, Ubuntu Server y desarrollo local) y su
configuración detallada, consulta el capítulo
[Instalación y puesta en marcha](docs/manual/01-instalacion-y-puesta-en-marcha.md) del manual.

---

## Requisitos

| Modo | Requisitos |
|------|-----------|
| Docker | Docker Engine 24+ y Docker Compose v2 |
| Binario nativo | Sin requisitos adicionales (todo incluido) |
| Desarrollo local | PHP 8.4+, Composer, PostgreSQL 16+, MySQL 8+ / MariaDB 11+ o SQLite |

---

## Desarrollo local

Requisitos: PHP 8.4+, Composer y Docker Compose (solo para la base de datos).

```bash
# 1. Clona el repositorio y copia el entorno
cp .env.example .env.local            # ajusta si es necesario
export COMPOSE_ENV_FILES=.env.local   # Compose usará .env.local

# 2. Levanta PostgreSQL con el overlay de desarrollo
docker compose -f compose.yaml -f compose.dev.yaml up -d

# 3. Instala dependencias e inicializa la base de datos
composer install
make migrate
php bin/console app:setup

# 4. Arranca el servidor de desarrollo
symfony server:start          # o: php -S localhost:8000 -t public/
```

Accede a **https://localhost:8000** (o **http://localhost:8000** con `php -S`) con `admin` / `admin`.

> **Atajo:** una vez instaladas las dependencias y la base de datos (pasos 1-3), `make dev`
> levanta los contenedores de desarrollo (PostgreSQL) y arranca `symfony serve`
> de una vez. `make dev-stop` detiene los contenedores. Requiere `.env.local` y la Symfony CLI.

> El overlay `compose.dev.yaml` (que se combina con `-f`) expone PostgreSQL en el puerto 5432 y deja los servicios `app` y `worker` tras el perfil `production`, de modo que el comando anterior solo arranca la base de datos. En producción se usa únicamente `compose.yaml` (`docker compose up -d`), que sí levanta la aplicación con FrankenPHP.

### Cargar datos de demostración

```bash
make fixtures
```

Consulta [DEMO.md](DEMO.md) para ver los usuarios, centros y escenarios disponibles.

### Ejecutar los tests

```bash
make test
```

### Análisis estático

```bash
php vendor/bin/phpstan analyse
```

### Generar la presentación

El proyecto incluye una presentación de introducción a GestConv+ en
[`docs/slides/`](docs/slides/), escrita en [Marp]. Para exportarla a PDF:

```bash
make slides
```

El comando requiere **Node.js** (usa `npx @marp-team/marp-cli`, sin instalación global) y genera
`docs/slides/gestconv-plus.pdf`. Cambiando la extensión de salida puedes obtener otros formatos
(`.pptx`, `.html`). Consulta [`docs/slides/README.md`](docs/slides/README.md) para más detalles.

### Generar el manual

El [manual de GestConv+](docs/manual/) se redacta en Markdown (`docs/manual/`) y se compila a PDF y a una
web navegable:

```bash
make docs-pdf    # PDF -> docs/manual/gestconv-plus-manual.pdf
make docs-web    # web -> docs/manual-site/
make docs-serve  # previsualización en http://127.0.0.1:8000
make docs        # PDF + web
```

El PDF requiere **pandoc** y **Node.js** (usa `npx pagedjs-cli`, el mismo motor Chromium que las slides).
La web requiere **MkDocs Material** (`pip install -r docs/manual/requirements.txt`). Consulta
[`docs/manual/README.md`](docs/manual/README.md) para más detalles.

---

## Licencia

Esta aplicación se ofrece bajo licencia [AGPL versión 3].

[Symfony]: http://symfony.com/
[Marp]: https://marp.app
[AGPL versión 3]: http://www.gnu.org/licenses/agpl.html
