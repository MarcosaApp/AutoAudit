<?php
declare(strict_types=1);

// BLOQUE 0. Punto único de entrada: cargar conexión, seguridad, consultas y HTML común.
require_once __DIR__ . '/../src/BaseDatos.php';
require_once __DIR__ . '/../src/Seguridad.php';
require_once __DIR__ . '/../src/Consultas.php';
require_once __DIR__ . '/../src/Presentacion.php';

$pagina_solicitada = (string)($_GET['pagina'] ?? 'inicio');
$mensaje_error = null;

// BLOQUE 1. Procesar formularios POST ANTES de imprimir HTML (se pueden redirigir).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    comprobar_csrf();
    $accion_solicitada = (string)($_POST['accion'] ?? '');
    try {
        if ($accion_solicitada === 'ingresar') {
            $nombre_usuario = trim((string)($_POST['nombre_usuario'] ?? ''));
            $contrasena = (string)($_POST['contrasena'] ?? '');
            $sentencia = conectar_base_datos()->prepare(
                'SELECT id_usuario, nombre_usuario, contrasena_hash, rol FROM auditoria.usuarios '
                . 'WHERE nombre_usuario = :nombre AND activo = TRUE LIMIT 1'
            );
            $sentencia->execute(['nombre' => $nombre_usuario]);
            $usuario_encontrado = $sentencia->fetch();
            if (!$usuario_encontrado || !password_verify($contrasena, $usuario_encontrado['contrasena_hash'])) {
                throw new RuntimeException('Usuario o contraseña incorrectos.');
            }
            session_regenerate_id(true);
            $_SESSION['usuario_autenticado'] = [
                'id_usuario' => (int)$usuario_encontrado['id_usuario'],
                'nombre_usuario' => $usuario_encontrado['nombre_usuario'],
                'rol' => $usuario_encontrado['rol'],
            ];
            avisar('success', 'Sesión iniciada correctamente.');
            ir_a('/?pagina=inicio');
        }

        if ($accion_solicitada === 'salir') {
            $_SESSION = [];
            session_regenerate_id(true);
            ir_a('/?pagina=ingresar');
        }

        // Los demás formularios requieren tener una sesión válida.
        requerir_sesion();
        $conexion = conectar_base_datos();

        if ($accion_solicitada === 'guardar_configuracion') {
            requerir_administrador();
            $esquema = (string)($_POST['esquema'] ?? '');
            $tabla = (string)($_POST['tabla'] ?? '');
            $tablas_disponibles = obtener_tablas_disponibles($conexion);
            $permitida = false;
            foreach ($tablas_disponibles as $tabla_disponible) {
                if ($tabla_disponible['esquema'] === $esquema && $tabla_disponible['tabla'] === $tabla) {
                    $permitida = true;
                    break;
                }
            }
            if (!$permitida) throw new RuntimeException('La tabla elegida no está disponible.');

            // El servidor valida de nuevo las columnas; no confía en el HTML enviado.
            $columnas_permitidas = array_column(obtener_columnas_tabla($conexion, $esquema, $tabla), 'nombre_columna');
            $selecciones_recibidas = $_POST['reglas'] ?? [];
            if (!is_array($selecciones_recibidas)) throw new RuntimeException('Configuración inválida.');
            $reglas_validas = [];
            foreach ($selecciones_recibidas as $nombre_columna => $eventos) {
                if (!in_array((string)$nombre_columna, $columnas_permitidas, true) || !is_array($eventos)) {
                    throw new RuntimeException('Se recibió una columna o lista incorrecta.');
                }
                $eventos = array_values(array_unique(array_map('strval', $eventos)));
                foreach ($eventos as $evento) {
                    if (!in_array($evento, ['INSERT','UPDATE','DELETE'], true)) {
                        throw new RuntimeException('Operación de auditoría no permitida.');
                    }
                }
                if ($eventos !== []) $reglas_validas[(string)$nombre_columna] = $eventos;
            }
            $consulta = $conexion->prepare(
                'SELECT auditoria.configurar_tabla(:esquema, :tabla, CAST(:reglas AS jsonb))'
            );
            $consulta->execute([
                'esquema' => $esquema, 'tabla' => $tabla,
                'reglas' => json_encode((object)$reglas_validas, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            avisar('success', 'Configuración guardada. Los triggers AFTER se generaron automáticamente.');
            ir_a('/?pagina=configuracion&tabla=' . rawurlencode($esquema . '.' . $tabla));
        }

        if ($accion_solicitada === 'cambiar_estado') {
            requerir_administrador();
            $identificador_tabla = (int)($_POST['id_tabla'] ?? 0);
            $estado_deseado = (string)($_POST['estado'] ?? '') === 'activar';
            $consulta = $conexion->prepare('SELECT auditoria.cambiar_estado(:id, :estado)');
            $consulta->bindValue(':id', $identificador_tabla, PDO::PARAM_INT);
            $consulta->bindValue(':estado', $estado_deseado, PDO::PARAM_BOOL);
            $consulta->execute();
            avisar('success', $estado_deseado ? 'Auditoría reactivada.' : 'Auditoría pausada, histórico preservado.');
            ir_a('/?pagina=configuracion');
        }

        // Laboratorio: todas las operaciones se ejecutan junto a contexto de auditoría.
        if ($accion_solicitada === 'crear_cliente') {
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $correo = trim((string)($_POST['correo'] ?? ''));
            $telefono = trim((string)($_POST['telefono'] ?? ''));
            if ($nombre === '' || strlen($nombre) > 120) throw new RuntimeException('El nombre es obligatorio y debe tener 120 caracteres o menos.');
            ejecutar_cambio_auditable(function (PDO $base) use ($nombre, $correo, $telefono): void {
                $consulta = $base->prepare('INSERT INTO public.clientes (nombre, correo, telefono) VALUES (:nombre,:correo,:telefono)');
                $consulta->execute(['nombre' => $nombre, 'correo' => $correo, 'telefono' => $telefono]);
            });
            avisar('success', 'Cliente creado; revisa el historial de INSERT si está habilitado.');
            ir_a('/?pagina=clientes');
        }

        if ($accion_solicitada === 'actualizar_cliente') {
            $identificador_cliente = (int)($_POST['id_cliente'] ?? 0);
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $correo = trim((string)($_POST['correo'] ?? ''));
            $telefono = trim((string)($_POST['telefono'] ?? ''));
            if ($identificador_cliente < 1 || $nombre === '' || strlen($nombre) > 120) {
                throw new RuntimeException('Identificador o nombre de cliente inválido.');
            }
            ejecutar_cambio_auditable(function (PDO $base) use ($identificador_cliente, $nombre, $correo, $telefono): void {
                $consulta = $base->prepare('UPDATE public.clientes SET nombre=:nombre, correo=:correo, telefono=:telefono WHERE id_cliente=:id');
                $consulta->execute(['nombre' => $nombre, 'correo' => $correo, 'telefono' => $telefono, 'id' => $identificador_cliente]);
            });
            avisar('success', 'Cliente actualizado; el trigger compara OLD contra NEW.');
            ir_a('/?pagina=clientes');
        }

        if ($accion_solicitada === 'eliminar_cliente') {
            $identificador_cliente = (int)($_POST['id_cliente'] ?? 0);
            if ($identificador_cliente < 1) throw new RuntimeException('Identificador de cliente inválido.');
            ejecutar_cambio_auditable(function (PDO $base) use ($identificador_cliente): void {
                $consulta = $base->prepare('DELETE FROM public.clientes WHERE id_cliente = :id');
                $consulta->execute(['id' => $identificador_cliente]);
            });
            avisar('success', 'Cliente eliminado. La instantánea OLD permanece en la bitácora.');
            ir_a('/?pagina=clientes');
        }
        throw new RuntimeException('Acción desconocida.');
    } catch (Throwable $error) {
        // No mostrar contraseñas ni consultas internas a los usuarios de la aplicación.
        error_log('[AutoAudit] ' . $error->getMessage());
        $mensaje_error = $error instanceof RuntimeException ? $error->getMessage()
                       : 'No se pudo completar la operación. Revisa los registros del servidor.';
    }
}

// BLOQUE 2. Página de ingreso: no requiere haber iniciado sesión.
if ($pagina_solicitada === 'ingresar' && usuario_autenticado() === null) {
    presentar_inicio('Acceder');
    if ($mensaje_error): ?><div class="alert alert-danger"><?= escapar_html($mensaje_error) ?></div><?php endif; ?>
    <div class="row justify-content-center"><div class="col-md-6 col-lg-5"><section class="panel p-4 p-lg-5">
      <div class="etiqueta-pequena mb-2">Acceso seguro</div><h1 class="h3 fw-bold">Ingresar a AutoAudit</h1>
      <p class="text-secondary">Usa las credenciales indicadas en tu archivo .env.</p>
      <form method="post">
        <input type="hidden" name="accion" value="ingresar"><input type="hidden" name="token_csrf" value="<?= token_csrf() ?>">
        <label class="form-label mt-2" for="nombre_usuario">Usuario</label>
        <input class="form-control" name="nombre_usuario" id="nombre_usuario" required autocomplete="username">
        <label class="form-label mt-3" for="contrasena">Contraseña</label>
        <input class="form-control" name="contrasena" id="contrasena" type="password" required autocomplete="current-password">
        <button class="btn btn-primary w-100 mt-4" type="submit">Iniciar sesión</button>
      </form>
    </section></div></div>
    <?php presentar_fin(); exit;
}

// BLOQUE 3. Exportar CSV ANTES de HTML, para que PHP pueda enviar las cabeceras HTTP.
if ($pagina_solicitada === 'exportar') {
    requerir_sesion();
    $conexion_exportacion = conectar_base_datos();
    $filtros_exportacion = obtener_filtros_historial();
    $eventos_exportados = consultar_historial($conexion_exportacion, $filtros_exportacion, 10000, 0);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="autoaudit_historial_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $salida_csv = fopen('php://output', 'w');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 para facilitar apertura con Excel.
    fputcsv($salida_csv, ['ID','Fecha','Tabla','Evento','Usuario','IP','Clave primaria','OLD','NEW'], ',', '"', '');
    foreach ($eventos_exportados as $evento_csv) {
        // Neutralizar fórmulas que programas de hojas de cálculo podrían ejecutar.
        $datos_csv = [
            $evento_csv['id_evento'], $evento_csv['fecha_evento'],
            $evento_csv['nombre_esquema'] . '.' . $evento_csv['nombre_tabla'],
            $evento_csv['operacion'],
            $evento_csv['nombre_usuario'] ?? $evento_csv['usuario_base_datos'],
            $evento_csv['direccion_ip'], $evento_csv['clave_primaria'],
            $evento_csv['registro_anterior'], $evento_csv['registro_nuevo'],
        ];
        $datos_csv = array_map(static function ($dato): string {
            $texto = (string)($dato ?? '');
            return preg_match('/^[\s]*[=+@-]/u', $texto) ? "'" . $texto : $texto;
        }, $datos_csv);
        fputcsv($salida_csv, $datos_csv, ',', '"', '');
    }
    fclose($salida_csv);
    exit;
}

// BLOQUE 4. Todas las pantallas siguientes requieren sesión.
$usuario = requerir_sesion();
$conexion = conectar_base_datos();
presentar_inicio(ucfirst($pagina_solicitada));
if ($mensaje_error): ?><div class="alert alert-danger"><?= escapar_html($mensaje_error) ?></div><?php endif;

if ($pagina_solicitada === 'inicio') {
    encabezado_pagina('Panel de control', 'Estado de la auditoría, actividad reciente y acceso a los módulos.');
    $cifras = $conexion->query(<<<'SQL'
        SELECT (SELECT COUNT(*) FROM auditoria.tablas_auditadas WHERE activa) AS tablas_activas,
               (SELECT COUNT(*) FROM auditoria.bitacora_eventos) AS total_eventos,
               (SELECT COUNT(*) FROM auditoria.bitacora_eventos WHERE fecha_evento >= NOW() - INTERVAL '24 hours') AS ultimas_24_horas
    SQL)->fetch();
    ?>
    <div class="row g-3 mb-4">
       <?php foreach (['tablas_activas'=>'Tablas activas','total_eventos'=>'Cambios registrados','ultimas_24_horas'=>'Últimas 24 horas'] as $clave => $nombre): ?>
       <div class="col-md-4"><div class="panel p-4"><div class="etiqueta-pequena"><?= escapar_html($nombre) ?></div><div class="numero-panel"><?= (int)$cifras[$clave] ?></div></div></div>
       <?php endforeach; ?>
    </div>
    <div class="row g-3"><div class="col-md-7"><section class="panel p-4 h-100">
      <h2 class="h5 fw-bold">Cómo funciona</h2>
      <ol class="mb-0 text-secondary"><li>Selecciona una tabla y las columnas a auditar.</li><li>Marca INSERT, UPDATE y/o DELETE.</li><li>AutoAudit genera los triggers AFTER.</li><li>Modifica registros en el laboratorio.</li><li>Filtra el historial y exporta CSV.</li></ol>
    </section></div><div class="col-md-5"><section class="panel p-4 h-100">
      <h2 class="h5 fw-bold">Accesos rápidos</h2>
      <div class="d-grid gap-2">
       <?php if ($usuario['rol'] === 'administrador'): ?><a class="btn btn-primary" href="/?pagina=configuracion">Configurar auditoría</a><?php endif; ?>
       <a class="btn btn-outline-primary" href="/?pagina=clientes">Probar CRUD de clientes</a>
       <a class="btn btn-outline-primary" href="/?pagina=historial">Consultar bitácora</a>
      </div>
    </section></div></div>
    <?php
} elseif ($pagina_solicitada === 'configuracion') {
    requerir_administrador();
    encabezado_pagina('Configuración de auditoría', 'Selecciona las columnas y los eventos que deben registrarse.');
    $tablas_disponibles = obtener_tablas_disponibles($conexion);
    $tabla_elegida = (string)($_GET['tabla'] ?? '');
    if ($tabla_elegida === '' && count($tablas_disponibles) > 0) {
        $tabla_elegida = $tablas_disponibles[0]['esquema'] . '.' . $tablas_disponibles[0]['tabla'];
    }
    $esquema = ''; $tabla = ''; $tabla_valida = false;
    foreach ($tablas_disponibles as $item_tabla) {
        if ($tabla_elegida === $item_tabla['esquema'] . '.' . $item_tabla['tabla']) {
            $tabla_valida = true; $esquema = $item_tabla['esquema']; $tabla = $item_tabla['tabla']; break;
        }
    }
    ?>
    <section class="panel p-4 mb-4">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="pagina" value="configuracion">
      <div class="col-md-8"><label class="form-label" for="tabla">Tabla a configurar</label>
        <select class="form-select" name="tabla" id="tabla">
        <?php foreach ($tablas_disponibles as $item_tabla): $nombre_completo = $item_tabla['esquema'] . '.' . $item_tabla['tabla']; ?>
          <option value="<?= escapar_html($nombre_completo) ?>" <?= $nombre_completo === $tabla_elegida ? 'selected' : '' ?>><?= escapar_html($nombre_completo) ?></option>
        <?php endforeach; ?>
        </select>
      </div><div class="col-md-4"><button class="btn btn-outline-primary w-100">Ver columnas</button></div>
    </form></section>
    <?php if ($tabla_valida):
        $columnas = obtener_columnas_tabla($conexion, $esquema, $tabla);
        $consulta_actual = $conexion->prepare(
            'SELECT id_tabla, activa, version_configuracion FROM auditoria.tablas_auditadas WHERE nombre_esquema=:esquema AND nombre_tabla=:tabla'
        );
        $consulta_actual->execute(['esquema' => $esquema, 'tabla' => $tabla]);
        $configuracion_actual = $consulta_actual->fetch();
        $reglas_activas = [];
        if ($configuracion_actual) {
            $consulta_reglas = $conexion->prepare(<<<'SQL'
                SELECT columna.nombre_columna, regla.operacion
                FROM auditoria.columnas_auditadas columna
                JOIN auditoria.reglas_auditoria regla ON regla.id_columna=columna.id_columna
                WHERE columna.id_tabla=:id AND columna.activa
            SQL);
            $consulta_reglas->execute(['id' => $configuracion_actual['id_tabla']]);
            foreach ($consulta_reglas->fetchAll() as $regla) {
                $reglas_activas[$regla['nombre_columna']][] = $regla['operacion'];
            }
        }
    ?>
    <section class="panel p-4 mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div><h2 class="h5 fw-bold mb-1">Columnas de <?= escapar_html($tabla_elegida) ?></h2>
              <p class="small text-secondary mb-0">Cada casilla crea una regla que el trigger leerá al modificarse la fila.</p></div>
            <span class="badge <?= $configuracion_actual && $configuracion_actual['activa'] ? 'text-bg-success' : 'text-bg-secondary' ?>">
              <?= $configuracion_actual ? ($configuracion_actual['activa'] ? 'Auditoría activa' : 'Pausada') : 'Sin configurar' ?>
            </span>
        </div>
        <form method="post">
            <input type="hidden" name="accion" value="guardar_configuracion">
            <input type="hidden" name="token_csrf" value="<?= token_csrf() ?>">
            <input type="hidden" name="esquema" value="<?= escapar_html($esquema) ?>">
            <input type="hidden" name="tabla" value="<?= escapar_html($tabla) ?>">
            <div class="table-responsive"><table class="table table-hover tabla-borde">
            <thead><tr><th>Columna</th><th>Tipo</th><th>INSERT</th><th>UPDATE</th><th>DELETE</th></tr></thead><tbody>
            <?php foreach ($columnas as $columna): ?>
             <tr><td class="fw-semibold"><?= escapar_html($columna['nombre_columna']) ?></td><td class="small text-secondary"><?= escapar_html($columna['tipo_dato']) ?></td>
               <?php foreach (['INSERT','UPDATE','DELETE'] as $operacion):
                   $nombre_columna = $columna['nombre_columna'];
                   $seleccionada = in_array($operacion, $reglas_activas[$nombre_columna] ?? [], true);
               ?>
                 <td class="celda-seleccion"><input class="form-check-input" type="checkbox" name="reglas[<?= escapar_html($nombre_columna) ?>][]" value="<?= $operacion ?>" <?= $seleccionada ? 'checked' : '' ?> aria-label="<?= escapar_html($operacion . ' en ' . $nombre_columna) ?>"></td>
               <?php endforeach; ?>
             </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <button type="submit" class="btn btn-primary mt-2">Guardar reglas y generar triggers</button>
        </form>
        <?php if ($configuracion_actual): ?>
          <hr><form method="post" class="d-flex align-items-center gap-3 flex-wrap">
            <input type="hidden" name="accion" value="cambiar_estado">
            <input type="hidden" name="token_csrf" value="<?= token_csrf() ?>">
            <input type="hidden" name="id_tabla" value="<?= (int)$configuracion_actual['id_tabla'] ?>">
            <input type="hidden" name="estado" value="<?= $configuracion_actual['activa'] ? 'pausar' : 'activar' ?>">
            <button type="submit" class="btn btn-outline-secondary"><?= $configuracion_actual['activa'] ? 'Pausar auditoría' : 'Reactivar auditoría' ?></button>
            <span class="text-secondary small">Versión de configuración: <?= (int)$configuracion_actual['version_configuracion'] ?>. No se borran eventos anteriores.</span>
          </form>
        <?php endif; ?>
    </section>
    <?php endif;
} elseif ($pagina_solicitada === 'clientes') {
    encabezado_pagina('Laboratorio de clientes', 'Haz INSERT, UPDATE y DELETE para verificar la auditoría en tiempo real.');
    $clientes = $conexion->query('SELECT id_cliente, nombre, correo, telefono FROM public.clientes ORDER BY id_cliente DESC LIMIT 100')->fetchAll();
    ?>
    <div class="alert alert-info">Primero habilita la auditoría de <code>public.clientes</code> en Configuración. Esta pantalla usa el mismo PostgreSQL que los triggers.</div>
    <section class="panel p-4 mb-4"><h2 class="h5 fw-bold">Registrar cliente (INSERT)</h2>
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="accion" value="crear_cliente"><input type="hidden" name="token_csrf" value="<?= token_csrf() ?>">
        <div class="col-md-4"><label class="form-label">Nombre</label><input class="form-control" name="nombre" maxlength="120" required placeholder="Ej. María López"></div>
        <div class="col-md-3"><label class="form-label">Correo</label><input class="form-control" name="correo" type="email" maxlength="150" placeholder="correo@ejemplo.com"></div>
        <div class="col-md-3"><label class="form-label">Teléfono</label><input class="form-control" name="telefono" maxlength="25" placeholder="5555-1111"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Guardar</button></div>
      </form>
    </section>
    <section class="panel p-4"><h2 class="h5 fw-bold mb-3">Clientes existentes</h2>
      <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>ID</th><th>Nombre</th><th>Correo</th><th>Teléfono</th><th>Acciones</th></tr></thead><tbody>
      <?php foreach ($clientes as $cliente): $id = (int)$cliente['id_cliente']; ?>
      <tr><td><?= $id ?></td><td><?= escapar_html($cliente['nombre']) ?></td><td><?= escapar_html($cliente['correo']) ?></td><td><?= escapar_html($cliente['telefono']) ?></td><td>
       <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editar<?= $id ?>">Editar</button>
       <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este cliente? Se guardará en la bitácora si DELETE está activado.');">
         <input type="hidden" name="accion" value="eliminar_cliente"><input type="hidden" name="token_csrf" value="<?= token_csrf() ?>">
         <input type="hidden" name="id_cliente" value="<?= $id ?>"><button class="btn btn-sm btn-outline-danger">Eliminar</button>
       </form>
      </td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    </section>
    <?php foreach ($clientes as $cliente): $id=(int)$cliente['id_cliente']; ?>
     <div class="modal fade" id="editar<?= $id ?>" tabindex="-1" aria-hidden="true">
       <div class="modal-dialog"><div class="modal-content"><form method="post">
          <div class="modal-header"><h2 class="modal-title h5">Editar cliente #<?= $id ?> (UPDATE)</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <input type="hidden" name="accion" value="actualizar_cliente"><input type="hidden" name="token_csrf" value="<?= token_csrf() ?>">
            <input type="hidden" name="id_cliente" value="<?= $id ?>">
            <?php foreach (['nombre'=>'Nombre','correo'=>'Correo','telefono'=>'Teléfono'] as $campo => $etiqueta): ?>
              <label class="form-label" for="<?= $campo . $id ?>"><?= $etiqueta ?></label>
              <input class="form-control mb-3" id="<?= $campo . $id ?>" name="<?= $campo ?>" value="<?= escapar_html($cliente[$campo]) ?>" <?= $campo === 'nombre' ? 'required' : '' ?> maxlength="<?= $campo === 'nombre' ? 120 : ($campo === 'correo' ? 150 : 25) ?>">
            <?php endforeach; ?>
          </div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Guardar actualización</button></div>
       </form></div></div>
     </div>
    <?php endforeach;
} elseif ($pagina_solicitada === 'historial') {
    encabezado_pagina('Historial de auditoría', 'Busca por usuario, tabla, evento, columna o intervalo de fechas; exporta los resultados.');
    $filtros = obtener_filtros_historial();
    $pagina_actual = max(1, min(10000, (int)($_GET['numero'] ?? 1)));
    $resultados = consultar_historial($conexion, $filtros, 51, ($pagina_actual - 1) * 50);
    $hay_siguiente = count($resultados) > 50;
    $resultados = array_slice($resultados, 0, 50);
    $tablas = $conexion->query('SELECT id_tabla, nombre_esquema, nombre_tabla FROM auditoria.tablas_auditadas ORDER BY nombre_tabla')->fetchAll();
    $usuarios = $conexion->query('SELECT id_usuario, nombre_usuario FROM auditoria.usuarios ORDER BY nombre_usuario')->fetchAll();
    ?>
    <section class="panel p-4 mb-4"><form method="get" class="row g-3">
       <input type="hidden" name="pagina" value="historial">
       <div class="col-lg-3 col-md-6"><label class="form-label">Tabla</label><select class="form-select" name="tabla"><option value="0">Todas</option><?php foreach ($tablas as $item): ?><option value="<?= (int)$item['id_tabla'] ?>" <?= $filtros['tabla']==(int)$item['id_tabla'] ? 'selected' : '' ?>><?= escapar_html($item['nombre_esquema'] . '.' . $item['nombre_tabla']) ?></option><?php endforeach; ?></select></div>
       <div class="col-lg-3 col-md-6"><label class="form-label">Usuario</label><select class="form-select" name="usuario"><option value="0">Todos</option><?php foreach ($usuarios as $item): ?><option value="<?= (int)$item['id_usuario'] ?>" <?= $filtros['usuario']==(int)$item['id_usuario'] ? 'selected' : '' ?>><?= escapar_html($item['nombre_usuario']) ?></option><?php endforeach; ?></select></div>
       <div class="col-lg-3 col-md-6"><label class="form-label">Evento</label><select class="form-select" name="operacion"><option value="">Todos</option><?php foreach (['INSERT','UPDATE','DELETE'] as $tipo): ?><option <?= $filtros['operacion']===$tipo ? 'selected' : '' ?>><?= $tipo ?></option><?php endforeach; ?></select></div>
       <div class="col-lg-3 col-md-6"><label class="form-label">Columna exacta</label><input class="form-control" name="columna" value="<?= escapar_html($filtros['columna']) ?>" placeholder="Ej. telefono"></div>
       <div class="col-lg-3 col-md-6"><label class="form-label">Desde</label><input class="form-control" name="desde" type="date" value="<?= escapar_html($filtros['desde']) ?>"></div>
       <div class="col-lg-3 col-md-6"><label class="form-label">Hasta</label><input class="form-control" name="hasta" type="date" value="<?= escapar_html($filtros['hasta']) ?>"></div>
       <div class="col-lg-6 col-md-12 d-flex align-items-end gap-2"><button class="btn btn-primary">Buscar cambios</button><a class="btn btn-outline-secondary" href="/?pagina=historial">Limpiar</a><a class="btn btn-outline-success" href="/?<?= escapar_html(http_build_query(array_merge($filtros,['pagina'=>'exportar']))) ?>">Exportar CSV</a></div>
    </form></section>
    <section class="panel p-4"><div class="table-responsive"><table class="table table-hover tabla-borde"><thead><tr><th>Evento</th><th>Fecha</th><th>Tabla</th><th>Operación</th><th>Usuario</th><th>Columnas</th><th></th></tr></thead><tbody>
       <?php foreach ($resultados as $evento): ?>
       <tr><td>#<?= (int)$evento['id_evento'] ?></td><td class="small"><?= escapar_html($evento['fecha_evento']) ?></td><td><?= escapar_html($evento['nombre_tabla']) ?></td>
       <td><span class="badge text-bg-<?= ['INSERT'=>'success','UPDATE'=>'warning','DELETE'=>'danger'][$evento['operacion']] ?? 'secondary' ?>"><?= escapar_html($evento['operacion']) ?></span></td>
       <td><?= escapar_html($evento['nombre_usuario'] ?? $evento['usuario_base_datos']) ?></td><td><?= (int)$evento['columnas_modificadas'] ?></td><td><a class="btn btn-sm btn-outline-primary" href="/?pagina=detalle&id=<?= (int)$evento['id_evento'] ?>">Detalle</a></td></tr>
       <?php endforeach; ?>
       <?php if (!$resultados): ?><tr><td colspan="7" class="text-secondary text-center py-4">No hay cambios que coincidan con los filtros. Configura una tabla y prueba el laboratorio.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="d-flex gap-2 justify-content-end"><span class="text-secondary small me-auto">Página <?= $pagina_actual ?></span>
      <?php if ($pagina_actual > 1): ?><a class="btn btn-sm btn-outline-secondary" href="/?<?= escapar_html(http_build_query(array_merge($_GET,['numero'=>$pagina_actual-1]))) ?>">Anterior</a><?php endif; ?>
      <?php if ($hay_siguiente): ?><a class="btn btn-sm btn-outline-secondary" href="/?<?= escapar_html(http_build_query(array_merge($_GET,['numero'=>$pagina_actual+1]))) ?>">Siguiente</a><?php endif; ?>
    </div></section>
    <?php
} elseif ($pagina_solicitada === 'detalle') {
    $identificador_evento = max(0, (int)($_GET['id'] ?? 0));
    $consulta = $conexion->prepare(<<<'SQL'
        SELECT evento.*, usuario.nombre_usuario, estacion.nombre AS nombre_estacion
        FROM auditoria.bitacora_eventos evento
        LEFT JOIN auditoria.usuarios usuario ON usuario.id_usuario=evento.id_usuario
        LEFT JOIN auditoria.estaciones_trabajo estacion ON estacion.id_estacion=evento.id_estacion
        WHERE evento.id_evento=:id
    SQL);
    $consulta->execute(['id' => $identificador_evento]);
    $evento = $consulta->fetch();
    if (!$evento) {
        echo '<div class="alert alert-warning">No se encontró el evento solicitado.</div>';
    } else {
        $consulta_detalles = $conexion->prepare('SELECT nombre_columna, valor_anterior, valor_nuevo FROM auditoria.bitacora_detalles WHERE id_evento=:id ORDER BY id_detalle');
        $consulta_detalles->execute(['id' => $identificador_evento]);
        $detalles = $consulta_detalles->fetchAll();
        encabezado_pagina('Evento #' . $identificador_evento, 'Datos anteriores y nuevos, usuario, estación e instantánea del registro.');
        ?>
        <a href="/?pagina=historial" class="btn btn-outline-secondary mb-3">Volver al historial</a>
        <section class="panel p-4 mb-4"><dl class="row mb-0">
         <?php foreach (['Operación'=>$evento['operacion'],'Tabla'=>$evento['nombre_esquema'].'.'.$evento['nombre_tabla'],'Fecha'=>$evento['fecha_evento'],'Usuario web'=>$evento['nombre_usuario'] ?? '(consulta SQL directa)','Usuario PostgreSQL'=>$evento['usuario_base_datos'],'IP'=>$evento['direccion_ip'] ?? 'No disponible','Estación'=>$evento['nombre_estacion'] ?? 'No identificada','Versión config.'=>$evento['version_configuracion']] as $etiqueta=>$valor): ?>
           <dt class="col-sm-3"><?= escapar_html($etiqueta) ?></dt><dd class="col-sm-9"><?= escapar_html($valor) ?></dd>
         <?php endforeach; ?>
        </dl><div class="fw-semibold mt-3 mb-2">Clave primaria</div><?= representar_json($evento['clave_primaria']) ?></section>
        <section class="panel p-4 mb-4"><h2 class="h5 fw-bold">Columnas monitoreadas</h2><div class="table-responsive"><table class="table"><thead><tr><th>Columna</th><th>OLD (anterior)</th><th>NEW (nuevo)</th></tr></thead><tbody>
        <?php foreach ($detalles as $detalle): ?><tr><td><?= escapar_html($detalle['nombre_columna']) ?></td><td><?= representar_json($detalle['valor_anterior']) ?></td><td><?= representar_json($detalle['valor_nuevo']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></section>
        <div class="row g-3"><div class="col-md-6"><section class="panel p-4 h-100"><h2 class="h6 fw-bold">Instantánea anterior</h2><?= representar_json($evento['registro_anterior']) ?></section></div><div class="col-md-6"><section class="panel p-4 h-100"><h2 class="h6 fw-bold">Instantánea nueva</h2><?= representar_json($evento['registro_nuevo']) ?></section></div></div>
        <?php
    }
} elseif ($pagina_solicitada === 'exportar') {
    // Esta ruta se resuelve antes de mandar HTML en la revisión al final del archivo.
    echo '<div class="alert alert-warning">No se pudo iniciar la descarga. Consulta los registros.</div>';
} else {
    http_response_code(404);
    echo '<div class="alert alert-warning">Página no encontrada.</div>';
}
presentar_fin();
