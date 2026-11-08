#!/usr/bin/env bash
# Verificaciones locales. Ejecuta este archivo SOLO después de levantar Docker Compose.
set -euo pipefail
cd "$(dirname "$0")"
echo '[1/4] Estado de contenedores:'
docker compose ps
echo '[2/4] Sintaxis PHP:'
docker compose exec -T web sh -c 'for archivo in /var/www/html/public/index.php /var/www/html/src/*.php; do php -l "$archivo" || exit 1; done'
echo '[3/4] PostgreSQL:'
docker compose exec -T bd psql -U postgres -d autoaudit -v ON_ERROR_STOP=1 -c 'SELECT version();'
echo '[4/4] Pruebas INSERT/UPDATE/DELETE (se revierten con ROLLBACK):'
docker compose exec -T bd psql -U postgres -d autoaudit -v ON_ERROR_STOP=1 < base_de_datos/pruebas/01_pruebas_funcionales.sql
echo 'Verificación terminada. Revisa las líneas APROBADO y cualquier advertencia.'
