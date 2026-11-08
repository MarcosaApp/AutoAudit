#!/usr/bin/env bash
# AutoAudit: instalacion local PostgreSQL 18 en Ubuntu 26.04 LTS (sin Docker).
# Ejecutar desde Ubuntu: bash instalacion_ubuntu/01_preparar_base_de_datos.sh
set -Eeuo pipefail
RUTA_PROYECTO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RUTA_PROYECTO"

if [[ ! -f .env ]]; then
    echo "ERROR: falta .env. Ejecuta: cp .env.ejemplo .env; luego edita .env con nano .env" >&2
    exit 1
fi

# El archivo .env de este proyecto emplea pares CLAVE=VALOR compatibles con Bash.
# Contiene solo datos que la persona ha decidido introducir en su proyecto local.
set -a
# shellcheck disable=SC1091
source .env
set +a

: "${AUTOAUDIT_CLAVE_APP:?Falta AUTOAUDIT_CLAVE_APP en .env}"
: "${AUTOAUDIT_ADMIN_USUARIO:?Falta AUTOAUDIT_ADMIN_USUARIO en .env}"
: "${AUTOAUDIT_ADMIN_CLAVE:?Falta AUTOAUDIT_ADMIN_CLAVE en .env}"

# Se restringe el alfabeto para facilitar la primera instalacion y evitar
# caracteres que requieran escape en el archivo de configuracion Bash.
if [[ ! "$AUTOAUDIT_CLAVE_APP" =~ ^[a-zA-Z0-9._-]{12,80}$ ]] ||
   [[ ! "$AUTOAUDIT_ADMIN_CLAVE" =~ ^[a-zA-Z0-9._-]{12,80}$ ]] ||
   [[ ! "$AUTOAUDIT_ADMIN_USUARIO" =~ ^[a-zA-Z0-9_-]{3,60}$ ]]; then
    echo "ERROR: usa claves de 12-80 caracteres (letras/numeros/._-) y usuario de 3-60 (letras/numeros/_-)." >&2
    exit 1
fi
if [[ "$AUTOAUDIT_CLAVE_APP" == cambiar_clave_* || "$AUTOAUDIT_ADMIN_CLAVE" == cambiar_clave_* ]]; then
    echo "ERROR: cambia las contrasenas de ejemplo en .env antes de continuar." >&2
    exit 1
fi
chmod 600 .env

echo 'PASO 1/5 - Instalar PostgreSQL 18, PHP y el conector PHP-PostgreSQL...'
sudo apt update
sudo apt install -y postgresql-18 php-cli php-pgsql
sudo systemctl enable --now postgresql

if [[ "$(sudo -u postgres psql -XAt -d postgres -c "SELECT 1 FROM pg_database WHERE datname = 'autoaudit'")" == '1' ]]; then
    echo "ERROR: ya existe una base de datos llamada autoaudit. No se sobrescribe para proteger datos." >&2
    echo "Si es una instalacion anterior, utiliza la base existente o pide ayuda para revisarla." >&2
    exit 1
fi

echo 'PASO 2/5 - Crear rol PostgreSQL para la aplicacion...'
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d postgres \
    -v "clave_aplicacion=$AUTOAUDIT_CLAVE_APP" <<'SQL'
SELECT format('CREATE ROLE autoaudit_aplicacion LOGIN PASSWORD %L', :'clave_aplicacion')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoaudit_aplicacion') \gexec
SQL

echo 'PASO 3/5 - Crear base autoaudit, tablas y funciones...'
sudo -u postgres createdb autoaudit
# La redireccion con < hace que Bash lea los archivos; el usuario postgres no
# necesita permisos de acceso al directorio personal del estudiante.
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d autoaudit < base_de_datos/inicializacion/02_estructura.sql
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d autoaudit < base_de_datos/inicializacion/03_funciones.sql

echo 'PASO 4/5 - Configurar permisos de acceso limitados...'
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d autoaudit < base_de_datos/inicializacion/04_permisos.sql

echo 'PASO 5/5 - Crear usuario administrador de la interfaz...'
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d autoaudit \
    -v "nombre_administrador=$AUTOAUDIT_ADMIN_USUARIO" \
    -v "clave_administrador=$AUTOAUDIT_ADMIN_CLAVE" <<'SQL'
INSERT INTO auditoria.usuarios (nombre_usuario, contrasena_hash, rol)
VALUES (:'nombre_administrador', crypt(:'clave_administrador', gen_salt('bf', 12)), 'administrador')
ON CONFLICT (nombre_usuario) DO NOTHING;
SQL

echo
echo 'EXITO: PostgreSQL y AutoAudit estan preparados.'
echo 'Comprobar version: psql --version'
echo 'Siguiente comando: bash instalacion_ubuntu/02_iniciar_web.sh'
