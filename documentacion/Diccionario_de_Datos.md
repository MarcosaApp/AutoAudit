# Diccionario de datos — AutoAudit

Motor: PostgreSQL 18. Esquema de auditoría: `auditoria`. Tablas de laboratorio: `public`.

Tipos: `BIGINT` es entero de 64 bits; `TIMESTAMPTZ` almacena fecha y hora con zona; `JSONB` guarda JSON binario indexable; `INET` almacena direcciones IPv4/IPv6; `BOOLEAN` es verdadero/falso.

## 1. `auditoria.usuarios`

| Campo | Tipo | Regla | Propósito |
|---|---|---|---|
| id_usuario | BIGINT IDENTITY | PK | ID del usuario de la web |
| nombre_usuario | VARCHAR(80) | NOT NULL, UNIQUE | Identificador de inicio de sesión |
| contrasena_hash | TEXT | NOT NULL | Hash bcrypt generado con pgcrypto |
| rol | VARCHAR(20) | CHECK administrador/consulta | Permiso funcional de la interfaz |
| activo | BOOLEAN | DEFAULT TRUE | Habilita o bloquea el inicio de sesión |
| creado_en | TIMESTAMPTZ | DEFAULT current_timestamp | Alta de usuario |

## 2. `auditoria.estaciones_trabajo`

| Campo | Tipo | Regla | Propósito |
|---|---|---|---|
| id_estacion | BIGINT IDENTITY | PK | ID de estación aproximada |
| identificador | VARCHAR(64) | NOT NULL, UNIQUE | SHA-256 de IP + agente navegador |
| nombre | VARCHAR(120) | NOT NULL | Etiqueta descriptiva |
| direccion_ip | INET | NULL | Dirección declarada en solicitud |
| agente_navegador | TEXT | NULL | User-Agent recibido |
| ultimo_acceso | TIMESTAMPTZ | DEFAULT current_timestamp | Último acceso procesado |

## 3. `auditoria.tablas_auditadas`

| Campo | Tipo | Regla | Propósito |
|---|---|---|---|
| id_tabla | BIGINT IDENTITY | PK | Configuración de tabla |
| nombre_esquema | TEXT | NOT NULL, UNIQUE con nombre_tabla | Ejemplo public |
| nombre_tabla | TEXT | NOT NULL, UNIQUE con esquema | Ejemplo clientes |
| activa | BOOLEAN | DEFAULT TRUE | Permite pausar o reactivar |
| version_configuracion | INTEGER | DEFAULT 1 | Número de revisión guardado en bitácora |
| creada_en | TIMESTAMPTZ | DEFAULT current_timestamp | Fecha de alta de configuración |

## 4. `auditoria.columnas_auditadas`

| Campo | Tipo | Regla | Propósito |
|---|---|---|---|
| id_columna | BIGINT IDENTITY | PK | ID de columna elegida |
| id_tabla | BIGINT | FK -> tablas_auditadas | Tabla a la que pertenece |
| nombre_columna | TEXT | NOT NULL, UNIQUE con id_tabla | Nombre de columna en catálogo |
| activa | BOOLEAN | DEFAULT TRUE | Selección vigente |

## 5. `auditoria.reglas_auditoria`

| Campo | Tipo | Regla | Propósito |
|---|---|---|---|
| id_regla | BIGINT IDENTITY | PK | Regla individual |
| id_columna | BIGINT | FK -> columnas_auditadas | Columna monitorizada |
| operacion | VARCHAR(6) | CHECK INSERT/UPDATE/DELETE; UNIQUE con id_columna | Evento observado |

## 6. `auditoria.bitacora_eventos`

| Campo | Tipo | Regla | Propósito |
|---|---|---|---|
| id_evento | BIGINT IDENTITY | PK | ID del cambio |
| id_tabla | BIGINT | FK -> tablas_auditadas | Configuración que activó el evento |
| version_configuracion | INTEGER | NOT NULL | Versión que estaba activa |
| nombre_esquema | TEXT | NOT NULL | Esquema de origen preservado |
| nombre_tabla | TEXT | NOT NULL | Tabla de origen preservada |
| operacion | VARCHAR(6) | CHECK INSERT/UPDATE/DELETE | Tipo de acción SQL |
| clave_primaria | JSONB | DEFAULT {} | Clave de fila; puede ser compuesta |
| registro_anterior | JSONB | NULL | Instantánea OLD, incluso en DELETE |
| registro_nuevo | JSONB | NULL | Instantánea NEW, incluso en INSERT |
| id_usuario | BIGINT | FK -> usuarios, NULL | Usuario web identificado |
| usuario_base_datos | TEXT | NOT NULL | SESSION_USER de PostgreSQL |
| id_estacion | BIGINT | FK -> estaciones, NULL | Estación contextual |
| direccion_ip | INET | NULL | IP origen/contexto |
| fecha_evento | TIMESTAMPTZ | DEFAULT current_timestamp | Fecha del cambio |

**Importante:** No existe FK desde un evento hacia el registro origen. Eso es intencional: una fila eliminada no puede anular su propia evidencia histórica. La integridad referencial sí se preserva para el catálogo de auditoría, usuario y estación.

## 7. `auditoria.bitacora_detalles`

| Campo | Tipo | Regla | Propósito |
|---|---|---|---|
| id_detalle | BIGINT IDENTITY | PK | ID de detalle |
| id_evento | BIGINT | FK -> bitacora_eventos | Evento padre |
| nombre_columna | TEXT | NOT NULL | Columna que generó el detalle |
| valor_anterior | JSONB | NULL | OLD de la columna |
| valor_nuevo | JSONB | NULL | NEW de la columna |

## 8. `public.clientes` (datos de laboratorio)

| Campo | Tipo | Regla | Propósito |
|---|---|---|---|
| id_cliente | BIGINT IDENTITY | PK | Identificador del cliente |
| nombre | VARCHAR(120) | NOT NULL | Nombre del cliente |
| correo | VARCHAR(150) | NULL | Email ficticio |
| telefono | VARCHAR(25) | NULL | Teléfono ficticio |
| creado_en | TIMESTAMPTZ | DEFAULT current_timestamp | Alta |

## 9. `public.productos` (datos de laboratorio y rendimiento)

| Campo | Tipo | Regla | Propósito |
|---|---|---|---|
| id_producto | BIGINT IDENTITY | PK | Identificador |
| descripcion | VARCHAR(120) | NOT NULL | Nombre del producto |
| precio | NUMERIC(12,2) | CHECK >= 0 | Precio |
| existencia | INTEGER | DEFAULT 0 | Unidades disponibles |

## Relaciones y cardinalidades

- tablas_auditadas (1) -> columnas_auditadas (0..N).
- columnas_auditadas (1) -> reglas_auditoria (0..N).
- tablas_auditadas (1) -> bitacora_eventos (0..N).
- usuarios (0..1) -> bitacora_eventos (0..N), por la posibilidad de acceso directo a PostgreSQL.
- estaciones_trabajo (0..1) -> bitacora_eventos (0..N), cuando no hay datos de navegador.
- bitacora_eventos (1) -> bitacora_detalles (1..N) para eventos efectivos.

## Funciones almacenadas

| Función | Entrada | Salida | Uso |
|---|---|---|---|
| obtener_o_crear_estacion | identificador, nombre, ip, user-agent | BIGINT | Registrar contexto web |
| registrar_cambio | TG_ARGV, NEW, OLD, TG_OP (trigger) | TRIGGER | Guardar evento y detalles |
| generar_triggers | id_tabla | VOID | Crear/sustituir triggers AFTER |
| configurar_tabla | esquema, tabla, objeto JSONB | BIGINT | Guardar selección y regenerar |
| cambiar_estado | id_tabla, booleano | VOID | Pausar y reactivar |

Índices principales: `indice_eventos_fecha`, `indice_eventos_usuario`, `indice_eventos_tabla`, `indice_detalles_columna`, `indice_detalles_evento`, `indice_reglas_columna`.
