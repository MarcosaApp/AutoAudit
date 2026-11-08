-- 1. Tablas existentes y auditables del esquema public.
SELECT espacio.nspname AS esquema, clase.relname AS tabla
FROM pg_catalog.pg_class clase
JOIN pg_catalog.pg_namespace espacio ON espacio.oid = clase.relnamespace
WHERE espacio.nspname = 'public' AND clase.relkind = 'r'
ORDER BY clase.relname;

-- 2. Columnas existentes, tipos y orden.
SELECT clase.relname AS tabla, atributo.attname AS columna,
       pg_catalog.format_type(atributo.atttypid, atributo.atttypmod) AS tipo,
       atributo.attnum AS posicion
FROM pg_catalog.pg_class clase
JOIN pg_catalog.pg_namespace espacio ON espacio.oid = clase.relnamespace
JOIN pg_catalog.pg_attribute atributo ON atributo.attrelid = clase.oid
WHERE espacio.nspname = 'public' AND clase.relkind = 'r'
  AND atributo.attnum > 0 AND NOT atributo.attisdropped
ORDER BY clase.relname, atributo.attnum;

-- 3. Triggers instalados por AutoAudit.
SELECT event_object_schema, event_object_table, trigger_name, event_manipulation
FROM information_schema.triggers WHERE trigger_name LIKE 'tr_autoaudit_%'
ORDER BY event_object_table, trigger_name;
