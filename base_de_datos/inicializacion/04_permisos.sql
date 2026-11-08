-- AUTOAUDIT / BLOQUE 3: privilegios del rol web. La bitácora NO es editable por el rol web.
REVOKE ALL ON SCHEMA auditoria FROM PUBLIC;
REVOKE ALL ON ALL TABLES IN SCHEMA auditoria FROM PUBLIC;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA auditoria FROM PUBLIC;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA auditoria FROM PUBLIC;
GRANT USAGE ON SCHEMA auditoria TO autoaudit_aplicacion;
GRANT USAGE ON SCHEMA public TO autoaudit_aplicacion;

-- La interfaz debe leer usuarios para login e identificar a los autores de cambios.
GRANT SELECT ON auditoria.usuarios, auditoria.estaciones_trabajo,
    auditoria.tablas_auditadas, auditoria.columnas_auditadas,
    auditoria.reglas_auditoria, auditoria.bitacora_eventos,
    auditoria.bitacora_detalles TO autoaudit_aplicacion;

-- Exclusivamente la tabla de demostración puede ser modificada desde el navegador.
GRANT SELECT, INSERT, UPDATE, DELETE ON public.clientes TO autoaudit_aplicacion;
GRANT SELECT, INSERT, UPDATE, DELETE ON public.productos TO autoaudit_aplicacion;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO autoaudit_aplicacion;

-- Acceso a funciones de negocio; la UI restringe configurar/pausar a administradores.
GRANT EXECUTE ON FUNCTION auditoria.obtener_o_crear_estacion(TEXT,TEXT,INET,TEXT) TO autoaudit_aplicacion;
GRANT EXECUTE ON FUNCTION auditoria.configurar_tabla(TEXT,TEXT,JSONB) TO autoaudit_aplicacion;
GRANT EXECUTE ON FUNCTION auditoria.cambiar_estado(BIGINT,BOOLEAN) TO autoaudit_aplicacion;
-- No se otorga acceso directo a generar_triggers ni a registrar_cambio.
-- Son SECURITY DEFINER y se llaman internamente desde otras funciones/triggers.
