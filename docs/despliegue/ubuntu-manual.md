# Instalación manual en Ubuntu Server 26.04

Guía paso a paso para instalar GestConv+ en un **VPS o servidor dedicado con Ubuntu Server 26.04
LTS**, usando el binario de FrankenPHP con **PostgreSQL nativo** y dos servicios **systemd**, sin
Docker. Se requiere acceso SSH con sudo.

> [!TIP]
> Estos son exactamente los pasos que automatiza el script
> [`dist/install-ubuntu.sh`](../../dist/install-ubuntu.sh). Sigue esta guía solo si prefieres
> controlar cada paso o adaptar la instalación a tu entorno; en caso contrario, usa la
> [instalación automatizada](https://reasol-edu.github.io/gestconv-plus/01-instalacion-y-puesta-en-marcha.html#despliegue-en-ubuntu-server-2604)
> descrita en el manual.

## 1. Instalar PostgreSQL

```bash
sudo apt-get update && sudo apt-get install -y postgresql postgresql-client curl
sudo -u postgres psql -c "CREATE USER gestconv WITH PASSWORD 'contraseña_segura';"
sudo -u postgres psql -c "CREATE DATABASE gestconv OWNER gestconv;"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE gestconv TO gestconv;"
```

## 2. Configurar el cortafuegos

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 443/udp   # HTTP/3 (QUIC)
sudo ufw enable
```

## 3. Crear el usuario del sistema y el directorio de instalación

```bash
sudo useradd -r -d /opt/gestconv-plus -s /usr/sbin/nologin gestconvplus
sudo mkdir -p /opt/gestconv-plus
sudo chown gestconvplus:gestconvplus /opt/gestconv-plus
```

## 4. Descargar el binario

Desde la [página de Releases](https://github.com/reasol-edu/gestconv-plus/releases), copia el enlace
del archivo `gestconv-plus-VERSION-linux-x86_64.tar.gz` (o `linux-aarch64` para ARM) y extráelo:

```bash
VERSION=X.Y.Z   # reemplaza por la versión actual
sudo -u gestconvplus bash -c "
  curl -fsSL https://github.com/reasol-edu/gestconv-plus/releases/download/v${VERSION}/gestconv-plus-${VERSION}-linux-x86_64.tar.gz \
  | tar xzf - -C /opt/gestconv-plus --strip-components=1
"
```

## 5. Crear el fichero de configuración

```bash
sudo -u gestconvplus nano /opt/gestconv-plus/.env.local
```

Contenido mínimo obligatorio:

```bash
SERVER_ADDR=gestconv.tudominio.es
DEFAULT_URI=https://gestconv.tudominio.es
DATABASE_URL=postgresql://gestconv:contraseña_segura@localhost:5432/gestconv?serverVersion=16&charset=utf8
MIGRATIONS_PATH=migrations/postgresql
MAILER_DSN=null://null
MAILER_FROM=no-responder@tudominio.es
```

`SERVER_ADDR` con el nombre de dominio (sin puerto) activa el **HTTPS automático** de
FrankenPHP/Caddy vía Let's Encrypt.

## 6. Crear los scripts de arranque

Los scripts `gestconv-start.sh` y `gestconv-worker.sh` los genera `install-ubuntu.sh`; en la
instalación manual, copia su contenido desde el propio script (sección «Crear scripts de arranque»
de [`dist/install-ubuntu.sh`](../../dist/install-ubuntu.sh)).

Los scripts leen `.env.local`, generan `APP_SECRET` automáticamente en el primer arranque
(guardado en `data/.secret`), escriben el fichero `app/.env` que necesita Symfony y lanzan en
primer plano el servidor o el worker respectivamente.

## 7. Instalar los servicios systemd

```bash
sudo tee /etc/systemd/system/gestconv-plus.service > /dev/null << 'UNIT'
[Unit]
Description=GestConv+ (FrankenPHP)
After=network-online.target postgresql.service
Wants=network-online.target
Requires=postgresql.service

[Service]
Type=simple
User=gestconvplus
Group=gestconvplus
WorkingDirectory=/opt/gestconv-plus
ExecStart=/opt/gestconv-plus/gestconv-start.sh
Restart=on-failure
RestartSec=5
TimeoutStopSec=30
LimitNOFILE=65536
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
UNIT

sudo tee /etc/systemd/system/gestconv-plus-worker.service > /dev/null << 'UNIT'
[Unit]
Description=GestConv+ Worker (Messenger + Scheduler)
After=gestconv-plus.service
Requires=gestconv-plus.service

[Service]
Type=simple
User=gestconvplus
Group=gestconvplus
WorkingDirectory=/opt/gestconv-plus
ExecStart=/opt/gestconv-plus/gestconv-worker.sh
Restart=always
RestartSec=10
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now gestconv-plus gestconv-plus-worker
```

> [!NOTE]
> La directiva `AmbientCapabilities=CAP_NET_BIND_SERVICE` permite que el proceso (ejecutado como
> `gestconvplus`, sin privilegios de root) escuche en los puertos 80 y 443.

## Comandos útiles

```bash
# Estado de los servicios
sudo systemctl status gestconv-plus gestconv-plus-worker

# Seguir los logs en tiempo real
sudo journalctl -u gestconv-plus -f
sudo journalctl -u gestconv-plus-worker -f

# Reiniciar tras cambiar .env.local
sudo systemctl restart gestconv-plus gestconv-plus-worker
```

## Después de instalar

- La aplicación queda accesible en `https://tudominio.es` con `admin` / `admin`.
  **Cambia la contraseña inmediatamente** en **Perfil → Cambiar contraseña**.
- Los pasos de actualización a nuevas versiones están en el
  [manual](https://reasol-edu.github.io/gestconv-plus/01-instalacion-y-puesta-en-marcha.html#actualizacion-en-ubuntu-server),
  y la actualización automática, en la guía de
  [despliegue continuo](despliegue-continuo.md).
- Incluye en tus copias de seguridad el volcado de la base de datos, el secreto (`data/.secret`) y
  la configuración (`.env.local`):

  ```bash
  sudo -u postgres pg_dump gestconv > backup-$(date +%Y%m%d).sql
  ```
