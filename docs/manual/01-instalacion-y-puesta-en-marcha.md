# Instalación y puesta en marcha

!!! tip "¿Tu centro ya usa GestConv+?"
    Este capítulo es solo para quien instala y mantiene el servidor. Si la aplicación ya
    está instalada y tu centro configurado, salta directamente a
    [Preparar el curso académico](02-preparar-el-curso-academico.md).

GestConv+ puede ejecutarse de varias formas, según la infraestructura disponible y quién va a
utilizarla:

| Modo | Base de datos | Para quién | Esfuerzo |
|------|---------------|------------|----------|
| **Binario nativo** (FrankenPHP) | SQLite | Un centro, sin infraestructura | Mínimo |
| **Docker Compose** | PostgreSQL | Servidor / producción | Medio |
| **Plesk** (PHP-FPM) | MySQL/MariaDB o PostgreSQL | VPS con Plesk, sin Docker | Medio |
| **Ubuntu Server 26.04** (FrankenPHP + systemd) | PostgreSQL | VPS Ubuntu, sin Docker | Medio |
| **Desarrollo local** | PostgreSQL, MySQL/MariaDB o SQLite | Contribuir al proyecto | Para perfiles técnicos |

En binario nativo, Docker y Ubuntu Server, las **migraciones de base de datos se aplican
automáticamente** al arrancar; en Plesk y desarrollo local hay que ejecutarlas manualmente.

!!! tip "¿No tienes conocimientos técnicos?"
    Elige **Binario nativo**. La guía paso a paso en
    [Prueba rápida en tu ordenador](#prueba-rapida-en-tu-ordenador-sin-conocimientos-tecnicos)
    te lleva desde la descarga hasta la aplicación en marcha en tres pasos.

## Requisitos

| Modo | Requisitos |
|------|------------|
| Binario nativo | Sin requisitos adicionales (todo incluido) |
| Docker | Docker Engine 24+ y Docker Compose v2 |
| Plesk | PHP 8.4 FPM, extensiones estándar, MySQL 8+ / MariaDB 10.6+ (o PostgreSQL 12+), Composer, acceso SSH |
| Ubuntu Server 26.04 | Ubuntu 26.04 LTS, acceso SSH con sudo y un dominio apuntando al servidor |
| Desarrollo local | PHP 8.4+, Composer, PostgreSQL 16+, MySQL 8+ / MariaDB 11+ o SQLite |

## Prueba rápida en tu ordenador (sin conocimientos técnicos)

¿Solo quieres ver cómo funciona GestConv+ en tu propio equipo? No necesitas instalar nada ni saber de
informática. En **tres pasos** tendrás la aplicación funcionando con datos de ejemplo (centros,
profesorado, estudiantes y partes de convivencia ya creados).

### Paso 1 · Descarga el archivo de tu equipo

Entra en la **[página de descargas (Releases)](https://github.com/reasol-edu/gestconv-plus/releases)** y,
en la última versión, descarga el archivo que corresponda a tu ordenador. Los archivos descargables
están en el apartado **Assets** de la última versión:

| Tu ordenador | Archivo a descargar |
|--------------|---------------------|
| **Windows** | `gestconv-plus-…-windows-x86_64.zip` |
| **Mac con chip Apple** (M1, M2, M3, M4…) | `gestconv-plus-…-macos-arm64.tar.gz` |
| **Mac con procesador Intel** | `gestconv-plus-…-macos-x86_64.tar.gz` |
| **Linux** (PC habitual) | `gestconv-plus-…-linux-x86_64.tar.gz` |
| **Linux ARM** (p. ej. Raspberry Pi) | `gestconv-plus-…-linux-aarch64.tar.gz` |

!!! question "¿Qué Mac tengo?"
    Abre el menú Apple () arriba a la izquierda → **Acerca de este Mac**.
    Si aparece «Chip Apple M…» elige el archivo *arm64*; si aparece «Procesador Intel», el *x86_64*.

### Paso 2 · Descomprime el archivo

- **Windows / Mac:** haz doble clic en el archivo descargado; se creará una carpeta `gestconv-plus-…`.
- **Linux:** clic derecho → *Extraer aquí* (o, en una terminal, `tar xzf gestconv-plus-….tar.gz`).

### Paso 3 · Arranca con datos de demostración

Abre la carpeta que se ha creado y:

- **Windows:** haz doble clic en **`demo.bat`**.
- **Mac:** haz doble clic en **`demo.command`**.
- **Linux:** abre una terminal en la carpeta y ejecuta `./demo.sh`.

Espera unos segundos (la primera vez tarda un poco en prepararse) y abre tu navegador en
**[http://localhost:8080](http://localhost:8080)**. Entra con usuario **`admin`** y contraseña
**`admin`** — el primer inicio de sesión te pedirá establecer una nueva contraseña —, o con
cualquiera de los docentes de ejemplo (contraseña `ejemplo`; la lista completa está en el fichero
`DEMO.md` del repositorio).

??? warning "Aviso de seguridad en macOS"
    Como la aplicación no está firmada con un certificado de Apple, la primera vez macOS la bloqueará
    con un mensaje del tipo *«No se puede abrir "demo.command" porque proviene de un desarrollador no
    identificado»* (en versiones recientes, *«Apple no ha podido verificar que "demo.command" esté libre
    de software malicioso»*). Es normal. Para autorizarla:

    1. En el **Finder**, haz **clic secundario** sobre `demo.command` (clic con el botón derecho del
       ratón, o mantén pulsada la tecla **Control** ⌃ mientras haces clic) y elige **Abrir** en el menú
       contextual.
    2. En el cuadro de diálogo que aparece, vuelve a pulsar **Abrir** para confirmar.

    Si usas **macOS Sequoia (15) o posterior** y el bloqueo no te ofrece la opción de abrir, ve al menú
    Apple () → **Ajustes del Sistema** → **Privacidad y seguridad**, baja hasta la sección **Seguridad**
    y pulsa **Abrir igualmente** junto al aviso sobre `demo.command`; confírmalo con **Touch ID** o tu
    contraseña de administrador.

    Solo hace falta hacerlo la primera vez: después, `demo.command` se abrirá con normalidad.

Para **detener** la aplicación, cierra la ventana negra (terminal) que se abrió, o pulsa `Ctrl + C` en
ella.

!!! danger "El modo demostración borra los datos existentes"
    Cada vez que arrancas con `demo.*` se borran todos los datos anteriores y se cargan los de ejemplo.
    Para empezar con una instalación vacía y real, usa los scripts `start.*` en vez de `demo.*`.

## Ejecución como binario nativo

El modo binario nativo está pensado para instalaciones sencillas sin Docker. Incluye un ejecutable de
[FrankenPHP](https://frankenphp.dev) que embebe el servidor web y PHP, y usa
[SQLite](https://www.sqlite.org) como base de datos, por lo que no necesita ningún software adicional
instalado en el sistema.

### Descarga

Descarga el paquete correspondiente a tu sistema operativo desde la página de releases del proyecto y
descomprímelo. El paquete contiene:

```
gestconv-plus/
├── app/            ← código de la aplicación
├── data/           ← generado automáticamente (BD, caché, secretos)
├── frankenphp      ← ejecutable (frankenphp.exe en Windows)
├── Caddyfile       ← configuración del servidor web
├── start.sh        ← script de arranque (Linux / macOS)
├── start.bat       ← script de arranque (Windows CMD)
├── start.ps1       ← script de arranque (Windows PowerShell)
├── demo.sh         ← arranque cargando datos de demostración (Linux / macOS)
├── demo.command    ← igual que demo.sh, abrible con doble clic (solo macOS)
├── demo.bat        ← arranque cargando datos de demostración (Windows CMD)
└── demo.ps1        ← arranque cargando datos de demostración (Windows PowerShell)
```

### Primer arranque

**Linux / macOS:**

```bash
chmod +x frankenphp start.sh
./start.sh
```

**Windows (CMD):**

```bat
start.bat
```

**Windows (PowerShell):**

```powershell
.\start.ps1
```

Se puede especificar un puerto distinto al predeterminado (8080):

```bash
./start.sh 9000          # Linux / macOS
start.bat 9000           # Windows CMD
.\start.ps1 -Port 9000   # Windows PowerShell
```

La primera vez que se inicia, el script realiza automáticamente:

1. Genera un `APP_SECRET` aleatorio y lo guarda en `data/.secret`.
2. Crea la base de datos SQLite en `data/gestconv-plus.db`.
3. Ejecuta las migraciones.
4. Crea el usuario administrador inicial (`admin` / `admin`), sin ningún centro educativo.
5. Precalienta la caché de Symfony y lanza el *worker* de envío de correos en segundo plano.

La aplicación queda disponible en `http://localhost:8080` (o el puerto indicado). El siguiente
paso es dar de alta el centro real: ver
[Configuración inicial del centro educativo](#configuracion-inicial-del-centro-educativo).

!!! danger "Cambia la contraseña por defecto"
    El usuario inicial `admin` / `admin` se crea solo para el primer acceso: la propia aplicación
    obliga a establecer una contraseña nueva en el primer inicio de sesión y no deja usar ninguna
    otra pantalla hasta hacerlo (salvo que la inicialización se hiciera con
    `--no-force-password-change`, ver [app:setup](#appsetup)). Elige una contraseña robusta y, en
    instalaciones reales, crea además tu propio administrador con
    [`app:create-admin`](#appcreate-admin).
    Nunca dejes `admin` / `admin` en una instalación accesible por red.

### Arranque con datos de demostración

Para probar la aplicación con datos de ejemplo (centros, docentes, estudiantes y partes precargados),
usa los scripts `demo.*` en lugar de `start.*`. Son equivalentes al arranque normal pero cargan los
fixtures automáticamente (`LOAD_FIXTURES=true`):

```bash
./demo.sh                 # Linux / macOS
demo.bat                  # Windows CMD
.\demo.ps1                # Windows PowerShell
```

En macOS también puedes hacer **doble clic en `demo.command`** desde el Finder (la primera vez: clic
derecho → *Abrir*, para saltar el aviso de Gatekeeper).

Los scripts `demo.*` aceptan un puerto, igual que los de arranque (`./demo.sh 9000`). ⚠️ Cargar los datos
de demostración **borra los datos existentes**.

### macOS: aviso de Gatekeeper

La primera vez que se ejecuta en macOS, el sistema puede bloquear el binario por no estar firmado. El
script `start.sh` elimina la cuarentena automáticamente, pero si el problema persiste ejecuta:

```bash
xattr -d com.apple.quarantine frankenphp
```

### Actualizar a una nueva versión

1. Detén la aplicación (cierra la terminal o pulsa Ctrl + C si se ejecuta en primer plano).
2. Descarga el paquete de la nueva versión desde la
   [página de Releases](https://github.com/reasol-edu/gestconv-plus/releases) y descomprímelo.
3. Copia los archivos nuevos sobre la carpeta existente, sobreescribiendo los que ya haya.
   El directorio `data/` (base de datos, secretos y caché) **no forma parte del paquete**, por lo que
   se conserva automáticamente al extraer encima.
4. Vuelve a ejecutar el script de arranque. En cada arranque se aplican automáticamente las
   migraciones pendientes y se regenera la caché.

!!! tip "Alternativa limpia"
    Si prefieres descomprimir en una carpeta nueva, copia el directorio `data/` desde la instalación
    anterior antes de arrancar. También puedes conservar un fichero `.env.local` si lo habías creado
    para personalizar alguna variable.

### Variables de entorno {#variables-de-entorno-opcionales}

Esta es la **referencia única** de las variables de configuración. En el binario nativo se ajustan antes
de lanzar el script (tanto en Linux/macOS como en Windows); en Docker se definen en `.env.local`.

| Variable | Descripción | Valor por defecto |
|----------|-------------|-------------------|
| `PORT` | Puerto de escucha (binario nativo) | `8080` |
| `APP_SECRET` | Clave de seguridad (64 caracteres hexadecimales). En el binario se genera sola | *(generada)* |
| `DATABASE_URL` | Conexión a la base de datos (Docker usa PostgreSQL; el binario, SQLite) | *(según el modo)* |
| `MIGRATIONS_PATH` | Carpeta de migraciones acorde a la base de datos: `migrations/postgresql`, `migrations/mysql` o `migrations/sqlite` | *(según el modo)* |
| `DEFAULT_URI` | URL pública de la aplicación, usada en los enlaces de los emails | `http://localhost` |
| `APP_EXTERNAL_ENABLED` | Activar autenticación iSéneca (ver [Introducción](index.md#acceso-a-la-aplicacion)) | `true` |
| `APP_EXTERNAL_URL` | URL del servicio iSéneca | *(URL oficial)* |
| `APP_EXTERNAL_URL_FORCE_SECURITY` | Verificar certificado TLS de iSéneca | `true` |
| `MAILER_DSN` | Transporte de correo para los [emails automáticos](07-administrar-la-plataforma.md#correo-electronico-del-servidor) | `null://null` (desactivado) |
| `MAILER_FROM` | Dirección remitente de los emails automáticos | `no-responder@example.com` |
| `MESSENGER_TRANSPORT_DSN` | Cola de envío asíncrono de correos | `doctrine://default?auto_setup=0` |
| `LOAD_FIXTURES` | Cargar datos de demostración al arrancar (⚠️ borra datos existentes) | `false` |
| `APP_LOG` | Activar el [registro de actividad](07-administrar-la-plataforma.md#registro-de-actividad) | `true` (`false` en el binario nativo) |
| `SYMFONY_TRUSTED_PROXIES` | IPs del proxy inverso desde el que recibe peticiones la aplicación (p. ej. nginx, Caddy). Necesario para que el registro muestre la IP real del usuario. Déjalo sin definir si no usas proxy | *(sin definir)* |

## Despliegue con Docker

Modo recomendado para producción. La imagen oficial
[`reasoledu/gestconv-plus`](https://hub.docker.com/r/reasoledu/gestconv-plus) se publica automáticamente
en Docker Hub con cada versión; no es necesario compilar nada. Incluye
[FrankenPHP](https://frankenphp.dev) como servidor de aplicaciones y usa
[PostgreSQL](https://www.postgresql.org) 16 como base de datos.

### Preparación

Copia el fichero de ejemplo y edita los valores. Usa `.env.local` (Git lo ignora, así que tus
secretos no se versionan y el `.env` del repositorio queda intacto):

```bash
cp .env.example .env.local
```

Indica a Docker Compose que use ese fichero exportándolo una vez en tu sesión. Así todos los comandos
`docker compose` de este capítulo lo leerán sin necesidad de repetir ningún flag:

```bash
export COMPOSE_ENV_FILES=.env.local
```

!!! tip "Alternativa"
    Añade `--env-file .env.local` a cada comando `docker compose`. Si quieres que la aplicación se
    inicie sola al reiniciar el servidor, consulta
    [Arranque automático al reiniciar el servidor](#arranque-automatico-al-reiniciar-el-servidor).

Los campos obligatorios son:

- **`APP_SECRET`** — clave aleatoria de 64 caracteres hexadecimales. Genera una accediendo
  a [esta página web](https://numbergenerator.org/hex-code-generator#!numbers=1&length=64&addfilters=) o, si
  tienes PHP instalado, con:
  ```bash
  php -r 'echo bin2hex(random_bytes(32));'
  ```
- **`DB_PASSWORD`** — contraseña de la base de datos PostgreSQL.

### Arranque

```bash
docker compose up -d
```

La primera vez que se inicia, el contenedor realiza automáticamente lo siguiente:

1. Ejecuta las migraciones de base de datos.
2. Crea el usuario administrador inicial (`admin` / `admin`), sin ningún centro educativo.
3. Inicializa la caché de Symfony.

La aplicación queda disponible en `http://localhost` (puerto 80 por defecto). El siguiente paso es
dar de alta el centro real: ver
[Configuración inicial del centro educativo](#configuracion-inicial-del-centro-educativo).

El stack levanta tres contenedores: `app` (servidor FrankenPHP), `database` (PostgreSQL) y `worker`, que
procesa el envío asíncrono de los correos y las tareas programadas con Symfony Scheduler, como la
limpieza semanal del registro de actividad o la prescripción automática diaria de partes sin
notificar (ver [Envío asíncrono](07-administrar-la-plataforma.md#envio-asincrono)).

!!! danger "Cambia la contraseña por defecto"
    El usuario inicial `admin` / `admin` se crea solo para el primer acceso: la propia aplicación
    obliga a establecer una contraseña nueva en el primer inicio de sesión y no deja usar ninguna
    otra pantalla hasta hacerlo (salvo que la inicialización se hiciera con
    `--no-force-password-change`, ver [app:setup](#appsetup)). Elige una contraseña robusta y, en
    producción, crea además tu propio administrador con [`app:create-admin`](#appcreate-admin).
    Nunca dejes `admin` / `admin` en una instalación accesible por red.

### Datos de demostración

Para arrancar con datos de prueba (centros, docentes, estudiantes y partes precargados), cambia en tu
`.env.local` el valor de la variable que ya existe (no añadas una línea nueva: una clave duplicada haría
que Docker Compose use la última aparición y podría seguir valiendo `false`):

```dotenv
LOAD_FIXTURES=true
```

El contenedor cargará los fixtures automáticamente en cada arranque. ⚠️ Esta opción **borra todos los
datos existentes**.

### HTTPS con Let's Encrypt

Para habilitar HTTPS automático, edita `.env.local` con tu dominio real:

```dotenv
SERVER_NAME=gestconv.tudominio.es
DEFAULT_URI=https://gestconv.tudominio.es
HTTP_PORT=80
HTTPS_PORT=443
```

FrankenPHP (Caddy) gestionará el certificado TLS sin configuración adicional.

### Datos persistentes

Los datos se almacenan en el directorio `./data/` del proyecto:

- `./data/postgres/` — base de datos PostgreSQL.
- `./data/var/` — caché, logs y sesiones de Symfony.

### Arranque automático al reiniciar el servidor

Los tres servicios del `compose.yaml` llevan `restart: unless-stopped`, así que **el demonio de Docker
vuelve a levantarlos solo tras un reinicio del servidor**, sin ninguna configuración adicional de
GestConv+. Para el caso habitual basta con dos cosas:

1. Que Docker se inicie con el sistema:
   ```bash
   sudo systemctl enable --now docker
   ```
2. Haber arrancado el stack al menos una vez con `docker compose up -d` y no haberlo detenido
   explícitamente con `docker compose stop` o `docker compose down`.

!!! warning "`.env.local` solo se lee al hacer `up`"
    Las variables de `.env.local` se graban en los contenedores **cuando se crean**
    (`docker compose up -d`). Los reinicios automáticos reutilizan esos contenedores tal cual y
    **no vuelven a leer el fichero**. Por eso, si más adelante editas `.env.local` (o el propio
    `compose.yaml`), debes volver a ejecutar `docker compose up -d` para que los cambios surtan efecto;
    un simple reinicio del servidor no los recogerá.

Si además quieres que el stack se **recree** desde cero en cada arranque —por ejemplo, para que siempre
aplique los últimos valores de `.env.local` aunque previamente se hubiera hecho `down`— define una unidad
de **systemd**:

```ini
# /etc/systemd/system/gestconv-plus.service
[Unit]
Description=GestConv+ (Docker Compose)
Requires=docker.service
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/opt/gestconv-plus     # ruta donde están compose.yaml y .env.local
Environment=COMPOSE_ENV_FILES=.env.local
ExecStart=/usr/bin/docker compose up -d
ExecStop=/usr/bin/docker compose down
TimeoutStartSec=0

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now gestconv-plus.service
```

`Environment=COMPOSE_ENV_FILES=.env.local` (ruta relativa a `WorkingDirectory`) es lo que hace que el
arranque automático use tu fichero de variables; equivale al `export` que harías en tu sesión. Como
alternativa, usa `ExecStart=/usr/bin/docker compose --env-file .env.local up -d`. Comprueba la ruta del
ejecutable de Docker con `which docker` (en algunas distribuciones es `/usr/local/bin/docker`).

| Situación | Solo `restart: unless-stopped` | Unidad de systemd |
|---|:---:|:---:|
| Reinicio del servidor | ✅ | ✅ |
| Tras `docker compose down` | ❌ no vuelve | ✅ se recrea |
| Recoge cambios de `.env.local` / `compose.yaml` sin intervención | ❌ requiere `up` manual | ✅ en cada arranque |

### Actualización

```bash
docker compose pull
docker compose up -d
```

Las migraciones se aplican automáticamente en cada arranque.

### Comandos útiles

```bash
# Ver logs en tiempo real
docker compose logs -f app

# Abrir una shell en el contenedor
docker compose exec app sh

# Crear un centro educativo adicional
docker compose exec app php bin/console app:create-educational-centre

# Crear un administrador adicional
docker compose exec app php bin/console app:create-admin
```

## Despliegue en Plesk

Opción para centros que ya disponen de un **VPS o servidor dedicado gestionado con Plesk**. El
stack es PHP-FPM + MySQL + Apache/Nginx estándar: no requiere Docker ni FrankenPHP, aunque sí
acceso SSH a la suscripción.

La guía completa, con los ocho pasos detallados (dominio, PHP 8.4, variables de entorno,
Composer, worker vía cron y HTTPS), está en el repositorio:

**[Guía de despliegue en Plesk](https://github.com/reasol-edu/gestconv-plus/blob/main/docs/despliegue/plesk.md)**

## Despliegue en Ubuntu Server 26.04

Opción para centros que disponen de un **VPS o servidor dedicado con Ubuntu Server 26.04 LTS**.
Usa el binario de FrankenPHP con **PostgreSQL nativo** y dos servicios **systemd**. No requiere
Docker. Se requiere acceso SSH con sudo.

### Instalación automatizada

El script `install-ubuntu.sh` (incluido en el paquete descargado o disponible directamente en el
repositorio) realiza la instalación completa sin intervención manual.

**Requisitos antes de ejecutarlo:**

- Ubuntu Server 26.04 LTS con acceso a Internet y usuario con sudo.
- Un **nombre de dominio** que apunte al servidor (necesario para el certificado TLS automático).
- Puertos **80**, **443/tcp** y **443/udp** accesibles desde Internet.

**Ejecución:**

Desde el directorio donde descomprimiste el paquete:

```bash
sudo bash install-ubuntu.sh
```

O directamente desde el repositorio, sin descargar el paquete:

```bash
curl -fsSL https://raw.githubusercontent.com/reasol-edu/gestconv-plus/main/dist/install-ubuntu.sh \
  | sudo bash
```

El script solicita tres datos: el **nombre de dominio**, la **contraseña de la base de datos** y la
**dirección de correo remitente** (opcional). Instala PostgreSQL, configura el cortafuegos, crea el
usuario del sistema, descarga el binario y deja en marcha los dos servicios systemd (aplicación y
worker).

!!! danger "Cambia la contraseña por defecto"
    Al terminar, la aplicación queda accesible en `https://tudominio.es` con `admin` / `admin`.
    El primer inicio de sesión obliga a establecer una contraseña nueva antes de poder usar
    cualquier otra pantalla: hazlo antes de dar acceso a nadie más.

!!! info "¿Prefieres controlar cada paso?"
    La **[guía de instalación manual](https://github.com/reasol-edu/gestconv-plus/blob/main/docs/despliegue/ubuntu-manual.md)**
    del repositorio detalla los mismos pasos que realiza el script, uno a uno, para adaptarlos a tu
    entorno.

### Actualización en Ubuntu Server

1. Descarga el nuevo paquete y detén los servicios:

   ```bash
   VERSION=X.Y.Z
   curl -fsSL https://github.com/reasol-edu/gestconv-plus/releases/download/v${VERSION}/gestconv-plus-${VERSION}-linux-x86_64.tar.gz \
     -o /tmp/gestconv-plus-new.tar.gz
   sudo systemctl stop gestconv-plus-worker gestconv-plus
   ```

2. Extrae el nuevo paquete sobre la instalación existente. El directorio `data/` (secretos y base de
   datos) y el fichero `.env.local` no están en el paquete, por lo que se conservan intactos:

   ```bash
   sudo -u gestconvplus tar xzf /tmp/gestconv-plus-new.tar.gz -C /opt/gestconv-plus --strip-components=1
   ```

3. Vuelve a arrancar los servicios. `gestconv-start.sh` aplica automáticamente las migraciones
   pendientes y regenera la caché:

   ```bash
   sudo systemctl start gestconv-plus gestconv-plus-worker
   ```

Si prefieres que el servidor se actualice solo con cada nueva versión —mediante un systemd timer o
un webhook de GitHub—, sigue la
**[guía de despliegue continuo](https://github.com/reasol-edu/gestconv-plus/blob/main/docs/despliegue/despliegue-continuo.md)**
del repositorio.

## Desarrollo local

Para contribuir al proyecto o ejecutarlo desde el código fuente. Requisitos: PHP 8.4+, Composer,
Symfony CLI y Docker Compose (solo para la base de datos).

```bash
# 1. Clona el repositorio y copia el entorno
git clone https://github.com/reasol-edu/gestconv-plus.git
cd gestconv-plus
cp .env.example .env.local

# 2. Edita .env.local y rellena APP_SECRET y DB_PASSWORD
#    con valores aleatorios. Genéralos con:
php -r 'echo bin2hex(random_bytes(32)) . PHP_EOL;'

# 3. Instala las dependencias
composer install

# 4. Levanta la base de datos e inicialízala
docker compose --env-file .env.local -f compose.yaml -f compose.dev.yaml up -d
make migrate
make setup

# 5. Arranca el servidor de desarrollo
symfony server:start          # o: php -S localhost:8000 -t public/
```

Los pasos 4 y 5 pueden abreviarse con `make dev`, que levanta los contenedores y arranca
`symfony server:start` en primer plano (`make dev-stop` detiene los contenedores).

Accede a **https://localhost:8000** (o **http://localhost:8000** con `php -S`) con `admin` / `admin`.

!!! note "Overlay de desarrollo"
    `compose.dev.yaml` se combina con `-f` y expone PostgreSQL en el puerto 5432, dejando los servicios
    PHP (`app` y `worker`) tras el perfil `production`; por eso el comando anterior solo arranca la base
    de datos. En producción se usa únicamente `compose.yaml` (`docker compose up -d`), que levanta
    también la aplicación.

!!! note "Otras bases de datos"
    También puedes desarrollar contra MySQL/MariaDB o SQLite sin Docker: ajusta `DATABASE_URL` en
    `.env.local` y establece `MIGRATIONS_PATH` a la carpeta correspondiente (`migrations/mysql` o
    `migrations/sqlite`).

Otros comandos útiles: `make fixtures` carga los datos de demostración (los usuarios y escenarios
están en el `DEMO.md` del repositorio), `make test` ejecuta los tests y
`vendor/bin/phpstan analyse --memory-limit=1G` lanza el análisis estático. Para actualizar desde el
repositorio: `git pull`, `composer install` y `make migrate`.

!!! tip "Revisa `.env.example` tras cada actualización"
    Si una nueva versión añade variables de entorno, aparecen en `.env.example`. Compara con
    tu `.env.local` y añade las que necesites.

## Configuración inicial del centro educativo

Con la aplicación en marcha, queda darle vida: crear el centro y su primera cuenta de
administración. Esto se hace **una sola vez** por centro.

1. **Entra con la cuenta inicial** `admin` / `admin` (el primer inicio de sesión obliga a cambiar
   la contraseña).
2. **Crea el centro** — la pantalla de selección de centro, al no existir ninguno todavía, ofrece
   un enlace directo a **Administración › Centros › Nuevo centro**. Solo pide tres datos: código,
   nombre y localidad. Al crearlo se generan automáticamente su primer curso académico (ya activo)
   y los catálogos por defecto de conductas, medidas, métodos de comunicación y ubicaciones (ver
   [Administrar la plataforma](07-administrar-la-plataforma.md#centros-educativos)). También se
   puede crear desde la consola con
   [`app:create-educational-centre`](#appcreate-educational-centre).
3. **Designa la administración del centro** — desde **Administración › Centros**, edita el centro
   y añade como administradores a las cuentas del equipo directivo (créalas antes desde
   **Administración › Docentes**, o espera a importar el profesorado desde Séneca y asígnalas
   después).

A partir de aquí, el testigo pasa al equipo directivo:
[Preparar el curso académico](02-preparar-el-curso-academico.md) explica cómo dejar listo el
curso con el profesorado, el alumnado y los grupos.

## Comandos de consola

Todos los comandos se ejecutan con `php bin/console <comando>` (o, en el paquete distribuido para
Windows/Ubuntu, con el binario de FrankenPHP: `frankenphp php-cli bin/console <comando>`). Solo
existen los tres comandos de esta sección bajo el espacio de nombres `app:`; puedes comprobarlo en
cualquier momento con `php bin/console list app`.

### app:setup

```
php bin/console app:setup [--no-force-password-change] [--demo-data]
```

Inicializa la aplicación **si la base de datos está completamente vacía** (si no existe ningún
docente todavía). Si ya hay al menos un docente registrado, el comando no hace nada y termina sin
error — por eso es seguro incluirlo en el arranque automático de la aplicación (lo hacen los
scripts `start.sh`/`start.ps1`/`start.bat` del paquete distribuido) sin riesgo de duplicar datos
en arranques posteriores.

Cuando sí se ejecuta, crea siempre un docente administrador global con usuario `admin` y
contraseña `admin`, con el cambio de contraseña obligatorio en el primer inicio de sesión (ver
[Forzar cambio de contraseña](07-administrar-la-plataforma.md#forzar-cambio-de-contrasena)): la
cuenta queda confinada a la pantalla de cambio de contraseña hasta que se establece una nueva. La
opción `--no-force-password-change` omite esta obligación y deja la contraseña `admin` activa sin
restricciones.

Por defecto **no crea ningún centro educativo**: un administrador que entre sin ningún centro
creado ve, en la pantalla de selección de centro, un enlace directo para crear el primero (ver
[Configuración inicial del centro educativo](#configuracion-inicial-del-centro-educativo)). La
opción `--demo-data` crea además un centro educativo de demostración (código `23999999`, nombre
«IES Test», ciudad «Linares») con un curso académico activo nombrado con el año en curso (por
ejemplo, «2026-2027») y sus conductas contrarias, medidas disciplinarias, métodos de comunicación
y ubicaciones por defecto; solo tiene sentido para probar la aplicación, nunca en un despliegue
para un centro real.

> Si usas `--no-force-password-change`, cambia la contraseña del usuario `admin` manualmente
> inmediatamente después del primer arranque en cualquier entorno accesible desde fuera de tu
> equipo.

### app:create-educational-centre

```
php bin/console app:create-educational-centre [código] [nombre] [ciudad]
```

Crea un nuevo centro educativo. Los tres argumentos son opcionales: si se omite alguno, el comando
lo pregunta de forma interactiva.

| Argumento | Descripción |
|---|---|
| `código` | Código de centro (por ejemplo, `23700281`) |
| `nombre` | Nombre del centro |
| `ciudad` | Localidad |

Igual que al crear el centro desde la web, se generan automáticamente su primer curso académico
(ya activo, nombrado con el año en curso, por ejemplo `2026-2027`) y los catálogos por defecto de
conductas, medidas, métodos de comunicación y ubicaciones. La oferta formativa se configura después
desde la aplicación (ver [Preparar el curso académico](02-preparar-el-curso-academico.md)).

### app:create-admin

```
php bin/console app:create-admin <usuario> [contraseña] [--no-force-password-change]
```

Crea una cuenta de docente con privilegios de **administrador global** (acceso a todos los centros
del servidor). El argumento `usuario` es obligatorio; si se omite `contraseña`, el comando la pide
de forma interactiva sin mostrarla en pantalla.

Si el nombre de usuario ya existe, el comando falla con un error y no modifica la cuenta existente.

Por defecto, la cuenta creada tiene el cambio de contraseña obligatorio en el primer inicio de
sesión (ver
[Forzar cambio de contraseña](07-administrar-la-plataforma.md#forzar-cambio-de-contrasena)).
La opción `--no-force-password-change` omite esta obligación y deja la contraseña indicada activa
sin restricciones.

Es la vía recomendada para crear administradores adicionales o para recuperar el acceso si se ha
perdido la contraseña del único administrador (ver
[Resolución de problemas](09-resolucion-de-problemas.md)).
