<?php
declare(strict_types=1);

/** BLOQUE 4: lectura del catálogo del DBMS (las tablas NO están codificadas a mano). */
function obtener_tablas_disponibles(PDO $conexion): array
{
    return $conexion->query(<<<'SQL'
        SELECT espacio.nspname AS esquema, clase.relname AS tabla
        FROM pg_catalog.pg_class clase
        JOIN pg_catalog.pg_namespace espacio ON espacio.oid = clase.relnamespace
        WHERE espacio.nspname = 'public' AND clase.relkind = 'r'
          AND pg_catalog.has_table_privilege(clase.oid, 'SELECT')
        ORDER BY clase.relname
    SQL)->fetchAll();
}

function obtener_columnas_tabla(PDO $conexion, string $esquema, string $tabla): array
{
    $consulta = $conexion->prepare(<<<'SQL'
        SELECT atributo.attname AS nombre_columna,
               pg_catalog.format_type(atributo.atttypid, atributo.atttypmod) AS tipo_dato
        FROM pg_catalog.pg_attribute atributo
        JOIN pg_catalog.pg_class clase ON clase.oid = atributo.attrelid
        JOIN pg_catalog.pg_namespace espacio ON espacio.oid = clase.relnamespace
        WHERE espacio.nspname = :esquema AND clase.relname = :tabla
          AND clase.relkind = 'r' AND atributo.attnum > 0
          AND NOT atributo.attisdropped
        ORDER BY atributo.attnum
    SQL);
    $consulta->execute(['esquema' => $esquema, 'tabla' => $tabla]);
    return $consulta->fetchAll();
}

/** BLOQUE 5: filtros con parámetros; no concatenar entradas del usuario dentro del SQL. */
function obtener_filtros_historial(): array
{
    return [
        'tabla' => max(0, (int)($_GET['tabla'] ?? 0)),
        'usuario' => max(0, (int)($_GET['usuario'] ?? 0)),
        'operacion' => strtoupper((string)($_GET['operacion'] ?? '')),
        'columna' => trim((string)($_GET['columna'] ?? '')),
        'desde' => trim((string)($_GET['desde'] ?? '')),
        'hasta' => trim((string)($_GET['hasta'] ?? '')),
    ];
}

function construir_consulta_historial(array $filtros, int $limite, int $desplazamiento): array
{
    $condiciones = [];
    $parametros = [];
    if ($filtros['tabla'] > 0) {
        $condiciones[] = 'evento.id_tabla = :tabla';
        $parametros['tabla'] = $filtros['tabla'];
    }
    if ($filtros['usuario'] > 0) {
        $condiciones[] = 'evento.id_usuario = :usuario';
        $parametros['usuario'] = $filtros['usuario'];
    }
    if (in_array($filtros['operacion'], ['INSERT','UPDATE','DELETE'], true)) {
        $condiciones[] = 'evento.operacion = :operacion';
        $parametros['operacion'] = $filtros['operacion'];
    }
    if ($filtros['columna'] !== '') {
        $condiciones[] = 'EXISTS (SELECT 1 FROM auditoria.bitacora_detalles detalle_filtro '
                       . 'WHERE detalle_filtro.id_evento = evento.id_evento AND detalle_filtro.nombre_columna = :columna)';
        $parametros['columna'] = $filtros['columna'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros['desde'])) {
        $condiciones[] = 'evento.fecha_evento >= CAST(:desde AS date)';
        $parametros['desde'] = $filtros['desde'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros['hasta'])) {
        $condiciones[] = 'evento.fecha_evento < (CAST(:hasta AS date) + INTERVAL \'1 day\')';
        $parametros['hasta'] = $filtros['hasta'];
    }
    $filtro_sql = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';
    $consulta_sql = <<<SQL
        SELECT evento.id_evento, evento.fecha_evento, evento.nombre_esquema, evento.nombre_tabla,
               evento.operacion, evento.clave_primaria, evento.version_configuracion,
               evento.direccion_ip, evento.usuario_base_datos, evento.registro_anterior,
               evento.registro_nuevo, usuario.nombre_usuario, estacion.nombre AS estacion,
               (SELECT COUNT(*) FROM auditoria.bitacora_detalles detalle WHERE detalle.id_evento=evento.id_evento) AS columnas_modificadas
        FROM auditoria.bitacora_eventos evento
        LEFT JOIN auditoria.usuarios usuario ON usuario.id_usuario = evento.id_usuario
        LEFT JOIN auditoria.estaciones_trabajo estacion ON estacion.id_estacion = evento.id_estacion
        $filtro_sql
        ORDER BY evento.fecha_evento DESC, evento.id_evento DESC
        LIMIT :limite OFFSET :desplazamiento
    SQL;
    $parametros['limite'] = $limite;
    $parametros['desplazamiento'] = $desplazamiento;
    return [$consulta_sql, $parametros];
}

function consultar_historial(PDO $conexion, array $filtros, int $limite = 100, int $desplazamiento = 0): array
{
    [$consulta_sql, $parametros] = construir_consulta_historial($filtros, $limite, $desplazamiento);
    $consulta = $conexion->prepare($consulta_sql);
    foreach ($parametros as $nombre => $valor) {
        $consulta->bindValue(':' . $nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $consulta->execute();
    return $consulta->fetchAll();
}
