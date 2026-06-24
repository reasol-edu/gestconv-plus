#!/usr/bin/env bash
# =============================================================================
# install-ubuntu.sh — Instalación automatizada de GestConv+ en Ubuntu Server
#
# Compatible: Ubuntu Server 26.04 LTS (y versiones posteriores de Ubuntu LTS)
# Arquitecturas: x86_64, aarch64
#
# Uso (desde el directorio del paquete descargado):
#   sudo bash install-ubuntu.sh
#
# Uso (descarga directa):
#   curl -fsSL https://raw.githubusercontent.com/reasol-edu/gestconv-plus/main/dist/install-ubuntu.sh \
#     | sudo bash
#
# Qué instala este script:
#   1. PostgreSQL (paquete oficial de Ubuntu) + base de datos y usuario
#   2. Cortafuegos UFW con los puertos mínimos abiertos (SSH, HTTP, HTTPS)
#   3. Usuario del sistema «gestconvplus» y directorio /opt/gestconv-plus
#   4. Binario de GestConv+ (última versión publicada en GitHub Releases)
#   5. Scripts de arranque del servidor y del worker de mensajería
#   6. Servicios systemd gestconv-plus y gestconv-plus-worker (arranque automático)
#
# Requisitos previos:
#   - Ubuntu Server 26.04 LTS (o posterior) con acceso a Internet
#   - Ejecutar como root o con: sudo bash install-ubuntu.sh
#   - Un nombre de dominio (p. ej. gestconv.tucentro.es) apuntando a este servidor,
#     necesario para que FrankenPHP/Caddy obtenga el certificado TLS automático
# =============================================================================
set -euo pipefail

# ── colores y helpers ──────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

step() { echo -e "\n${CYAN}${BOLD}▶  $*${NC}"; }
ok()   { echo -e "   ${GREEN}✔${NC}  $*"; }
warn() { echo -e "   ${YELLOW}⚠${NC}   $*"; }
die()  { echo -e "\n${RED}✘  Error: $*${NC}" >&2; exit 1; }

# ── verificaciones previas ────────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || die "Ejecuta el script como root:  sudo bash $0"

if [[ -f /etc/os-release ]]; then
    # shellcheck source=/dev/null
    source /etc/os-release
    if [[ "${ID:-}" != "ubuntu" ]]; then
        warn "Este script está diseñado para Ubuntu. Tu sistema es: ${PRETTY_NAME:-desconocido}."
        warn "Puede funcionar en distribuciones derivadas, pero no está garantizado."
        read -rp "   ¿Continuar de todas formas? [s/N] " FORCE
        [[ "${FORCE:-N}" =~ ^[Ss]$ ]] || { echo "Instalación cancelada."; exit 0; }
    fi
fi

ARCH=$(uname -m)
case "$ARCH" in
    x86_64)  ASSET_ARCH="linux-x86_64"  ;;
    aarch64) ASSET_ARCH="linux-aarch64" ;;
    *)       die "Arquitectura no soportada: ${ARCH}. Solo x86_64 y aarch64." ;;
esac

# ── banner ────────────────────────────────────────────────────────────────────
echo -e "
${BOLD}╔══════════════════════════════════════════════════════╗
║        GestConv+ — Instalación en Ubuntu Server        ║
╚══════════════════════════════════════════════════════╝${NC}

Este script instalará GestConv+ con:
  •  FrankenPHP como servidor web (HTTPS automático vía Let's Encrypt)
  •  PostgreSQL como base de datos
  •  Hub Mercure embebido (sincronización en tiempo real)
  •  Dos servicios systemd con arranque automático al reiniciar
"

# ── solicitar configuración ───────────────────────────────────────────────────
step "Configuración"

while true; do
    read -rp "   Nombre de dominio (p.ej. gestconv.tucentro.es): " DOMAIN
    [[ -n "$DOMAIN" && "$DOMAIN" != *" "* ]] && break
    warn "El dominio no puede estar vacío ni contener espacios."
done

while true; do
    read -rsp "   Contraseña de la base de datos (mín. 12 caracteres, sin comillas simples): " DB_PASS
    echo
    [[ ${#DB_PASS} -ge 12 && "$DB_PASS" != *"'"* ]] && break
    warn "Contraseña inválida: mínimo 12 caracteres y sin comillas simples (')."
done

read -rp "   Dirección de correo remitente [no-responder@${DOMAIN}]: " MAIL_FROM
MAIL_FROM="${MAIL_FROM:-no-responder@${DOMAIN}}"

echo -e "
   ${BOLD}Dominio:${NC}   ${DOMAIN}
   ${BOLD}Base BD:${NC}   gestconv  (usuario: gestconv)
   ${BOLD}Correo:${NC}    ${MAIL_FROM}
"
read -rp "   ¿Empezar la instalación? [S/n] " CONFIRM
[[ "${CONFIRM:-S}" =~ ^[Ss]?$ ]] || { echo "Instalación cancelada."; exit 0; }

# ── 1. PostgreSQL ─────────────────────────────────────────────────────────────
step "1/7 · Instalar PostgreSQL"
DEBIAN_FRONTEND=noninteractive apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq postgresql postgresql-client curl
ok "PostgreSQL instalado"

step "    Crear base de datos y usuario"
sudo -u postgres psql -v ON_ERROR_STOP=1 << SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'gestconv') THEN
    CREATE USER gestconv WITH PASSWORD '${DB_PASS}';
  ELSE
    ALTER USER gestconv WITH PASSWORD '${DB_PASS}';
  END IF;
END
\$\$;
SELECT 'CREATE DATABASE' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'gestconv')\gexec
GRANT ALL PRIVILEGES ON DATABASE gestconv TO gestconv;
ALTER DATABASE gestconv OWNER TO gestconv;
SQL
ok "Base de datos 'gestconv' y usuario 'gestconv' listos"

# ── 2. Cortafuegos ────────────────────────────────────────────────────────────
step "2/7 · Configurar cortafuegos (UFW)"
ufw allow OpenSSH  > /dev/null
ufw allow 80/tcp   > /dev/null
ufw allow 443/tcp  > /dev/null
ufw allow 443/udp  > /dev/null   # HTTP/3 (QUIC)
ufw --force enable > /dev/null
ok "UFW activo: SSH, HTTP y HTTPS abiertos"

# ── 3. Usuario del sistema ────────────────────────────────────────────────────
step "3/7 · Crear usuario del sistema 'gestconvplus'"
if ! id gestconvplus &> /dev/null; then
    useradd -r -d /opt/gestconv-plus -s /usr/sbin/nologin gestconvplus
    ok "Usuario 'gestconvplus' creado"
else
    ok "Usuario 'gestconvplus' ya existía"
fi
mkdir -p /opt/gestconv-plus
chown gestconvplus:gestconvplus /opt/gestconv-plus

# ── 4. Binario de GestConv+ ─────────────────────────────────────────────────────
step "4/7 · Descargar el binario de GestConv+ (última versión)"
VERSION=$(curl -fsSL https://api.github.com/repos/reasol-edu/gestconv-plus/releases/latest \
    | grep '"tag_name"' | sed 's/.*"v\([^"]*\)".*/\1/')
[[ -n "$VERSION" ]] || die "No se pudo obtener la versión más reciente desde GitHub."
TARBALL_URL="https://github.com/reasol-edu/gestconv-plus/releases/download/v${VERSION}/gestconv-plus-${VERSION}-${ASSET_ARCH}.tar.gz"
echo "   Descargando gestconv-plus v${VERSION} (${ASSET_ARCH})..."
curl -fsSL "$TARBALL_URL" | sudo -u gestconvplus tar xzf - -C /opt/gestconv-plus --strip-components=1
ok "GestConv+ v${VERSION} extraído en /opt/gestconv-plus"

# ── 5. Configuración ─────────────────────────────────────────────────────────
step "5/7 · Crear fichero de configuración (.env.local)"
if [[ -f /opt/gestconv-plus/.env.local ]]; then
    warn ".env.local ya existe; se conserva. Revisa que DATABASE_URL y SERVER_ADDR sean correctos."
else
sudo -u gestconvplus tee /opt/gestconv-plus/.env.local > /dev/null << ENVFILE
# GestConv+ — configuración de producción en Ubuntu Server
# Generado automáticamente el $(date '+%Y-%m-%d %H:%M:%S')
# Edita este fichero para cambiar dominio, correo, etc.
# Después: sudo systemctl restart gestconv-plus gestconv-plus-worker

SERVER_ADDR=${DOMAIN}
DEFAULT_URI=https://${DOMAIN}
DATABASE_URL=postgresql://gestconv:${DB_PASS}@localhost:5432/gestconv?serverVersion=16&charset=utf8
MIGRATIONS_PATH=migrations/postgresql
MAILER_DSN=null://null
MAILER_FROM=${MAIL_FROM}
APP_EXTERNAL_ENABLED=true
ENVFILE
chmod 600 /opt/gestconv-plus/.env.local
ok ".env.local creado con permisos 600"
fi

# ── 6. Scripts de arranque ────────────────────────────────────────────────────
step "6/7 · Crear scripts de arranque"

# — gestconv-start.sh (servidor web) ——————————————————————————————————————————————
sudo -u gestconvplus tee /opt/gestconv-plus/gestconv-start.sh > /dev/null << 'STARTSCRIPT'
#!/usr/bin/env bash
# gestconv-start.sh — arranca FrankenPHP leyendo la configuración de .env.local
# No modifiques directamente las variables hardcodeadas de start.sh original;
# usa .env.local para toda la configuración de este servidor.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA="${ROOT}/data"
APP="${ROOT}/app"
FP="${ROOT}/frankenphp"

# Cargar configuración local (anula los valores por defecto del binario)
set -a
# shellcheck source=/dev/null
[[ -f "${ROOT}/.env.local" ]] && source "${ROOT}/.env.local"
set +a

# Valores requeridos
: "${SERVER_ADDR:?Falta SERVER_ADDR en .env.local (nombre de dominio o :puerto)}"
: "${DATABASE_URL:?Falta DATABASE_URL en .env.local}"
: "${DEFAULT_URI:?Falta DEFAULT_URI en .env.local}"

# Valores con defecto
export APP_ENV=prod
export APP_DEBUG=0
export DOCUMENT_ROOT="${APP}/public"
export MIGRATIONS_PATH="${MIGRATIONS_PATH:-migrations/postgresql}"
export APP_LOG="${APP_LOG:-true}"
export APP_LOG_RETENTION_DAYS="${APP_LOG_RETENTION_DAYS:-90}"
export APP_EXTERNAL_ENABLED="${APP_EXTERNAL_ENABLED:-true}"
export APP_EXTERNAL_URL="${APP_EXTERNAL_URL:-https://seneca.juntadeandalucia.es/seneca/jsp/ComprobarUsuarioExt.jsp}"
export APP_EXTERNAL_URL_FORCE_SECURITY="${APP_EXTERNAL_URL_FORCE_SECURITY:-true}"
export MAILER_DSN="${MAILER_DSN:-null://null}"
export MAILER_FROM="${MAILER_FROM:-no-responder@example.com}"
export MESSENGER_TRANSPORT_DSN="${MESSENGER_TRANSPORT_DSN:-doctrine://default?auto_setup=0}"
export MERCURE_URL="${MERCURE_URL:-${DEFAULT_URI}/.well-known/mercure}"
export MERCURE_PUBLIC_URL="${MERCURE_PUBLIC_URL:-/.well-known/mercure}"

mkdir -p "${DATA}"

# Generar APP_SECRET en el primer arranque y guardarlo en data/.secret
if [[ ! -f "${DATA}/.secret" ]]; then
    "${FP}" php-cli -r 'echo bin2hex(random_bytes(32));' > "${DATA}/.secret"
fi
export APP_SECRET="$(< "${DATA}/.secret")"

# Generar MERCURE_JWT_SECRET en el primer arranque y guardarlo en data/.mercure_secret
if [[ ! -f "${DATA}/.mercure_secret" ]]; then
    "${FP}" php-cli -r 'echo bin2hex(random_bytes(32));' > "${DATA}/.mercure_secret"
fi
export MERCURE_JWT_SECRET="$(< "${DATA}/.mercure_secret")"

# Escribir app/.env para que Symfony lo lea vía bootEnv (requerido por el binario)
cat > "${APP}/.env" << EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${APP_SECRET}
DATABASE_URL=${DATABASE_URL}
MIGRATIONS_PATH=${MIGRATIONS_PATH}
DEFAULT_URI=${DEFAULT_URI}
SERVER_ADDR=${SERVER_ADDR}
APP_LOG=${APP_LOG}
APP_LOG_RETENTION_DAYS=${APP_LOG_RETENTION_DAYS}
APP_EXTERNAL_ENABLED=${APP_EXTERNAL_ENABLED}
APP_EXTERNAL_URL=${APP_EXTERNAL_URL}
APP_EXTERNAL_URL_FORCE_SECURITY=${APP_EXTERNAL_URL_FORCE_SECURITY}
MAILER_DSN=${MAILER_DSN}
MAILER_FROM=${MAILER_FROM}
MESSENGER_TRANSPORT_DSN=${MESSENGER_TRANSPORT_DSN}
MERCURE_URL=${MERCURE_URL}
MERCURE_PUBLIC_URL=${MERCURE_PUBLIC_URL}
MERCURE_JWT_SECRET=${MERCURE_JWT_SECRET}
EOF

# Inicializar: migraciones, datos por defecto y caché de producción
cd "${APP}"
rm -rf var/cache/
"${FP}" php-cli bin/console doctrine:migrations:migrate --no-interaction
"${FP}" php-cli bin/console app:setup --no-interaction || true
"${FP}" php-cli bin/console cache:warmup --no-interaction

# Arrancar FrankenPHP en primer plano (systemd gestiona el ciclo de vida)
cd "${ROOT}"
exec "${FP}" run --config Caddyfile
STARTSCRIPT

# — gestconv-worker.sh (worker de Messenger) ——————————————————————————————————————
sudo -u gestconvplus tee /opt/gestconv-plus/gestconv-worker.sh > /dev/null << 'WORKERSCRIPT'
#!/usr/bin/env bash
# gestconv-worker.sh — consumidor de mensajes (emails y recordatorios programados)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA="${ROOT}/data"
APP="${ROOT}/app"
FP="${ROOT}/frankenphp"

set -a
# shellcheck source=/dev/null
[[ -f "${ROOT}/.env.local" ]] && source "${ROOT}/.env.local"
set +a

export APP_ENV=prod
export APP_DEBUG=0
export DOCUMENT_ROOT="${APP}/public"
export MIGRATIONS_PATH="${MIGRATIONS_PATH:-migrations/postgresql}"
export APP_LOG="${APP_LOG:-true}"
export APP_LOG_RETENTION_DAYS="${APP_LOG_RETENTION_DAYS:-90}"
export APP_EXTERNAL_ENABLED="${APP_EXTERNAL_ENABLED:-true}"
export APP_EXTERNAL_URL="${APP_EXTERNAL_URL:-https://seneca.juntadeandalucia.es/seneca/jsp/ComprobarUsuarioExt.jsp}"
export APP_EXTERNAL_URL_FORCE_SECURITY="${APP_EXTERNAL_URL_FORCE_SECURITY:-true}"
export MAILER_DSN="${MAILER_DSN:-null://null}"
export MAILER_FROM="${MAILER_FROM:-no-responder@example.com}"
export MESSENGER_TRANSPORT_DSN="${MESSENGER_TRANSPORT_DSN:-doctrine://default?auto_setup=0}"
export MERCURE_URL="${MERCURE_URL:-${DEFAULT_URI}/.well-known/mercure}"
export MERCURE_PUBLIC_URL="${MERCURE_PUBLIC_URL:-/.well-known/mercure}"

# Esperar a que gestconv-start.sh haya generado los secretos (primer arranque)
until [[ -f "${DATA}/.secret" && -f "${DATA}/.mercure_secret" ]]; do
    sleep 1
done
export APP_SECRET="$(< "${DATA}/.secret")"
export MERCURE_JWT_SECRET="$(< "${DATA}/.mercure_secret")"

cat > "${APP}/.env" << EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${APP_SECRET}
DATABASE_URL=${DATABASE_URL}
MIGRATIONS_PATH=${MIGRATIONS_PATH}
DEFAULT_URI=${DEFAULT_URI}
SERVER_ADDR=${SERVER_ADDR}
APP_LOG=${APP_LOG}
APP_LOG_RETENTION_DAYS=${APP_LOG_RETENTION_DAYS}
APP_EXTERNAL_ENABLED=${APP_EXTERNAL_ENABLED}
APP_EXTERNAL_URL=${APP_EXTERNAL_URL}
APP_EXTERNAL_URL_FORCE_SECURITY=${APP_EXTERNAL_URL_FORCE_SECURITY}
MAILER_DSN=${MAILER_DSN}
MAILER_FROM=${MAILER_FROM}
MESSENGER_TRANSPORT_DSN=${MESSENGER_TRANSPORT_DSN}
MERCURE_URL=${MERCURE_URL}
MERCURE_PUBLIC_URL=${MERCURE_PUBLIC_URL}
MERCURE_JWT_SECRET=${MERCURE_JWT_SECRET}
EOF

cd "${APP}"
exec "${FP}" php-cli bin/console messenger:consume async scheduler_default \
    --time-limit=3600 --memory-limit=128M --quiet
WORKERSCRIPT

chmod +x /opt/gestconv-plus/gestconv-start.sh /opt/gestconv-plus/gestconv-worker.sh
ok "gestconv-start.sh y gestconv-worker.sh creados"

# ── 7. Servicios systemd ──────────────────────────────────────────────────────
step "7/7 · Instalar y arrancar los servicios systemd"

tee /etc/systemd/system/gestconv-plus.service > /dev/null << 'UNIT'
[Unit]
Description=GestConv+ (FrankenPHP)
Documentation=https://reasol-edu.github.io/gestconv-plus/
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
# Permite escuchar en los puertos 80 y 443 sin ejecutar como root
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
UNIT

tee /etc/systemd/system/gestconv-plus-worker.service > /dev/null << 'UNIT'
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

systemctl daemon-reload
systemctl enable --now gestconv-plus gestconv-plus-worker
ok "Servicios activos y habilitados para el arranque automático"

# ── resultado ─────────────────────────────────────────────────────────────────
echo -e "
${GREEN}${BOLD}╔══════════════════════════════════════════════════════╗
║              ✔  Instalación completada               ║
╚══════════════════════════════════════════════════════╝${NC}

  URL de acceso: ${BOLD}https://${DOMAIN}${NC}
  Usuario:       ${BOLD}admin${NC}
  Contraseña:    ${BOLD}admin${NC}  ← cámbiala ahora en Perfil

  ${YELLOW}⚠  En el primer arranque FrankenPHP solicita el certificado TLS.
     Puede tardar 30-60 segundos hasta que HTTPS esté disponible.${NC}

  Comandos útiles:
    Ver estado:   sudo systemctl status gestconv-plus gestConv-plus-worker
    Ver logs:     sudo journalctl -u gestConv-plus -f
    Reiniciar:    sudo systemctl restart gestConv-plus gestConv-plus-worker
    Configurar:   sudo nano /opt/gestConv-plus/.env.local
"
