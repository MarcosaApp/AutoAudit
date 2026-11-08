#!/usr/bin/env bash
# BLOQUE 1. Crear un usuario de PostgreSQL con privilegios limitados.
set -euo pipefail
: "${AUTOAUDIT_CLAVE_APP:?Debes definir AUTOAUDIT_CLAVE_APP}"
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  --set=clave_aplicacion="$AUTOAUDIT_CLAVE_APP" <<'SQL'
SELECT format('CREATE ROLE autoaudit_aplicacion LOGIN PASSWORD %L', :'clave_aplicacion')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autoaudit_aplicacion') \gexec
SQL
