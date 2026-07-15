# Despliegue continuo (CD) en Ubuntu Server

Con despliegue continuo, el servidor se actualiza solo cada vez que se publica una nueva versión
de GestConv+, sin intervención manual. Esta guía asume una instalación en Ubuntu Server como la
descrita en el
[manual](https://reasol-edu.github.io/gestconv-plus/01-instalacion-y-puesta-en-marcha.html#despliegue-en-ubuntu-server-2604)
(automatizada con `install-ubuntu.sh` o [manual paso a paso](ubuntu-manual.md)).

La base es siempre el mismo script de actualización; la diferencia está en cómo se activa:
**sondeo periódico** (más sencillo, sin puertos extra) o **webhook** (instantáneo, requiere un
puerto adicional).

## Script de actualización compartido

Crea `/opt/gestconv-plus/gestconv-update.sh` con el usuario `gestconvplus`:

```bash
sudo -u gestconvplus tee /opt/gestconv-plus/gestconv-update.sh > /dev/null << 'EOF'
#!/usr/bin/env bash
# Actualiza GestConv+ a la última versión publicada en GitHub.
# Compara la versión instalada con la etiqueta remota más reciente; si difieren,
# descarga el paquete y reinicia los servicios.
set -euo pipefail

INSTALL_DIR=/opt/gestconv-plus
REPO=reasol-edu/gestconv-plus
LOG_TAG=gestconv-update

log()  { logger -t "$LOG_TAG" "$*"; echo "$*"; }
error(){ logger -t "$LOG_TAG" -p user.err "ERROR: $*"; echo "ERROR: $*" >&2; }

# ── Obtener etiqueta remota más reciente ──────────────────────────────────────
REMOTE_TAG=$(curl -fsSL "https://api.github.com/repos/${REPO}/releases/latest" \
  | grep '"tag_name"' | head -1 | sed 's/.*"tag_name": *"\(.*\)".*/\1/')

if [[ -z "$REMOTE_TAG" ]]; then
  error "No se pudo obtener la versión remota."
  exit 1
fi

# ── Comparar con la versión instalada ────────────────────────────────────────
# El paquete incluye la versión en el fichero .version de la raíz.
LOCAL_TAG="v$(cat "${INSTALL_DIR}/.version" 2>/dev/null || echo "none")"

if [[ "$LOCAL_TAG" == "$REMOTE_TAG" ]]; then
  log "Ya en ${REMOTE_TAG}. Sin cambios."
  exit 0
fi

log "Actualizando ${LOCAL_TAG} → ${REMOTE_TAG}…"

# ── Descarga ──────────────────────────────────────────────────────────────────
# Ruta fija dentro del directorio de instalación (solo escribible por gestconvplus).
# Evita el comodín en /tmp, que sudo rechaza en los argumentos de la regla.
VERSION=${REMOTE_TAG#v}
PKG="${INSTALL_DIR}/.gestconv-plus-update.tar.gz"
curl -fsSL \
  "https://github.com/${REPO}/releases/download/${REMOTE_TAG}/gestconv-plus-${VERSION}-linux-x86_64.tar.gz" \
  -o "$PKG"

# ── Parada, extracción y arranque ─────────────────────────────────────────────
sudo systemctl stop gestconv-plus-worker gestconv-plus
sudo tar xzf "$PKG" -C "$INSTALL_DIR" --strip-components=1
rm -f "$PKG"
sudo systemctl start gestconv-plus gestconv-plus-worker

log "Actualización a ${REMOTE_TAG} completada."
EOF
sudo chmod +x /opt/gestconv-plus/gestconv-update.sh
```

El script necesita poder invocar `sudo systemctl` sin contraseña. Añade la regla de sudoers:

```bash
sudo tee /etc/sudoers.d/gestconv-update > /dev/null << 'EOF'
gestconvplus ALL=(root) NOPASSWD: \
  /usr/bin/systemctl stop gestconv-plus gestconv-plus-worker, \
  /usr/bin/systemctl start gestconv-plus gestconv-plus-worker, \
  /usr/bin/tar xzf /opt/gestconv-plus/.gestconv-plus-update.tar.gz -C /opt/gestconv-plus --strip-components=1
EOF
sudo chmod 440 /etc/sudoers.d/gestconv-update
```

## Opción A — Sondeo periódico con systemd timer

El timer comprueba si hay nueva versión cada 15 minutos. No requiere abrir ningún puerto extra ni
configurar el repositorio remoto.

```bash
sudo tee /etc/systemd/system/gestconv-update.service > /dev/null << 'UNIT'
[Unit]
Description=GestConv+ — actualización automática
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=gestconvplus
ExecStart=/opt/gestconv-plus/gestconv-update.sh
StandardOutput=journal
StandardError=journal
UNIT

sudo tee /etc/systemd/system/gestconv-update.timer > /dev/null << 'UNIT'
[Unit]
Description=GestConv+ — comprueba actualizaciones cada 15 minutos

[Timer]
OnBootSec=2min
OnUnitActiveSec=15min
RandomizedDelaySec=60

[Install]
WantedBy=timers.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now gestconv-update.timer
```

Verifica que el timer está activo:

```bash
systemctl list-timers gestconv-update.timer
```

Para forzar una comprobación inmediata sin esperar al siguiente disparo:

```bash
sudo systemctl start gestconv-update.service
journalctl -u gestconv-update.service -n 20
```

## Opción B — Webhook desde GitHub

El webhook recibe la señal de GitHub en el momento exacto en que se publica la release, sin ningún
retardo de sondeo. Requiere abrir el puerto elegido en el cortafuegos y configurar un secreto
compartido en GitHub.

**1. Instala `webhook`:**

```bash
sudo apt-get install -y webhook
```

**2. Crea la configuración del receptor:**

```bash
sudo -u gestconvplus tee /opt/gestconv-plus/webhook.json > /dev/null << 'EOF'
[
  {
    "id": "gestconv-update",
    "execute-command": "/opt/gestconv-plus/gestconv-update.sh",
    "command-working-directory": "/opt/gestconv-plus",
    "response-message": "Actualización iniciada",
    "trigger-rule": {
      "and": [
        {
          "match": {
            "type": "payload-hmac-sha256",
            "secret": "WEBHOOK_SECRET",
            "parameter": { "source": "header", "name": "X-Hub-Signature-256" }
          }
        },
        {
          "match": {
            "type": "value",
            "value": "release",
            "parameter": { "source": "payload", "name": "action" }
          }
        }
      ]
    }
  }
]
EOF
```

Sustituye `WEBHOOK_SECRET` por una cadena aleatoria larga (p. ej. `openssl rand -hex 32`).

**3. Crea el servicio systemd para el receptor:**

```bash
sudo tee /etc/systemd/system/gestconv-webhook.service > /dev/null << 'UNIT'
[Unit]
Description=GestConv+ — receptor de webhooks
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=gestconvplus
ExecStart=/usr/bin/webhook -hooks /opt/gestconv-plus/webhook.json -port 9000 -verbose
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now gestconv-webhook
```

**4. Abre el puerto en el cortafuegos:**

```bash
sudo ufw allow 9000/tcp comment 'GestConv+ webhook'
```

**5. Configura el webhook en GitHub:**

En el repositorio ve a **Settings → Webhooks → Add webhook**:

| Campo | Valor |
|-------|-------|
| Payload URL | `https://tudominio.es:9000/hooks/gestconv-update` |
| Content type | `application/json` |
| Secret | el mismo valor que `WEBHOOK_SECRET` |
| Events | «Let me select individual events» → **Releases** |

> [!TIP]
> **HTTPS para el webhook.** Si prefieres no exponer el puerto 9000 directamente, configura un
> proxy inverso en FrankenPHP (o Caddy) que enrute `/hooks/` al receptor local en
> `localhost:9000`. Así el webhook llega por el mismo puerto 443 ya abierto y el tráfico queda
> cifrado con tu certificado TLS existente.
