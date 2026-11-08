<?php
declare(strict_types=1);

/** BLOQUE 1: conexión PDO a PostgreSQL con errores explícitos y consultas preparadas. */
function conectar_base_datos(): PDO
{
    static $conexion_compartida = null;
    if ($conexion_compartida instanceof PDO) {
        return $conexion_compartida;
    }
    $servidor = getenv('DB_SERVIDOR') ?: '127.0.0.1';
    $puerto = getenv('DB_PUERTO') ?: '5432';
    $nombre_base_datos = getenv('DB_NOMBRE') ?: 'autoaudit';
    $nombre_usuario = getenv('DB_USUARIO') ?: 'autoaudit_aplicacion';
    $contrasena = getenv('DB_CLAVE') ?: '';

    $conexion_compartida = new PDO(
        "pgsql:host={$servidor};port={$puerto};dbname={$nombre_base_datos}",
        $nombre_usuario,
        $contrasena,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    return $conexion_compartida;
}
