#!/usr/bin/env bash
# AutoAudit: servidor PHP local para estudiar sin configurar Apache o Docker.
set -Eeuo pipefail
RUTA_PROYECTO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RUTA_PROYECTO"
if [[ ! -f .env ]]; then
    echo 'ERROR: falta .env en la raiz del proyecto.' >&2
    exit 1
fi
set -a
# shellcheck disable=SC1091
source .env
set +a

: "${AUTOAUDIT_CLAVE_APP:?Falta AUTOAUDIT_CLAVE_APP}"
export DB_SERVIDOR=127.0.0.1
export DB_PUERTO=5432
export DB_NOMBRE=autoaudit
export DB_USUARIO=autoaudit_aplicacion
export DB_CLAVE="$AUTOAUDIT_CLAVE_APP"
PUERTO="${PUERTO_WEB:-8080}"
if [[ ! "$PUERTO" =~ ^[0-9]{2,5}$ ]]; then
    echo 'ERROR: PUERTO_WEB debe contener un numero de puerto valido.' >&2
    exit 1
fi
if ! php -m | grep -qi '^pdo_pgsql$'; then
    echo 'ERROR: falta pdo_pgsql. Instala: sudo apt install php-pgsql' >&2
    exit 1
fi

echo "Servidor de practica activo: http://127.0.0.1:$PUERTO"
echo 'Abre esa direccion en Firefox dentro de Ubuntu.'
echo 'Para detener el servidor, vuelve a esta terminal y pulsa Ctrl+C.'
exec php -S "127.0.0.1:$PUERTO" -t app/public
