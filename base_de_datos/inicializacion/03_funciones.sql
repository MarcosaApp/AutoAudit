-- AUTOAUDIT / BLOQUE 2: lógica dentro de PostgreSQL 18 (PL/pgSQL).
-- Nota académica: el PDF dice PL/SQL, pero el dialecto nativo de PostgreSQL es PL/pgSQL.

-- FUNCIÓN A. Registrar una estación de trabajo sin permitir INSERT directo en su tabla.
CREATE OR REPLACE FUNCTION auditoria.obtener_o_crear_estacion(
    identificador_recibido TEXT, nombre_recibido TEXT,
    direccion_recibida INET, navegador_recibido TEXT
) RETURNS BIGINT
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, auditoria, pg_temp AS $$
DECLARE
    identificador_estacion BIGINT;
BEGIN
    IF identificador_recibido !~ '^[a-f0-9]{64}$' THEN
        RAISE EXCEPTION 'Identificador de estación inválido';
    END IF;
    INSERT INTO auditoria.estaciones_trabajo
        (identificador, nombre, direccion_ip, agente_navegador)
    VALUES (identificador_recibido, LEFT(nombre_recibido, 120), direccion_recibida,
            LEFT(navegador_recibido, 1000))
    ON CONFLICT (identificador) DO UPDATE
       SET direccion_ip = EXCLUDED.direccion_ip,
           ultimo_acceso = CURRENT_TIMESTAMP
    RETURNING id_estacion INTO identificador_estacion;
    RETURN identificador_estacion;
END;
$$;

-- FUNCIÓN B. Esta función genérica es invocada por los triggers creados dinámicamente.
-- TG_ARGV[0] guarda el identificador de la tabla configurada; TG_OP es el evento SQL.
CREATE OR REPLACE FUNCTION auditoria.registrar_cambio() RETURNS TRIGGER
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, auditoria, pg_temp AS $$
DECLARE
    identificador_tabla BIGINT := TG_ARGV[0]::BIGINT;
    configuracion_tabla RECORD;
    registro_viejo JSONB;
    registro_nuevo JSONB;
    registro_clave JSONB;
    registro_actual JSONB;
    columna_regla RECORD;
    numero_columnas INTEGER := 0;
    identificador_evento BIGINT;
    identificador_usuario BIGINT;
    identificador_estacion BIGINT;
    direccion_cliente INET;
BEGIN
    -- B1. Verificar que la configuración sigue activa y pertenece a esta tabla.
    SELECT id_tabla, version_configuracion, activa
      INTO configuracion_tabla
      FROM auditoria.tablas_auditadas
     WHERE id_tabla = identificador_tabla
       AND nombre_esquema = TG_TABLE_SCHEMA AND nombre_tabla = TG_TABLE_NAME;
    IF NOT FOUND OR NOT configuracion_tabla.activa THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
    END IF;

    -- B2. Convertir NEW y OLD a JSONB. En INSERT no hay OLD, en DELETE no hay NEW.
    IF TG_OP = 'INSERT' THEN
        registro_viejo := NULL;
        registro_nuevo := to_jsonb(NEW);
        registro_actual := registro_nuevo;
    ELSIF TG_OP = 'DELETE' THEN
        registro_viejo := to_jsonb(OLD);
        registro_nuevo := NULL;
        registro_actual := registro_viejo;
    ELSE
        registro_viejo := to_jsonb(OLD);
        registro_nuevo := to_jsonb(NEW);
        registro_actual := registro_nuevo;
    END IF;

    -- B3. Determinar si hay columnas auditadas que justifiquen crear un evento.
    -- En UPDATE, no escribir bitácora si el valor es idéntico (incluso NULL = NULL).
    SELECT COUNT(*) INTO numero_columnas
      FROM auditoria.reglas_auditoria regla
      JOIN auditoria.columnas_auditadas columna ON columna.id_columna = regla.id_columna
     WHERE columna.id_tabla = identificador_tabla AND columna.activa
       AND regla.operacion = TG_OP
       AND (TG_OP <> 'UPDATE' OR
            (registro_viejo -> columna.nombre_columna)
            IS DISTINCT FROM (registro_nuevo -> columna.nombre_columna));
    IF numero_columnas = 0 THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
    END IF;

    -- B4. Construir la clave primaria como JSONB; soporta claves compuestas.
    SELECT COALESCE(jsonb_object_agg(atributo.attname, registro_actual -> atributo.attname), '{}'::jsonb)
      INTO registro_clave
      FROM pg_catalog.pg_index indice
      JOIN pg_catalog.pg_attribute atributo
        ON atributo.attrelid = indice.indrelid AND atributo.attnum = ANY(indice.indkey)
     WHERE indice.indrelid = TG_RELID AND indice.indisprimary;

    -- B5. Identificar el usuario de la web, equipo e IP enviados en la transacción.
    -- Para SQL directo, usuario/estación pueden ser NULL y se conserva SESSION_USER.
    identificador_usuario := NULLIF(current_setting('autoaudit.usuario_id', true), '')::BIGINT;
    identificador_estacion := NULLIF(current_setting('autoaudit.estacion_id', true), '')::BIGINT;
    direccion_cliente := COALESCE(NULLIF(current_setting('autoaudit.ip_origen', true), '')::INET,
                                  inet_client_addr());

    -- B6. Guardar el encabezado; se conservan instantáneas completas incluso tras DELETE.
    INSERT INTO auditoria.bitacora_eventos
       (id_tabla, version_configuracion, nombre_esquema, nombre_tabla, operacion,
        clave_primaria, registro_anterior, registro_nuevo, id_usuario,
        usuario_base_datos, id_estacion, direccion_ip)
    VALUES
       (identificador_tabla, configuracion_tabla.version_configuracion,
        TG_TABLE_SCHEMA, TG_TABLE_NAME, TG_OP, registro_clave,
        registro_viejo, registro_nuevo, identificador_usuario,
        session_user, identificador_estacion, direccion_cliente)
    RETURNING id_evento INTO identificador_evento;

    -- B7. Una fila de detalle por columna seleccionada. JSONB conserva tipos y NULL.
    FOR columna_regla IN
        SELECT columna.nombre_columna
        FROM auditoria.reglas_auditoria regla
        JOIN auditoria.columnas_auditadas columna ON columna.id_columna = regla.id_columna
        WHERE columna.id_tabla = identificador_tabla AND columna.activa
          AND regla.operacion = TG_OP
          AND (TG_OP <> 'UPDATE' OR
              (registro_viejo -> columna.nombre_columna)
              IS DISTINCT FROM (registro_nuevo -> columna.nombre_columna))
    LOOP
        INSERT INTO auditoria.bitacora_detalles
            (id_evento, nombre_columna, valor_anterior, valor_nuevo)
        VALUES (identificador_evento, columna_regla.nombre_columna,
                registro_viejo -> columna_regla.nombre_columna,
                registro_nuevo -> columna_regla.nombre_columna);
    END LOOP;

    IF TG_OP = 'DELETE' THEN RETURN OLD; ELSE RETURN NEW; END IF;
END;
$$;

-- FUNCIÓN C. El generador crea o elimina triggers AFTER para una tabla real.
-- Los identificadores dinámicos se citan con %I y los argumentos con %L.
CREATE OR REPLACE FUNCTION auditoria.generar_triggers(identificador_tabla BIGINT)
RETURNS VOID LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, auditoria, pg_temp AS $$
DECLARE
    tabla_elegida auditoria.tablas_auditadas%ROWTYPE;
    tipo_operacion TEXT;
    nombre_disparador TEXT;
    existe_columna_invalida BOOLEAN;
    existe_regla BOOLEAN;
BEGIN
    SELECT * INTO STRICT tabla_elegida
      FROM auditoria.tablas_auditadas WHERE id_tabla = identificador_tabla FOR UPDATE;

    -- C1. Limitar la auditoría a tablas ordinarias del esquema public en este proyecto.
    IF tabla_elegida.nombre_esquema <> 'public' OR NOT EXISTS (
        SELECT 1 FROM pg_catalog.pg_class clase
        JOIN pg_catalog.pg_namespace espacio ON espacio.oid = clase.relnamespace
        WHERE espacio.nspname = tabla_elegida.nombre_esquema
          AND clase.relname = tabla_elegida.nombre_tabla AND clase.relkind = 'r'
    ) THEN
        RAISE EXCEPTION 'Tabla origen no permitida o inexistente: %.%',
            tabla_elegida.nombre_esquema, tabla_elegida.nombre_tabla;
    END IF;

    -- C2. Validar las columnas con reglas antes de construir código SQL.
    SELECT EXISTS (
      SELECT 1 FROM auditoria.columnas_auditadas columna
      JOIN auditoria.reglas_auditoria regla ON regla.id_columna = columna.id_columna
      WHERE columna.id_tabla = identificador_tabla AND columna.activa
        AND NOT EXISTS (
          SELECT 1 FROM pg_catalog.pg_attribute atributo
          JOIN pg_catalog.pg_class clase ON clase.oid = atributo.attrelid
          JOIN pg_catalog.pg_namespace espacio ON espacio.oid = clase.relnamespace
          WHERE espacio.nspname = tabla_elegida.nombre_esquema
            AND clase.relname = tabla_elegida.nombre_tabla
            AND atributo.attname = columna.nombre_columna
            AND atributo.attnum > 0 AND NOT atributo.attisdropped
        )
    ) INTO existe_columna_invalida;
    IF existe_columna_invalida THEN
        RAISE EXCEPTION 'Hay reglas para columnas eliminadas o inexistentes';
    END IF;

    -- C3. Volver a generar los tres triggers, si existen reglas activas para ellos.
    FOREACH tipo_operacion IN ARRAY ARRAY['INSERT','UPDATE','DELETE']
    LOOP
        nombre_disparador := format('tr_autoaudit_%s_%s', identificador_tabla, lower(tipo_operacion));
        EXECUTE format('DROP TRIGGER IF EXISTS %I ON %I.%I', nombre_disparador,
                       tabla_elegida.nombre_esquema, tabla_elegida.nombre_tabla);
        SELECT EXISTS (
            SELECT 1 FROM auditoria.reglas_auditoria regla
            JOIN auditoria.columnas_auditadas columna ON columna.id_columna = regla.id_columna
            WHERE columna.id_tabla = identificador_tabla AND columna.activa
              AND regla.operacion = tipo_operacion
        ) INTO existe_regla;
        IF tabla_elegida.activa AND existe_regla THEN
            EXECUTE format(
              'CREATE TRIGGER %I AFTER %s ON %I.%I FOR EACH ROW EXECUTE FUNCTION auditoria.registrar_cambio(%L)',
              nombre_disparador, tipo_operacion, tabla_elegida.nombre_esquema,
              tabla_elegida.nombre_tabla, identificador_tabla::TEXT
            );
        END IF;
    END LOOP;
END;
$$;

-- FUNCIÓN D. Configurar tabla y columnas desde JSONB y regenerar triggers.
-- Ejemplo: SELECT auditoria.configurar_tabla('public','clientes',
--           '{"nombre":["INSERT","UPDATE","DELETE"]}'::jsonb);
CREATE OR REPLACE FUNCTION auditoria.configurar_tabla(
    esquema_recibido TEXT, tabla_recibida TEXT, reglas_recibidas JSONB
) RETURNS BIGINT LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, auditoria, pg_temp AS $$
DECLARE
    identificador_tabla BIGINT;
    identificador_columna BIGINT;
    nombre_columna_recibida TEXT;
    eventos_columna JSONB;
    evento_seleccionado TEXT;
BEGIN
    IF esquema_recibido <> 'public' OR NOT EXISTS (
        SELECT 1 FROM pg_catalog.pg_class clase
        JOIN pg_catalog.pg_namespace espacio ON espacio.oid = clase.relnamespace
        WHERE espacio.nspname = esquema_recibido
          AND clase.relname = tabla_recibida AND clase.relkind = 'r'
    ) THEN
        RAISE EXCEPTION 'La tabla origen no existe o no se permite auditarla';
    END IF;
    IF jsonb_typeof(reglas_recibidas) IS DISTINCT FROM 'object' THEN
        RAISE EXCEPTION 'La configuración debe ser un objeto JSON';
    END IF;

    -- D1. Crear o recuperar la configuración; bloquear fila para evitar ediciones simultáneas.
    INSERT INTO auditoria.tablas_auditadas (nombre_esquema, nombre_tabla)
    VALUES (esquema_recibido, tabla_recibida)
    ON CONFLICT (nombre_esquema, nombre_tabla) DO UPDATE
      SET activa = TRUE, version_configuracion = auditoria.tablas_auditadas.version_configuracion + 1
    RETURNING id_tabla INTO identificador_tabla;

    -- D2. Mantener las columnas para históricos, pero sustituir las reglas vigentes.
    DELETE FROM auditoria.reglas_auditoria regla
     USING auditoria.columnas_auditadas columna
     WHERE regla.id_columna = columna.id_columna AND columna.id_tabla = identificador_tabla;
    UPDATE auditoria.columnas_auditadas SET activa = FALSE WHERE id_tabla = identificador_tabla;

    -- D3. Validar columnas reales y operaciones permitidas para bloquear SQL no autorizado.
    FOR nombre_columna_recibida, eventos_columna IN
        SELECT clave, valor FROM jsonb_each(reglas_recibidas) AS seleccion(clave, valor)
    LOOP
        IF NOT EXISTS (
            SELECT 1 FROM pg_catalog.pg_attribute atributo
            JOIN pg_catalog.pg_class clase ON clase.oid = atributo.attrelid
            JOIN pg_catalog.pg_namespace espacio ON espacio.oid = clase.relnamespace
            WHERE espacio.nspname = esquema_recibido AND clase.relname = tabla_recibida
              AND atributo.attname = nombre_columna_recibida
              AND atributo.attnum > 0 AND NOT atributo.attisdropped
        ) THEN
            RAISE EXCEPTION 'Columna inválida: %', nombre_columna_recibida;
        END IF;
        IF jsonb_typeof(eventos_columna) <> 'array' THEN
            RAISE EXCEPTION 'Los eventos de cada columna deben ser una lista';
        END IF;
        INSERT INTO auditoria.columnas_auditadas (id_tabla, nombre_columna, activa)
        VALUES (identificador_tabla, nombre_columna_recibida, TRUE)
        ON CONFLICT (id_tabla, nombre_columna) DO UPDATE SET activa = TRUE
        RETURNING id_columna INTO identificador_columna;
        FOR evento_seleccionado IN SELECT jsonb_array_elements_text(eventos_columna)
        LOOP
            IF evento_seleccionado NOT IN ('INSERT','UPDATE','DELETE') THEN
                RAISE EXCEPTION 'Evento no permitido: %', evento_seleccionado;
            END IF;
            INSERT INTO auditoria.reglas_auditoria (id_columna, operacion)
            VALUES (identificador_columna, evento_seleccionado)
            ON CONFLICT (id_columna, operacion) DO NOTHING;
        END LOOP;
    END LOOP;

    -- D4. Crear triggers dinámicamente en la misma transacción.
    PERFORM auditoria.generar_triggers(identificador_tabla);
    RETURN identificador_tabla;
END;
$$;

-- FUNCIÓN E. Pausar o reactivar una auditoría sin borrar su historial.
CREATE OR REPLACE FUNCTION auditoria.cambiar_estado(
    identificador_tabla BIGINT, estado_deseado BOOLEAN
) RETURNS VOID LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, auditoria, pg_temp AS $$
BEGIN
    UPDATE auditoria.tablas_auditadas
       SET activa = estado_deseado, version_configuracion = version_configuracion + 1
     WHERE id_tabla = identificador_tabla;
    IF NOT FOUND THEN RAISE EXCEPTION 'Configuración no encontrada'; END IF;
    PERFORM auditoria.generar_triggers(identificador_tabla);
END;
$$;
