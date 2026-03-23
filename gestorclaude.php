<?php
/**
 * ============================================================
 * GESTOR DE CARPETA DE DOWNLOADS - Versión de Producción
 * ============================================================
 * Fecha       : 25 de marzo de 2026
 * https://vibecodingmexico.com/gestor-de-carpeta-descargas/
 * Licencia    : MIT
 * Autor       : Alfonso Orozco Aguilar
 * Co-autor IA : Claude Sonnet 4 (Anthropic) - claude-sonnet-4-20250514
 * Descripción : Script de auditoría y migración de archivos
 *               locales a base de datos MariaDB.
 * ------------------------------------------------------------
 * MIT License
 * Copyright (c) 2026
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software to deal in the Software
 * without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software.
 * ============================================================
 */

// --- NO CACHE ---
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Content-Type: text/html; charset=UTF-8");

session_start();

// --- CONFIGURACIÓN HARDCODED ---
define('APP_PASSWORD',   'Clave2026!');   // Contraseña para autenticación
define('MAX_FILE_SIZE',  3 * 1024 * 1024); // 3 MB en bytes
define('DOWNLOAD_DIR',   './downloads/');
define('APP_MODEL',      'Claude Sonnet 4');
define('APP_DATE',       '25 de marzo de 2026');

// --- BASE DE DATOS ---
require_once 'config.php'; // Provee $link (mysqli)

// Forzar UTF-8 MB4
mysqli_set_charset($link, 'utf8mb4');
mysqli_query($link, "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

// --- CREAR TABLAS SI NO EXISTEN ---
$sql_galeria = "CREATE TABLE IF NOT EXISTS `downloads_galeria` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre_archivo` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `contenido` LONGBLOB NOT NULL,
    `sha1` CHAR(40) NOT NULL,
    `size_bytes` INT NOT NULL,
    `comentario` TEXT NULL,
    `tipo_archivo` VARCHAR(50) NULL DEFAULT 'Imagen',
    `categoria` VARCHAR(30) NULL,
    `visible` VARCHAR(3) DEFAULT 'SI',
    `engine_ia` VARCHAR(30) NULL,
    `fecha_archivo` DATETIME NOT NULL,
    `fecha_registro` DATETIME NOT NULL,
    INDEX (`sha1`),
    INDEX (`categoria`),
    INDEX (`visible`),
    INDEX (`fecha_registro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$sql_documentos = "CREATE TABLE IF NOT EXISTS `downloads_documentos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre_archivo` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `contenido` LONGBLOB NOT NULL,
    `sha1` CHAR(40) NOT NULL,
    `size_bytes` INT NOT NULL,
    `comentario` TEXT NULL,
    `tipo_archivo` VARCHAR(50) NULL DEFAULT 'Documento',
    `categoria` VARCHAR(30) NULL,
    `visible` VARCHAR(3) DEFAULT 'SI',
    `engine_ia` VARCHAR(30) NULL,
    `fecha_archivo` DATETIME NOT NULL,
    `fecha_registro` DATETIME NOT NULL,
    INDEX (`sha1`),
    INDEX (`categoria`),
    INDEX (`visible`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

mysqli_query($link, $sql_galeria);
mysqli_query($link, $sql_documentos);

// --- NOMBRE REAL DEL SCRIPT ---
$script_name = basename(__FILE__);

// --- HELPER: Verificar si MIME es imagen ---
function esImagen($mime) {
    return strpos($mime, 'image/') === 0;
}

// --- HELPER: SHA1 ya existe en DB ---
function sha1ExisteEnDB($sha1, $link) {
    global $link;
    $sha1_escaped = mysqli_real_escape_string($link, $sha1);
    $r1 = mysqli_query($link, "SELECT id FROM downloads_galeria WHERE sha1='$sha1_escaped' LIMIT 1");
    if ($r1 && mysqli_num_rows($r1) > 0) return ['tabla' => 'galeria', 'row' => mysqli_fetch_assoc($r1)];
    $r2 = mysqli_query($link, "SELECT id FROM downloads_documentos WHERE sha1='$sha1_escaped' LIMIT 1");
    if ($r2 && mysqli_num_rows($r2) > 0) return ['tabla' => 'documentos', 'row' => mysqli_fetch_assoc($r2)];
    return false;
}

// --- HELPER: Formatear bytes ---
function formatBytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

// --- ACCIONES POST ---
$mensaje_global = '';
$tipo_mensaje   = '';

// LOGIN
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    if ($_POST['password'] === APP_PASSWORD) {
        $_SESSION['auth'] = true;
        header("Location: " . $_SERVER['PHP_SELF'] . "?module=agregar");
        exit;
    } else {
        $mensaje_global = "Contraseña incorrecta. Acceso denegado.";
        $tipo_mensaje   = "danger";
    }
}

// LOGOUT
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// TOGGLE VISIBLE
if (isset($_POST['action']) && $_POST['action'] === 'toggle_visible' && isset($_SESSION['auth'])) {
    $tid   = (int)$_POST['toggle_id'];
    $tabla = ($_POST['toggle_tabla'] === 'galeria') ? 'downloads_galeria' : 'downloads_documentos';
    $tabla_safe = mysqli_real_escape_string($link, $tabla);
    $res   = mysqli_query($link, "SELECT visible FROM `$tabla_safe` WHERE id=$tid LIMIT 1");
    if ($res && $row = mysqli_fetch_assoc($res)) {
        $nuevo = ($row['visible'] === 'SI') ? 'NO' : 'SI';
        mysqli_query($link, "UPDATE `$tabla_safe` SET visible='$nuevo' WHERE id=$tid");
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?module=" . ($_POST['toggle_tabla'] === 'galeria' ? 'agregar' : 'manage'));
    exit;
}

// BORRAR REGISTRO
if (isset($_POST['action']) && $_POST['action'] === 'borrar') {
    if (!isset($_SESSION['auth'])) {
        $mensaje_global = "No autenticado.";
        $tipo_mensaje   = "danger";
    } elseif ($_POST['confirm_pwd'] !== APP_PASSWORD) {
        $mensaje_global = "Operación cancelada. Password incorrecto.";
        $tipo_mensaje   = "warning";
    } else {
        $bid   = (int)$_POST['borrar_id'];
        $tabla = ($_POST['borrar_tabla'] === 'galeria') ? 'downloads_galeria' : 'downloads_documentos';
        $tabla_safe = mysqli_real_escape_string($link, $tabla);
        mysqli_query($link, "DELETE FROM `$tabla_safe` WHERE id=$bid");
        $mensaje_global = "Registro eliminado correctamente.";
        $tipo_mensaje   = "success";
    }
}

// INSERTAR ARCHIVO (verde)
if (isset($_POST['action']) && $_POST['action'] === 'insertar' && isset($_SESSION['auth'])) {
    $filepath  = DOWNLOAD_DIR . basename($_POST['nombre_archivo']);
    $comentario = isset($_POST['comentario']) ? trim($_POST['comentario']) : '';
    $categoria  = isset($_POST['categoria'])  ? trim($_POST['categoria'])  : '';
    $confirmado = isset($_POST['confirmar'])  && $_POST['confirmar'] === '1';

    if (!$confirmado) {
        $mensaje_global = "Debe marcar la casilla de confirmación.";
        $tipo_mensaje   = "warning";
    } elseif (!file_exists($filepath)) {
        $mensaje_global = "Archivo no encontrado: " . htmlspecialchars(basename($filepath));
        $tipo_mensaje   = "danger";
    } else {
        $contenido     = file_get_contents($filepath);
        $sha1_archivo  = sha1($contenido);
        $size_bytes    = strlen($contenido);
        $mime_type     = mime_content_type($filepath);
        $nombre_archivo = basename($filepath);
        $mtime         = date('Y-m-d H:i:s', filemtime($filepath));
        $fecha_registro = date('Y-m-d H:i:s');
        $es_imagen     = esImagen($mime_type);
        $tabla         = $es_imagen ? 'downloads_galeria' : 'downloads_documentos';
        $tipo_archivo  = $es_imagen ? 'Imagen' : 'Documento';
        $engine_ia     = APP_MODEL;

        $nombre_esc   = mysqli_real_escape_string($link, $nombre_archivo);
        $mime_esc     = mysqli_real_escape_string($link, $mime_type);
        $sha1_esc     = mysqli_real_escape_string($link, $sha1_archivo);
        $coment_esc   = mysqli_real_escape_string($link, $comentario);
        $categ_esc    = mysqli_real_escape_string($link, $categoria);
        $tipo_esc     = mysqli_real_escape_string($link, $tipo_archivo);
        $engine_esc   = mysqli_real_escape_string($link, $engine_ia);
        $blob_esc     = mysqli_real_escape_string($link, $contenido);

        $sql_insert = "INSERT INTO `$tabla`
            (nombre_archivo, mime_type, contenido, sha1, size_bytes, comentario, tipo_archivo, categoria, visible, engine_ia, fecha_archivo, fecha_registro)
            VALUES
            ('$nombre_esc','$mime_esc','$blob_esc','$sha1_esc',$size_bytes,'$coment_esc','$tipo_esc','$categ_esc','SI','$engine_esc','$mtime','$fecha_registro')";

        if (mysqli_query($link, $sql_insert)) {
            $new_id  = mysqli_insert_id($link);
            // Verificar SHA1 del BLOB
            $res_check = mysqli_query($link, "SELECT sha1 FROM `$tabla` WHERE id=$new_id LIMIT 1");
            $row_check = mysqli_fetch_assoc($res_check);
            if ($row_check && $row_check['sha1'] === $sha1_archivo) {
                unlink($filepath);
                $mensaje_global = "Archivo '$nombre_archivo' insertado y verificado. SHA1 correcto. Archivo físico eliminado.";
                $tipo_mensaje   = "success";
            } else {
                // SHA1 no coincide, borrar registro
                mysqli_query($link, "DELETE FROM `$tabla` WHERE id=$new_id");
                $mensaje_global = "Error de integridad SHA1. El archivo NO fue guardado. Operación revertida.";
                $tipo_mensaje   = "danger";
            }
        } else {
            $mensaje_global = "Error al insertar en base de datos: " . mysqli_error($link);
            $tipo_mensaje   = "danger";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?module=agregar&msg=" . urlencode($mensaje_global) . "&tipo=" . $tipo_mensaje);
        exit;
    }
}

// VERIFICAR LOGIN PARA MANAGE
if (isset($_GET['module']) && $_GET['module'] === 'manage' && !isset($_SESSION['auth'])) {
    if (isset($_POST['action']) && $_POST['action'] === 'login_manage') {
        if ($_POST['password'] === APP_PASSWORD) {
            $_SESSION['auth'] = true;
            header("Location: " . $_SERVER['PHP_SELF'] . "?module=manage");
            exit;
        } else {
            $mensaje_global = "Contraseña incorrecta.";
            $tipo_mensaje   = "danger";
        }
    }
}

// Recoger mensaje desde redirect
if (isset($_GET['msg'])) {
    $mensaje_global = urldecode($_GET['msg']);
    $tipo_mensaje   = isset($_GET['tipo']) ? $_GET['tipo'] : 'info';
}

// --- MÓDULO ACTUAL ---
$module = isset($_GET['module']) ? $_GET['module'] : 'galeria';

// ============================================================
// INICIO HTML
// ============================================================
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestor Downloads &mdash; <?php echo APP_MODEL; ?></title>

    <!-- Bootstrap 4.6 via jsDelivr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <!-- Font Awesome 5 via jsDelivr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">

    <style>
        body { padding-top: 56px; padding-bottom: 60px; background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .navbar-brand span { font-weight: 700; color: #ffd700 !important; }
        .footer-fixed { position: fixed; bottom: 0; left: 0; right: 0; background: #212529; color: #adb5bd; text-align: center; padding: 8px; font-size: 0.78rem; z-index: 1030; }
        .card-file { transition: transform .2s, box-shadow .2s; cursor: pointer; }
        .card-file:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,.18); }
        .card-file img { object-fit: cover; height: 160px; width: 100%; }
        .badge-tabla { font-size: .7rem; }
        .btn-sm-action { font-size: .75rem; padding: .2rem .5rem; }
        .modal-img-preview { max-width: 100%; max-height: 60vh; display: block; margin: 0 auto; }
        .file-list-item { background: #fff; border-radius: 6px; margin-bottom: 8px; padding: 10px 14px; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
        .visible-badge-si  { background: #28a745; color: #fff; }
        .visible-badge-no  { background: #6c757d; color: #fff; }
        #loading-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999; align-items:center; justify-content:center; }
        #loading-overlay .spinner-border { width:3rem; height:3rem; }
    </style>
</head>
<body>

<!-- LOADING OVERLAY -->
<div id="loading-overlay">
    <div class="text-center text-white">
        <div class="spinner-border text-light" role="status"></div>
        <p class="mt-2">Procesando...</p>
    </div>
</div>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <a class="navbar-brand" href="<?php echo $_SERVER['PHP_SELF']; ?>">
        <i class="fas fa-robot"></i> <span><?php echo APP_MODEL; ?></span>
    </a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarMain">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarMain">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item">
                <a class="nav-link" href="https://www.google.com" target="_blank">
                    <i class="fab fa-google"></i> Google
                </a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="dropOpciones" data-toggle="dropdown">
                    <i class="fas fa-cog"></i> Opciones
                </a>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="#"><i class="fas fa-info-circle"></i> Acerca de</a>
                    <a class="dropdown-item" href="#"><i class="fas fa-question-circle"></i> Ayuda</a>
                    <a class="dropdown-item" href="#"><i class="fas fa-envelope"></i> Contacto</a>
                </div>
            </li>
        </ul>
        <ul class="navbar-nav">
            <li class="nav-item <?php echo ($module === 'galeria') ? 'active' : ''; ?>">
                <a class="nav-link" href="<?php echo $_SERVER['PHP_SELF']; ?>?module=galeria">
                    <i class="fas fa-images"></i> Galería
                </a>
            </li>
            <li class="nav-item <?php echo ($module === 'manage') ? 'active' : ''; ?>">
                <a class="nav-link" href="<?php echo $_SERVER['PHP_SELF']; ?>?module=manage">
                    <i class="fas fa-file-alt"></i> Documentos
                </a>
            </li>
            <?php if (isset($_SESSION['auth'])): ?>
            <li class="nav-item <?php echo ($module === 'agregar') ? 'active' : ''; ?>">
                <a class="nav-link" href="<?php echo $_SERVER['PHP_SELF']; ?>?module=agregar">
                    <i class="fas fa-plus-circle"></i> Agregar
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-warning" href="<?php echo $_SERVER['PHP_SELF']; ?>?action=logout">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </a>
            </li>
            <?php else: ?>
            <li class="nav-item">
                <a class="nav-link" href="#" data-toggle="modal" data-target="#modalLogin">
                    <i class="fas fa-lock"></i> Acceder
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<!-- MODAL LOGIN -->
<div class="modal fade" id="modalLogin" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-lock"></i> Autenticación</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label>Contraseña</label>
                        <input type="password" name="password" class="form-control" placeholder="Contraseña" required autofocus>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-dark btn-block">
                        <i class="fas fa-sign-in-alt"></i> Ingresar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL BORRAR CONFIRMACIÓN -->
<div class="modal fade" id="modalBorrar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> ¿Está usted seguro?</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" id="formBorrar">
                <div class="modal-body">
                    <input type="hidden" name="action" value="borrar">
                    <input type="hidden" name="borrar_id" id="borrar_id_input">
                    <input type="hidden" name="borrar_tabla" id="borrar_tabla_input">
                    <div class="alert alert-danger">
                        <strong>Esta acción es permanente e irreversible.</strong><br>
                        El registro será eliminado de la base de datos.
                    </div>
                    <div class="form-group">
                        <label>Confirmar con contraseña:</label>
                        <input type="password" name="confirm_pwd" class="form-control" placeholder="Contraseña" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Confirmar Borrado
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL VER IMAGEN -->
<div class="modal fade" id="modalVerImagen" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="modalVerImagenTitulo"></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body text-center">
                <img id="modalVerImagenSrc" src="" class="modal-img-preview mb-3" alt="">
                <table class="table table-sm table-bordered text-left mt-2">
                    <tbody id="modalVerImagenInfo"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- CONTENIDO PRINCIPAL -->
<div class="container-fluid mt-3">

<?php if ($mensaje_global): ?>
<div class="alert alert-<?php echo htmlspecialchars($tipo_mensaje); ?> alert-dismissible fade show">
    <?php echo htmlspecialchars($mensaje_global); ?>
    <button type="button" class="close" data-dismiss="alert">&times;</button>
</div>
<?php endif; ?>

<?php
// ============================================================
// MÓDULO: GALERÍA
// ============================================================
if ($module === 'galeria'):

    // Filtro categoría
    $filtro_cat = isset($_POST['filtro_categoria']) ? trim($_POST['filtro_categoria']) : '';
    $page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page   = 16;
    $offset     = ($page - 1) * $per_page;

    $where = "WHERE visible='SI' AND tipo_archivo='Imagen'";
    if ($filtro_cat !== '' && $filtro_cat !== 'todas') {
        $cat_esc = mysqli_real_escape_string($link, $filtro_cat);
        $where  .= " AND categoria='$cat_esc'";
    }

    $total_res = mysqli_query($link, "SELECT COUNT(*) as c FROM downloads_galeria $where");
    $total_row = mysqli_fetch_assoc($total_res);
    $total     = $total_row ? (int)$total_row['c'] : 0;
    $pages     = max(1, ceil($total / $per_page));

    $res_imgs  = mysqli_query($link,
        "SELECT id, nombre_archivo, mime_type, sha1, size_bytes, comentario, categoria, fecha_registro
         FROM downloads_galeria $where
         ORDER BY fecha_registro DESC
         LIMIT $per_page OFFSET $offset"
    );

    // Categorías disponibles
    $cats_res = mysqli_query($link, "SELECT DISTINCT categoria FROM downloads_galeria WHERE visible='SI' AND tipo_archivo='Imagen' AND categoria IS NOT NULL AND categoria != '' ORDER BY categoria");
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4><i class="fas fa-images text-primary"></i> Galería de Imágenes</h4>
    <small class="text-muted"><?php echo $total; ?> imagen(es) encontrada(s)</small>
</div>

<!-- Filtro categoría -->
<form method="POST" class="form-inline mb-4">
    <label class="mr-2"><i class="fas fa-filter"></i> Categoría:</label>
    <select name="filtro_categoria" class="form-control form-control-sm mr-2">
        <option value="todas" <?php echo ($filtro_cat === '' || $filtro_cat === 'todas') ? 'selected' : ''; ?>>Todas</option>
        <?php if ($cats_res): while ($c = mysqli_fetch_assoc($cats_res)): ?>
        <option value="<?php echo htmlspecialchars($c['categoria']); ?>"
            <?php echo ($filtro_cat === $c['categoria']) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($c['categoria']); ?>
        </option>
        <?php endwhile; endif; ?>
    </select>
    <button type="submit" class="btn btn-sm btn-primary">
        <i class="fas fa-search"></i> Filtrar
    </button>
    <?php if ($filtro_cat && $filtro_cat !== 'todas'): ?>
    <a href="<?php echo $_SERVER['PHP_SELF']; ?>?module=galeria" class="btn btn-sm btn-secondary ml-1">
        <i class="fas fa-times"></i> Limpiar
    </a>
    <?php endif; ?>
</form>

<div class="row">
<?php if ($res_imgs && mysqli_num_rows($res_imgs) > 0):
    while ($img = mysqli_fetch_assoc($res_imgs)):
        $img_id      = $img['id'];
        $img_nombre  = htmlspecialchars($img['nombre_archivo']);
        $img_mime    = htmlspecialchars($img['mime_type']);
        $img_size    = formatBytes($img['size_bytes']);
        $img_fecha   = $img['fecha_registro'];
        $img_cat     = $img['categoria'] ? htmlspecialchars($img['categoria']) : 'N/A';
        $img_comment = $img['comentario'] ? htmlspecialchars($img['comentario']) : 'Sin comentario';
        $img_sha1    = htmlspecialchars($img['sha1']);
?>
<div class="col-6 col-sm-4 col-md-3 mb-4">
    <div class="card card-file shadow-sm"
         onclick="abrirModalImagen(
             '<?php echo $img_id; ?>',
             '<?php echo addslashes($img_nombre); ?>',
             '<?php echo addslashes($img_mime); ?>',
             '<?php echo addslashes($img_size); ?>',
             '<?php echo addslashes($img_fecha); ?>',
             '<?php echo addslashes($img_cat); ?>',
             '<?php echo addslashes($img_comment); ?>'
         )">
        <img src="<?php echo $_SERVER['PHP_SELF']; ?>?action=ver_imagen&tabla=galeria&id=<?php echo $img_id; ?>"
             class="card-img-top" alt="<?php echo $img_nombre; ?>"
             onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'160\' height=\'160\'%3E%3Crect width=\'160\' height=\'160\' fill=\'%23dee2e6\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' dominant-baseline=\'middle\' text-anchor=\'middle\' fill=\'%236c757d\' font-size=\'14\'%3EImagen%3C/text%3E%3C/svg%3E'">
        <div class="card-body p-2">
            <p class="card-text small text-truncate mb-0" title="<?php echo $img_nombre; ?>">
                <strong><?php echo $img_nombre; ?></strong>
            </p>
            <?php if ($img['categoria']): ?>
            <span class="badge badge-info badge-tabla"><?php echo $img_cat; ?></span>
            <?php endif; ?>
            <p class="card-text small text-muted mb-0"><?php echo $img_size; ?></p>
        </div>
    </div>
    <?php if (isset($_SESSION['auth'])): ?>
    <div class="text-center mt-1">
        <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="toggle_visible">
            <input type="hidden" name="toggle_id" value="<?php echo $img_id; ?>">
            <input type="hidden" name="toggle_tabla" value="galeria">
            <button type="submit" class="btn btn-xs btn-outline-secondary btn-sm-action" title="Cambiar visibilidad">
                <i class="fas fa-eye"></i> SI
            </button>
        </form>
        <button class="btn btn-xs btn-outline-danger btn-sm-action"
                onclick="prepararBorrar(<?php echo $img_id; ?>, 'galeria')"
                title="Borrar">
            <i class="fas fa-trash"></i>
        </button>
    </div>
    <?php endif; ?>
</div>
<?php endwhile; else: ?>
<div class="col-12">
    <div class="alert alert-info"><i class="fas fa-info-circle"></i> No hay imágenes disponibles en la galería.</div>
</div>
<?php endif; ?>
</div>

<!-- Paginación -->
<?php if ($pages > 1): ?>
<nav>
    <ul class="pagination justify-content-center">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
        <li class="page-item <?php echo ($p == $page) ? 'active' : ''; ?>">
            <a class="page-link" href="?module=galeria&page=<?php echo $p; ?><?php echo ($filtro_cat && $filtro_cat !== 'todas') ? '&cat=' . urlencode($filtro_cat) : ''; ?>">
                <?php echo $p; ?>
            </a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php
// ============================================================
// MÓDULO: MANAGE (DOCUMENTOS)
// ============================================================
elseif ($module === 'manage'):
    // Requiere autenticación
    if (!isset($_SESSION['auth'])):
?>
<div class="row justify-content-center mt-5">
    <div class="col-md-4">
        <div class="card shadow">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-lock"></i> Acceso Requerido
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="login_manage">
                    <div class="form-group">
                        <label>Contraseña de acceso:</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-dark btn-block">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php else:
    // Filtro por tamaño
    $filtro_size = isset($_POST['filtro_size']) ? (int)$_POST['filtro_size'] : 0;
    $where_doc   = "WHERE 1=1";
    if ($filtro_size > 0) {
        $max_bytes   = $filtro_size * 1024 * 1024;
        $where_doc  .= " AND size_bytes <= $max_bytes";
    }

    $res_docs = mysqli_query($link,
        "SELECT id, nombre_archivo, mime_type, sha1, size_bytes, comentario, categoria, visible, fecha_registro, tipo_archivo
         FROM downloads_documentos $where_doc
         ORDER BY fecha_registro DESC"
    );
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4><i class="fas fa-file-alt text-secondary"></i> Lista de Documentos</h4>
</div>

<!-- Filtro tamaño -->
<form method="POST" class="form-inline mb-4">
    <label class="mr-2"><i class="fas fa-filter"></i> Tamaño máximo:</label>
    <select name="filtro_size" class="form-control form-control-sm mr-2">
        <option value="0" <?php echo ($filtro_size == 0) ? 'selected' : ''; ?>>Todos</option>
        <option value="1" <?php echo ($filtro_size == 1) ? 'selected' : ''; ?>>Hasta 1 MB</option>
        <option value="2" <?php echo ($filtro_size == 2) ? 'selected' : ''; ?>>Hasta 2 MB</option>
        <option value="3" <?php echo ($filtro_size == 3) ? 'selected' : ''; ?>>Hasta 3 MB</option>
    </select>
    <button type="submit" class="btn btn-sm btn-secondary">
        <i class="fas fa-search"></i> Filtrar
    </button>
</form>

<?php if ($res_docs && mysqli_num_rows($res_docs) > 0):
    while ($doc = mysqli_fetch_assoc($res_docs)):
        $doc_id      = $doc['id'];
        $doc_nombre  = htmlspecialchars($doc['nombre_archivo']);
        $doc_mime    = htmlspecialchars($doc['mime_type']);
        $doc_size    = formatBytes($doc['size_bytes']);
        $doc_fecha   = $doc['fecha_registro'];
        $doc_cat     = $doc['categoria'] ? htmlspecialchars($doc['categoria']) : 'N/A';
        $doc_comment = $doc['comentario'] ? htmlspecialchars($doc['comentario']) : '';
        $doc_visible = $doc['visible'];
?>
<div class="file-list-item">
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <i class="fas fa-file text-secondary mr-1"></i>
            <strong><?php echo $doc_nombre; ?></strong>
            <span class="badge badge-secondary ml-1"><?php echo $doc_mime; ?></span>
            <span class="badge badge-light border ml-1"><?php echo $doc_size; ?></span>
            <?php if ($doc['categoria']): ?>
            <span class="badge badge-info ml-1"><?php echo $doc_cat; ?></span>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center mt-1 mt-sm-0">
            <span class="badge badge-pill mr-2 <?php echo ($doc_visible === 'SI') ? 'visible-badge-si' : 'visible-badge-no'; ?>">
                <?php echo $doc_visible; ?>
            </span>
            <small class="text-muted mr-3"><?php echo $doc_fecha; ?></small>
            <!-- Toggle visible -->
            <form method="POST" class="d-inline mr-1">
                <input type="hidden" name="action" value="toggle_visible">
                <input type="hidden" name="toggle_id" value="<?php echo $doc_id; ?>">
                <input type="hidden" name="toggle_tabla" value="documentos">
                <button type="submit" class="btn btn-sm btn-outline-secondary btn-sm-action" title="Toggle visible">
                    <i class="fas fa-eye<?php echo ($doc_visible === 'NO') ? '-slash' : ''; ?>"></i>
                </button>
            </form>
            <!-- Ver -->
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>?action=ver_imagen&tabla=documentos&id=<?php echo $doc_id; ?>"
               target="_blank" class="btn btn-sm btn-outline-info btn-sm-action mr-1" title="Abrir">
                <i class="fas fa-external-link-alt"></i>
            </a>
            <!-- Borrar -->
            <button class="btn btn-sm btn-outline-danger btn-sm-action"
                    onclick="prepararBorrar(<?php echo $doc_id; ?>, 'documentos')"
                    title="Borrar">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </div>
    <?php if ($doc_comment): ?>
    <small class="text-muted d-block mt-1"><i class="fas fa-comment-alt fa-xs"></i> <?php echo $doc_comment; ?></small>
    <?php endif; ?>
</div>
<?php endwhile; else: ?>
<div class="alert alert-info"><i class="fas fa-info-circle"></i> No hay documentos registrados.</div>
<?php endif; ?>
<?php endif; ?>

<?php
// ============================================================
// MÓDULO: AGREGAR
// ============================================================
elseif ($module === 'agregar'):
    if (!isset($_SESSION['auth'])):
        // Mostrar login
?>
<div class="row justify-content-center mt-5">
    <div class="col-md-4">
        <div class="card shadow">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-lock"></i> Acceso Requerido
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label>Contraseña:</label>
                        <input type="password" name="password" class="form-control" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-dark btn-block">
                        <i class="fas fa-sign-in-alt"></i> Ingresar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php else:
    // MÓDULO AGREGAR - AUTENTICADO

    // Verificar que existe la carpeta downloads
    $dir_ok     = false;
    $dir_existe = is_dir(DOWNLOAD_DIR);
    $dir_write  = $dir_existe && is_writable(DOWNLOAD_DIR);

    if (!$dir_existe):
?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Error:</strong> La carpeta <code><?php echo htmlspecialchars(DOWNLOAD_DIR); ?></code> no existe.
</div>
<?php elseif (!$dir_write): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Advertencia:</strong> La carpeta <code><?php echo htmlspecialchars(DOWNLOAD_DIR); ?></code> no tiene permisos de escritura.
</div>
<?php else:
    $dir_ok = true;
?>
<div class="alert alert-success py-1">
    <i class="fas fa-check-circle"></i> Carpeta <code><?php echo htmlspecialchars(DOWNLOAD_DIR); ?></code> accesible y con escritura.
</div>
<?php endif;

    // Escanear directorio
    $archivos = [];
    if ($dir_existe) {
        $scan = scandir(DOWNLOAD_DIR);
        foreach ($scan as $archivo) {
            // Ignorar ocultos, directorios, el propio script, index.php
            if ($archivo === '.' || $archivo === '..') continue;
            if ($archivo === $script_name) continue;
            if ($archivo === 'index.php') continue;
            if (substr($archivo, 0, 1) === '.') continue;
            $full_path = DOWNLOAD_DIR . $archivo;
            if (!is_file($full_path)) continue;
            $archivos[] = $archivo;
        }
        sort($archivos);
    }
?>

<h4 class="mb-3"><i class="fas fa-folder-open text-warning"></i> Archivos en ./downloads/</h4>

<div class="table-responsive">
    <table class="table table-bordered table-sm bg-white shadow-sm">
        <thead class="thead-dark">
            <tr>
                <th>Archivo</th>
                <th>Tamaño</th>
                <th>MIME</th>
                <th>Fecha archivo</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
<?php
    if (empty($archivos)):
?>
        <tr>
            <td colspan="6" class="text-center text-muted">
                <i class="fas fa-inbox"></i> No hay archivos en la carpeta.
            </td>
        </tr>
<?php
    else:
        foreach ($archivos as $archivo):
            $full_path  = DOWNLOAD_DIR . $archivo;
            $size_bytes = filesize($full_path);
            $mime_type  = mime_content_type($full_path);
            $mtime      = date('Y-m-d H:i:s', filemtime($full_path));
            $sha1_file  = sha1_file($full_path);
            $es_grande  = ($size_bytes > MAX_FILE_SIZE);
            $existe_sha = sha1ExisteEnDB($sha1_file, $link);

            // Determinar color
            if ($es_grande) {
                $color = 'danger';
                $estado_txt = 'Excede ' . formatBytes(MAX_FILE_SIZE);
            } elseif ($existe_sha) {
                $color = 'warning';
                $estado_txt = 'Ya existe (SHA1 duplicado)';
            } else {
                $color = 'success';
                $estado_txt = 'Nuevo';
            }
?>
        <tr>
            <td>
                <i class="fas fa-<?php echo esImagen($mime_type) ? 'image' : 'file'; ?> mr-1"></i>
                <code><?php echo htmlspecialchars($archivo); ?></code>
            </td>
            <td><?php echo formatBytes($size_bytes); ?></td>
            <td><small><?php echo htmlspecialchars($mime_type); ?></small></td>
            <td><small><?php echo $mtime; ?></small></td>
            <td>
                <span class="badge badge-<?php echo $color; ?>">
                    <?php echo $estado_txt; ?>
                </span>
            </td>
            <td>
<?php if ($color === 'success'): ?>
                <!-- VERDE: formulario de inserción -->
                <button type="button" class="btn btn-success btn-sm"
                        onclick="abrirFormInsertar(
                            '<?php echo addslashes(htmlspecialchars($archivo)); ?>',
                            '<?php echo addslashes(htmlspecialchars($mime_type)); ?>',
                            '<?php echo addslashes(formatBytes($size_bytes)); ?>',
                            '<?php echo addslashes($mtime); ?>'
                        )">
                    <i class="fas fa-upload"></i> Guardar en DB
                </button>
<?php elseif ($color === 'warning' || $color === 'danger'): ?>
                <!-- AMARILLO/ROJO: solo ver y borrar -->
                <a href="<?php echo htmlspecialchars(DOWNLOAD_DIR . $archivo); ?>"
                   target="_blank" class="btn btn-<?php echo $color; ?> btn-sm mr-1">
                    <i class="fas fa-external-link-alt"></i> Abrir
                </a>
                <?php if ($existe_sha): ?>
                <button class="btn btn-outline-danger btn-sm"
                        onclick="prepararBorrar(<?php echo (int)$existe_sha['row']['id']; ?>, '<?php echo $existe_sha['tabla']; ?>')">
                    <i class="fas fa-trash"></i> Borrar de DB
                </button>
                <?php endif; ?>
<?php endif; ?>
            </td>
        </tr>
<?php
        endforeach;
    endif;
?>
        </tbody>
    </table>
</div>

<!-- Modal para insertar archivo verde -->
<div class="modal fade" id="modalInsertar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-upload"></i> Guardar archivo en Base de Datos</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" id="formInsertar" onsubmit="mostrarLoading()">
                <input type="hidden" name="action" value="insertar">
                <input type="hidden" name="nombre_archivo" id="insertar_nombre">
                <div class="modal-body">
                    <div class="alert alert-light border mb-3" id="insertar_info"></div>

                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Categoría:</label>
                        <input type="text" name="categoria" class="form-control" placeholder="Ej: trabajo, personal, fotos...">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-comment-alt"></i> Comentario:</label>
                        <textarea name="comentario" class="form-control" rows="3" placeholder="Descripción del archivo..."></textarea>
                    </div>
                    <div class="form-check mt-2">
                        <input type="checkbox" class="form-check-input" id="chk_confirmar" name="confirmar" value="1" required>
                        <label class="form-check-label font-weight-bold text-success" for="chk_confirmar">
                            Estoy seguro que quiero ingresar este archivo a base de datos
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-database"></i> Insertar en DB
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; // auth
endif; // module agregar
?>

</div><!-- /container -->

<?php
// ============================================================
// SERVIR ARCHIVO BLOB (imágenes y documentos)
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'ver_imagen'):
    $vid   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $vtab  = (isset($_GET['tabla']) && $_GET['tabla'] === 'documentos') ? 'downloads_documentos' : 'downloads_galeria';
    $vtab_safe = mysqli_real_escape_string($link, $vtab);
    $vres  = mysqli_query($link, "SELECT nombre_archivo, mime_type, contenido FROM `$vtab_safe` WHERE id=$vid LIMIT 1");
    if ($vres && $vrow = mysqli_fetch_assoc($vres)) {
        ob_clean();
        header("Content-Type: " . $vrow['mime_type']);
        header("Content-Disposition: inline; filename=\"" . $vrow['nombre_archivo'] . "\"");
        echo $vrow['contenido'];
        exit;
    }
endif;
?>

<!-- FOOTER FIJO -->
<div class="footer-fixed">
    <i class="fas fa-code"></i> Gestor Downloads &mdash; Co-autor: <strong><?php echo APP_MODEL; ?></strong> (Anthropic) &mdash;
    Licencia MIT &mdash; <?php echo APP_DATE; ?> &mdash;
    <i class="fas fa-shield-alt"></i> UTF-8 MB4 &bull; MariaDB InnoDB &bull; Bootstrap 4.6
</div>

<!-- JS Bootstrap 4.6 y dependencias via jsDelivr -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Abrir modal confirmar borrado
function prepararBorrar(id, tabla) {
    document.getElementById('borrar_id_input').value  = id;
    document.getElementById('borrar_tabla_input').value = tabla;
    $('#modalBorrar').modal('show');
}

// Abrir modal de inserción (verde)
function abrirFormInsertar(nombre, mime, size, fecha) {
    document.getElementById('insertar_nombre').value = nombre;
    document.getElementById('insertar_info').innerHTML =
        '<strong><i class="fas fa-file mr-1"></i>' + nombre + '</strong><br>' +
        '<small>MIME: ' + mime + ' &bull; Tamaño: ' + size + ' &bull; Fecha: ' + fecha + '</small>';
    // Reset form
    document.querySelector('#formInsertar [name=categoria]').value   = '';
    document.querySelector('#formInsertar [name=comentario]').value  = '';
    document.getElementById('chk_confirmar').checked = false;
    $('#modalInsertar').modal('show');
}

// Abrir modal imagen galería
function abrirModalImagen(id, nombre, mime, size, fecha, categoria, comentario) {
    var url = '<?php echo $_SERVER["PHP_SELF"]; ?>?action=ver_imagen&tabla=galeria&id=' + id;
    document.getElementById('modalVerImagenTitulo').textContent = nombre;
    document.getElementById('modalVerImagenSrc').src = url;
    document.getElementById('modalVerImagenInfo').innerHTML =
        '<tr><th>Nombre</th><td>' + nombre + '</td></tr>' +
        '<tr><th>Categoría</th><td>' + (categoria || 'N/A') + '</td></tr>' +
        '<tr><th>Tamaño</th><td>' + size + '</td></tr>' +
        '<tr><th>MIME</th><td>' + mime + '</td></tr>' +
        '<tr><th>Fecha subida</th><td>' + fecha + '</td></tr>' +
        '<tr><th>Comentario</th><td>' + (comentario || 'Sin comentario') + '</td></tr>';
    $('#modalVerImagen').modal('show');
}

// Loading overlay al subir archivo
function mostrarLoading() {
    document.getElementById('loading-overlay').style.display = 'flex';
}

// Auto-ocultar alertas luego de 5 segundos
$(document).ready(function() {
    setTimeout(function() {
        $('.alert-dismissible').fadeOut('slow');
    }, 6000);
});
</script>
</body>
</html>
