#!/usr/bin/env bash
# Ejecutar validacion funcional sobre una base de datos AutoAudit ya instalada.
set -Eeuo pipefail
RUTA_PROYECTO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RUTA_PROYECTO"
echo 'Ejecutando pruebas funcionales (terminan con ROLLBACK)...'
sudo -u postgres psql -X -d autoaudit -v ON_ERROR_STOP=1 < base_de_datos/pruebas/01_pruebas_funcionales.sql
echo 'Pruebas funcionales finalizadas. Los tiempos de rendimiento se prueban por separado.'
