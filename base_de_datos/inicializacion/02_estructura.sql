-- AUTOAUDIT / BLOQUE 1: estructura de entidades y relaciones.
-- Las funciones usan el esquema auditoria; las tablas ejemplo usan public.
BEGIN;
CREATE SCHEMA IF NOT EXISTS auditoria;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- Usuarios que inician sesión en la aplicación. Contraseñas: hash bcrypt, nunca texto plano.
CREATE TABLE auditoria.usuarios (
    id_usuario BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre_usuario VARCHAR(80) NOT NULL UNIQUE,
    contrasena_hash TEXT NOT NULL,
    rol VARCHAR(20) NOT NULL CHECK (rol IN ('administrador','consulta')),
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    creado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Estación de trabajo aproximada: huella del navegador + IP, no identificación física garantizada.
CREATE TABLE auditoria.estaciones_trabajo (
    id_estacion BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    identificador VARCHAR(64) NOT NULL UNIQUE,
    nombre VARCHAR(120) NOT NULL,
    direccion_ip INET,
    agente_navegador TEXT,
    ultimo_acceso TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Qué tabla se audita (ej. public.clientes). No apunta con FK a la tabla origen:
-- una auditoría debe sobrevivir a la eliminación de filas fuente.
CREATE TABLE auditoria.tablas_auditadas (
    id_tabla BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre_esquema TEXT NOT NULL,
    nombre_tabla TEXT NOT NULL,
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    version_configuracion INTEGER NOT NULL DEFAULT 1,
    creada_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (nombre_esquema, nombre_tabla)
);

-- Columnas elegibles de cada tabla auditada.
CREATE TABLE auditoria.columnas_auditadas (
    id_columna BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_tabla BIGINT NOT NULL REFERENCES auditoria.tablas_auditadas(id_tabla) ON DELETE RESTRICT,
    nombre_columna TEXT NOT NULL,
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (id_tabla, nombre_columna)
);

-- Una regla equivale a: columna X + evento INSERT/UPDATE/DELETE.
CREATE TABLE auditoria.reglas_auditoria (
    id_regla BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_columna BIGINT NOT NULL REFERENCES auditoria.columnas_auditadas(id_columna) ON DELETE RESTRICT,
    operacion VARCHAR(6) NOT NULL CHECK (operacion IN ('INSERT', 'UPDATE', 'DELETE')),
    UNIQUE (id_columna, operacion)
);

-- Encabezado del cambio. Las instantáneas JSONB preservan registros eliminados.
CREATE TABLE auditoria.bitacora_eventos (
    id_evento BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_tabla BIGINT NOT NULL REFERENCES auditoria.tablas_auditadas(id_tabla) ON DELETE RESTRICT,
    version_configuracion INTEGER NOT NULL,
    nombre_esquema TEXT NOT NULL,
    nombre_tabla TEXT NOT NULL,
    operacion VARCHAR(6) NOT NULL CHECK (operacion IN ('INSERT','UPDATE','DELETE')),
    clave_primaria JSONB NOT NULL DEFAULT '{}'::jsonb,
    registro_anterior JSONB,
    registro_nuevo JSONB,
    id_usuario BIGINT REFERENCES auditoria.usuarios(id_usuario) ON DELETE SET NULL,
    usuario_base_datos TEXT NOT NULL,
    id_estacion BIGINT REFERENCES auditoria.estaciones_trabajo(id_estacion) ON DELETE SET NULL,
    direccion_ip INET,
    fecha_evento TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Detalle normalizado: una fila por columna rastreada durante un evento.
CREATE TABLE auditoria.bitacora_detalles (
    id_detalle BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_evento BIGINT NOT NULL REFERENCES auditoria.bitacora_eventos(id_evento) ON DELETE RESTRICT,
    nombre_columna TEXT NOT NULL,
    valor_anterior JSONB,
    valor_nuevo JSONB
);

-- Índices para los filtros principales de búsqueda.
CREATE INDEX indice_eventos_fecha ON auditoria.bitacora_eventos (fecha_evento DESC, id_evento DESC);
CREATE INDEX indice_eventos_usuario ON auditoria.bitacora_eventos (id_usuario, fecha_evento DESC);
CREATE INDEX indice_eventos_tabla ON auditoria.bitacora_eventos (id_tabla, fecha_evento DESC);
CREATE INDEX indice_detalles_columna ON auditoria.bitacora_detalles (nombre_columna, id_evento);
CREATE INDEX indice_detalles_evento ON auditoria.bitacora_detalles (id_evento);
CREATE INDEX indice_reglas_columna ON auditoria.reglas_auditoria (id_columna, operacion);

-- Datos ficticios exclusivamente para practicar INSERT, UPDATE y DELETE.
CREATE TABLE public.clientes (
    id_cliente BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    correo VARCHAR(150),
    telefono VARCHAR(25),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO public.clientes (nombre, correo, telefono) VALUES
('Ana López', 'ana.ejemplo@example.com', '5555-1001'),
('Luis Méndez', 'luis.ejemplo@example.com', '5555-1002');

CREATE TABLE public.productos (
    id_producto BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    descripcion VARCHAR(120) NOT NULL,
    precio NUMERIC(12,2) NOT NULL CHECK (precio >= 0),
    existencia INTEGER NOT NULL DEFAULT 0
);
INSERT INTO public.productos (descripcion, precio, existencia) VALUES
('Mouse de prueba', 85.00, 15), ('Teclado de prueba', 190.00, 8);
COMMIT;
