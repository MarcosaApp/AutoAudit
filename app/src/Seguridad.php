<?php
declare(strict_types=1);

/** BLOQUE 2: sesiones, permisos, escape de HTML y protección CSRF. */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}

function escapar_html(mixed $valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function usuario_autenticado(): ?array
{
    return $_SESSION['usuario_autenticado'] ?? null;
}

function requerir_sesion(): array
{
    $usuario = usuario_autenticado();
    if ($usuario === null) {
        header('Location: /?pagina=ingresar');
        exit;
    }
    return $usuario;
}

function requerir_administrador(): array
{
    $usuario = requerir_sesion();
    if (($usuario['rol'] ?? '') !== 'administrador') {
        http_response_code(403);
        exit('Acceso denegado: solo los administradores pueden configurar auditorías.');
    }
    return $usuario;
}

function token_csrf(): string
{
    if (!isset($_SESSION['token_csrf'])) {
        $_SESSION['token_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['token_csrf'];
}

function comprobar_csrf(): void
{
    if (!hash_equals(token_csrf(), (string)($_POST['token_csrf'] ?? ''))) {
        http_response_code(419);
        exit('Solicitud rechazada: token CSRF inválido.');
    }
}

function avisar(string $tipo, string $mensaje): void
{
    $_SESSION['aviso_temporal'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function ir_a(string $ruta): never
{
    header('Location: ' . $ruta);
    exit;
}

/** BLOQUE 3: asignar actor, estación e IP a la transacción actual. */
function ejecutar_cambio_auditable(callable $operacion): mixed
{
    $conexion = conectar_base_datos();
    $usuario = requerir_sesion();
    $direccion_ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($direccion_ip, FILTER_VALIDATE_IP)) {
        $direccion_ip = '';
    }
    $agente_navegador = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'No identificado'), 0, 1000);
    $identificador_estacion = hash('sha256', $direccion_ip . '|' . $agente_navegador);

    $conexion->beginTransaction();
    try {
        // B3.1 La función SQL crea/reutiliza la estación sin permiso de escritura directa.
        $consulta_estacion = $conexion->prepare(
            'SELECT auditoria.obtener_o_crear_estacion(:identificador, :nombre, CAST(:ip AS inet), :navegador)'
        );
        $consulta_estacion->execute([
            'identificador' => $identificador_estacion,
            'nombre' => 'Navegador web',
            'ip' => $direccion_ip !== '' ? $direccion_ip : null,
            'navegador' => $agente_navegador,
        ]);
        $id_estacion = (int)$consulta_estacion->fetchColumn();

        // B3.2 set_config(..., true) vale solo dentro de ESTA transacción.
        // Así el trigger puede leer qué usuario de la web modificó el registro.
        foreach ([
            'autoaudit.usuario_id' => (string)$usuario['id_usuario'],
            'autoaudit.estacion_id' => (string)$id_estacion,
            'autoaudit.ip_origen' => $direccion_ip,
        ] as $nombre_configuracion => $valor_configuracion) {
            $consulta_contexto = $conexion->prepare('SELECT set_config(:nombre, :valor, true)');
            $consulta_contexto->execute(['nombre' => $nombre_configuracion, 'valor' => $valor_configuracion]);
        }

        // B3.3 El cierre confirma el cambio y su bitácora en una única transacción.
        $resultado = $operacion($conexion);
        $conexion->commit();
        return $resultado;
    } catch (Throwable $error) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        throw $error;
    }
}
