#!/usr/bin/env bash
# AutoAudit: alternativa de servidor web con Apache en Ubuntu local.
# Antes, detener el servidor PHP del paso 02 usando Ctrl+C.
set -Eeuo pipefail
RUTA_PROYECTO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RUTA_PROYECTO"
if [[ ! -f .env ]]; then echo 'ERROR: falta .env' >&2; exit 1; fi
set -a
# shellcheck disable=SC1091
source .env
set +a
: "${AUTOAUDIT_CLAVE_APP:?Falta AUTOAUDIT_CLAVE_APP}"
if [[ ! "$AUTOAUDIT_CLAVE_APP" =~ ^[a-zA-Z0-9._-]{12,80}$ ]]; then
    echo 'ERROR: utiliza una clave simple compatible con la instalacion.' >&2
    exit 1
fi

sudo apt update
sudo apt install -y apache2 libapache2-mod-php php-pgsql
sudo mkdir -p /var/www/autoaudit
sudo cp -a app/. /var/www/autoaudit/
sudo chown -R root:www-data /var/www/autoaudit
sudo find /var/www/autoaudit -type d -exec chmod 755 {} \;
sudo find /var/www/autoaudit -type f -exec chmod 644 {} \;

ARCHIVO_TEMPORAL="$(mktemp)"
trap 'rm -f "$ARCHIVO_TEMPORAL"' EXIT
chmod 600 "$ARCHIVO_TEMPORAL"
cat > "$ARCHIVO_TEMPORAL" <<CONFIG
<VirtualHost *:8080>
    ServerName localhost
    DocumentRoot /var/www/autoaudit/public
    <Directory /var/www/autoaudit/public>
        Options FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    SetEnv DB_SERVIDOR "127.0.0.1"
    SetEnv DB_PUERTO "5432"
    SetEnv DB_NOMBRE "autoaudit"
    SetEnv DB_USUARIO "autoaudit_aplicacion"
    SetEnv DB_CLAVE "$AUTOAUDIT_CLAVE_APP"
    ErrorLog \${APACHE_LOG_DIR}/autoaudit_error.log
    CustomLog \${APACHE_LOG_DIR}/autoaudit_access.log combined
</VirtualHost>
CONFIG
sudo install -m 600 -o root -g root "$ARCHIVO_TEMPORAL" /etc/apache2/sites-available/autoaudit.conf
printf '%s\n' 'Listen 8080' | sudo tee /etc/apache2/conf-available/autoaudit-puerto.conf >/dev/null
sudo a2enconf autoaudit-puerto
sudo a2ensite autoaudit.conf
sudo apache2ctl configtest
sudo systemctl enable --now apache2
sudo systemctl restart apache2

echo 'Apache configurado. Abre: http://127.0.0.1:8080'
echo 'ATENCION: para reflejar cambios hechos en tu carpeta de proyecto, copia app/ a /var/www/autoaudit nuevamente.'
