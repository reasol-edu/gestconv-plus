# Instalación manual en Ubuntu Server 26.04

Guía paso a paso para instalar GestConv+ en un **VPS o servidor dedicado con Ubuntu Server 26.04
LTS**, usando el binario de FrankenPHP con **PostgreSQL nativo** y dos servicios **systemd**, sin
Docker. Se requiere acceso SSH con sudo.

!!! tip "Esto es lo que automatiza el script de instalación"
    Estos son exactamente los pasos que automatiza el script
    [`dist/install-ubuntu.sh`](https://github.com/reasol-edu/gestconv-plus/blob/main/dist/install-ubuntu.sh).
    Sigue este capítulo solo si prefieres controlar cada paso o adaptar la instalación a tu entorno; en
    caso contrario, usa la
    [instalación automatizada](01-instalacion-y-puesta-en-marcha.md#despliegue-en-ubuntu-server-2604)
    descrita en el capítulo de instalación.

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

!!! note "Detrás de un proxy inverso"
    Si vas a poner GestConv+ **detrás de un proxy inverso propio** (ver
    [la variante del paso 5](#variante-detras-de-un-proxy-inverso)), no abras 80/443: FrankenPHP
    servirá HTTP plano en un puerto local (p. ej. `8080`). Si el proxy corre en el mismo servidor no
    hace falta abrir nada más que SSH; si está en otra máquina, abre ese puerto **solo** para la IP
    del proxy: `sudo ufw allow from 10.0.0.5 to any port 8080 proto tcp`.

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

### Variante: detrás de un proxy inverso {#variante-detras-de-un-proxy-inverso}

Si el TLS lo va a terminar un **proxy inverso propio** (nginx, Apache, HAProxy, Traefik, el
balanceador de un proveedor de nube…) en vez de FrankenPHP, cambia dos cosas en `.env.local`:

```bash
# HTTP plano en un puerto local; sin nombre de dominio -> Caddy NO pide certificado
SERVER_ADDR=127.0.0.1:8080          # o  :8080  si el proxy está en otra máquina
DEFAULT_URI=https://gestconv.tudominio.es
DATABASE_URL=postgresql://gestconv:contraseña_segura@localhost:5432/gestconv?serverVersion=16&charset=utf8
MIGRATIONS_PATH=migrations/postgresql
MAILER_DSN=null://null
MAILER_FROM=no-responder@tudominio.es
# IP (o rango CIDR) del proxy inverso: sin esto la aplicación registraría la IP
# del proxy en vez de la del usuario y no reconocería el https:// original
SYMFONY_TRUSTED_PROXIES=10.0.0.5
```

- **No** hay Let's Encrypt ni certificado en este servidor: `SERVER_ADDR` con puerto y sin
  dominio desactiva el HTTPS automático de Caddy. El certificado del dominio lo gestiona el proxy.
- `SYMFONY_TRUSTED_PROXIES` es imprescindible para que el
  [registro de actividad](13-administrar-la-plataforma.md#registro-de-actividad) y los límites de
  intentos guarden la IP real del usuario y no la del proxy. Acepta una IP, un rango CIDR o varios
  valores separados por comas.
- El proxy debe reenviar las cabeceras `X-Forwarded-For`, `X-Forwarded-Proto` y `X-Forwarded-Host`.

**Ejemplo con nginx** (en el servidor del proxy):

```nginx
server {
    listen 443 ssl http2;
    server_name gestconv.tudominio.es;

    ssl_certificate     /etc/letsencrypt/live/gestconv.tudominio.es/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/gestconv.tudominio.es/privkey.pem;

    client_max_body_size 70m;   # subidas de adjuntos (post_max_size de la app: 64 MB)

    location / {
        proxy_pass http://10.0.0.10:8080;      # IP:puerto de este servidor
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host  $host;
    }
}
```

**Ejemplo con Apache** (`mod_proxy` + `mod_ssl` + `mod_headers`):

```apache
<VirtualHost *:443>
    ServerName gestconv.tudominio.es
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/gestconv.tudominio.es/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/gestconv.tudominio.es/privkey.pem

    LimitRequestBody 73400320
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "https"
    ProxyPass        / http://10.0.0.10:8080/
    ProxyPassReverse / http://10.0.0.10:8080/
</VirtualHost>
```

El script [`dist/install-ubuntu.sh`](https://github.com/reasol-edu/gestconv-plus/blob/main/dist/install-ubuntu.sh)
ofrece esta variante como opción 2 («Detrás de un proxy inverso propio») y pregunta el puerto
local y la IP del proxy.

### Avisos push al móvil (opcional)

Para activar los avisos push, genera las claves (una sola vez, **después del primer arranque**, cuando ya
existe `app/.env`) y añade las tres variables a `.env.local`. Sin ellas la función no aparece y el resto no cambia:

```bash
cd /opt/gestconv-plus/app
sudo -u gestconvplus ../frankenphp php-cli bin/console app:push:generate-keys
```

```bash
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:informatica@tudominio.es
```

Necesita HTTPS (ya lo da `SERVER_ADDR`) y salida a internet. Reinicia los dos servicios al terminar. Más
detalle en [Avisos push al móvil](13-administrar-la-plataforma.md#avisos-push-al-movil).

## 6. Crear los scripts de arranque

Los scripts `gestconv-start.sh` y `gestconv-worker.sh` los genera `install-ubuntu.sh`; en la
instalación manual, copia su contenido desde el propio script (sección «Crear scripts de arranque»
de [`dist/install-ubuntu.sh`](https://github.com/reasol-edu/gestconv-plus/blob/main/dist/install-ubuntu.sh)).

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

!!! note "Puertos 80 y 443 sin ser root"
    La directiva `AmbientCapabilities=CAP_NET_BIND_SERVICE` permite que el proceso (ejecutado como
    `gestconvplus`, sin privilegios de root) escuche en los puertos 80 y 443.

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
- Los pasos de actualización a nuevas versiones están en
  [Actualización en Ubuntu Server](01-instalacion-y-puesta-en-marcha.md#actualizacion-en-ubuntu-server),
  y la actualización automática, en [Despliegue continuo en Ubuntu Server](20-despliegue-continuo.md).
- Incluye en tus copias de seguridad el volcado de la base de datos, el secreto (`data/.secret`) y
  la configuración (`.env.local`):

  ```bash
  sudo -u postgres pg_dump gestconv > backup-$(date +%Y%m%d).sql
  ```
