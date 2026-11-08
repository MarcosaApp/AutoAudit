-- Pruebas funcionales autocontenidas. Uso:
-- docker compose exec -T bd psql -U postgres -d autoaudit -v ON_ERROR_STOP=1 < base_de_datos/pruebas/01_pruebas_funcionales.sql
-- El ROLLBACK final evita dejar filas de prueba y reglas de auditoría modificadas.
BEGIN;
DO $$
DECLARE
    identificador_cliente BIGINT;
    identificador_tabla BIGINT;
    total_eventos_inicial BIGINT;
    total_eventos_final BIGINT;
    valores_anteriores TEXT;
    valores_nuevos TEXT;
BEGIN
    -- 1. Configurar automáticamente los tres tipos de trigger para la columna telefono.
    SELECT auditoria.configurar_tabla('public','clientes',
          '{"telefono":["INSERT","UPDATE","DELETE"],"nombre":["INSERT","DELETE"]}'::jsonb)
      INTO identificador_tabla;
    SELECT COUNT(*) INTO total_eventos_inicial
      FROM auditoria.bitacora_eventos WHERE id_tabla = identificador_tabla;
    ASSERT (SELECT COUNT(*) = 3 FROM pg_catalog.pg_trigger
            WHERE tgrelid = 'public.clientes'::regclass
              AND tgname LIKE 'tr_autoaudit_%' AND NOT tgisinternal),
           'No se crearon los 3 triggers';

    -- 2. INSERT: el cambio debe aparecer en bitácora.
    INSERT INTO public.clientes (nombre, correo, telefono)
    VALUES ('Prueba automática','prueba@example.com','1111-0000')
    RETURNING id_cliente INTO identificador_cliente;
    ASSERT EXISTS (SELECT 1 FROM auditoria.bitacora_eventos
                  WHERE id_tabla = identificador_tabla AND operacion = 'INSERT'
                  AND clave_primaria ->> 'id_cliente' = identificador_cliente::TEXT),
           'INSERT sin auditoría';

    -- 3. UPDATE sin cambio real: no crear un evento nuevo.
    SELECT COUNT(*) INTO total_eventos_inicial FROM auditoria.bitacora_eventos
       WHERE operacion = 'UPDATE' AND clave_primaria ->> 'id_cliente' = identificador_cliente::TEXT;
    UPDATE public.clientes SET telefono='1111-0000' WHERE id_cliente = identificador_cliente;
    SELECT COUNT(*) INTO total_eventos_final FROM auditoria.bitacora_eventos
       WHERE operacion = 'UPDATE' AND clave_primaria ->> 'id_cliente' = identificador_cliente::TEXT;
    ASSERT total_eventos_inicial = total_eventos_final, 'UPDATE idéntico creó un evento';

    -- 4. UPDATE real: comprobar valores anteriores y posteriores.
    UPDATE public.clientes SET telefono='2222-0000' WHERE id_cliente = identificador_cliente;
    SELECT detalle.valor_anterior #>> '{}', detalle.valor_nuevo #>> '{}'
      INTO valores_anteriores, valores_nuevos
      FROM auditoria.bitacora_eventos evento
      JOIN auditoria.bitacora_detalles detalle ON detalle.id_evento = evento.id_evento
      WHERE evento.operacion='UPDATE' AND detalle.nombre_columna='telefono'
        AND evento.clave_primaria ->> 'id_cliente' = identificador_cliente::TEXT
      ORDER BY evento.id_evento DESC LIMIT 1;
    ASSERT valores_anteriores = '1111-0000' AND valores_nuevos = '2222-0000',
           'UPDATE no guardó OLD y NEW correctamente';

    -- 5. DELETE: conservar imagen original aunque la fila fuente desaparezca.
    DELETE FROM public.clientes WHERE id_cliente = identificador_cliente;
    ASSERT EXISTS (SELECT 1 FROM auditoria.bitacora_eventos
                  WHERE operacion='DELETE' AND registro_anterior ->> 'telefono' = '2222-0000'
                    AND clave_primaria ->> 'id_cliente' = identificador_cliente::TEXT),
           'DELETE no conserva la instantánea';
    ASSERT NOT EXISTS (SELECT 1 FROM public.clientes WHERE id_cliente = identificador_cliente),
           'El cliente de prueba debería estar eliminado';

    -- 6. Desactivar: los triggers deben desaparecer, pero no se borra el historial.
    PERFORM auditoria.cambiar_estado(identificador_tabla, FALSE);
    ASSERT (SELECT COUNT(*) = 0 FROM pg_catalog.pg_trigger
            WHERE tgrelid = 'public.clientes'::regclass
              AND tgname LIKE 'tr_autoaudit_%' AND NOT tgisinternal),
           'Se esperaban cero triggers después de desactivar';
    RAISE NOTICE 'APROBADO: INSERT, UPDATE, DELETE, sin cambios, histórico y desactivación';
END $$;
ROLLBACK;
