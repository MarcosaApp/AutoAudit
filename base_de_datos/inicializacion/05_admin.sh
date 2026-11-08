#!/usr/bin/env bash
# BLOQUE 4. Crear el usuario inicial; pgcrypto genera un hash bcrypt compatible con PHP.
set -euo pipefail
: "${AUTOAUDIT_ADMIN_USUARIO:?Debes definir AUTOAUDIT_ADMIN_USUARIO}"
: "${AUTOAUDIT_ADMIN_CLAVE:?Debes definir AUTOAUDIT_ADMIN_CLAVE}"
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  --set=nombre_administrador="$AUTOAUDIT_ADMIN_USUARIO" \
  --set=clave_administrador="$AUTOAUDIT_ADMIN_CLAVE" <<'SQL'
INSERT INTO auditoria.usuarios (nombre_usuario, contrasena_hash, rol)
VALUES (:'nombre_administrador', crypt(:'clave_administrador', gen_salt('bf', 12)), 'administrador')
ON CONFLICT (nombre_usuario) DO NOTHING;
SQL
