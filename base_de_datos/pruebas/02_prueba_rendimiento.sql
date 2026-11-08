-- Ejemplo de medición. Ejecutar con psql -v ON_ERROR_STOP=1 en ambiente DE PRUEBAS.
-- EXPLAIN ANALYZE imprime tiempos reales del entorno, no promete una cifra fija.
BEGIN;
SELECT auditoria.configurar_tabla('public','productos',
 '{"descripcion":["INSERT","UPDATE","DELETE"],"precio":["INSERT","UPDATE","DELETE"]}'::jsonb);
EXPLAIN (ANALYZE, BUFFERS, SUMMARY)
INSERT INTO public.productos (descripcion, precio, existencia)
SELECT 'Producto de carga ' || numero, (numero % 1000)::NUMERIC, numero % 15
FROM generate_series(1, 100) AS numero;
EXPLAIN (ANALYZE, BUFFERS, SUMMARY)
INSERT INTO public.productos (descripcion, precio, existencia)
SELECT 'Producto de carga B ' || numero, (numero % 1000)::NUMERIC, numero % 15
FROM generate_series(1, 1000) AS numero;
ROLLBACK;
