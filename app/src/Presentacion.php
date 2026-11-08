<?php
declare(strict_types=1);

/** BLOQUE 6: plantilla HTML común usando Bootstrap 5.3.8. */
function presentar_inicio(string $titulo): void
{
    $usuario = usuario_autenticado();
    $pagina_actual = (string)($_GET['pagina'] ?? 'inicio');
    $aviso = $_SESSION['aviso_temporal'] ?? null;
    unset($_SESSION['aviso_temporal']);
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escapar_html($titulo) ?> | AutoAudit</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/estilos.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark barra-superior">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/?pagina=inicio"><span class="marca-circulo">A</span> AutoAudit</a>
        <?php if ($usuario): ?>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navegacion"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="navegacion">
            <ul class="navbar-nav me-auto ms-lg-4">
                <?php foreach (['inicio'=>'Panel', 'configuracion'=>'Configuración', 'clientes'=>'Laboratorio', 'historial'=>'Historial'] as $ruta => $etiqueta): ?>
                <?php if ($ruta === 'configuracion' && $usuario['rol'] !== 'administrador') continue; ?>
                <li class="nav-item"><a class="nav-link <?= $pagina_actual === $ruta ? 'active' : '' ?>" href="/?pagina=<?= $ruta ?>"><?= $etiqueta ?></a></li>
                <?php endforeach; ?>
            </ul>
            <span class="navbar-text me-3"><?= escapar_html($usuario['nombre_usuario']) ?> · <?= escapar_html($usuario['rol']) ?></span>
            <form method="post" class="m-0"><input type="hidden" name="accion" value="salir"><input type="hidden" name="token_csrf" value="<?= token_csrf() ?>"><button class="btn btn-sm btn-outline-light">Salir</button></form>
        </div>
        <?php endif; ?>
    </div>
</nav>
<main class="container py-4 py-lg-5">
<?php if ($aviso): ?>
    <div class="alert alert-<?= escapar_html($aviso['tipo']) ?> alert-dismissible fade show" role="alert">
        <?= escapar_html($aviso['mensaje']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php
}

function presentar_fin(): void
{
    ?>
</main>
<footer class="pie-pagina py-4 mt-5"><div class="container small">AutoAudit · Proyecto académico de Bases de Datos I · PostgreSQL 18 + PHP + Bootstrap</div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
<?php
}

function encabezado_pagina(string $titulo, string $descripcion): void
{
    echo '<div class="mb-4"><h1 class="h2 fw-bold mb-2">' . escapar_html($titulo) . '</h1>'
       . '<p class="text-secondary mb-0">' . escapar_html($descripcion) . '</p></div>';
}

function representar_json(mixed $json): string
{
    if ($json === null) return '<span class="text-secondary">NULL</span>';
    $decodificado = is_string($json) ? json_decode($json, true) : $json;
    $formato = json_encode($decodificado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return '<pre class="bloque-json small mb-0">' . escapar_html($formato ?: (string)$json) . '</pre>';
}
