<?php
/**
 * PROYECTO: GESTOR DE CARPETA DE DOWNLOADS (ESPECIFICACIÓN V4)
 * Fecha: 25 de marzo de 2026
 * Version 2.2
 * Licencia: MIT
 * https://vibecodingmexico.com/gestor-de-carpeta-descargas/
 * Autor Alfonso Orozco Aguilar
 * Coautor: Gemini 3 Flash Web + Claude Sonnet 4 (Anthropic) - v2.2
 *          Paso 1 Gemini
 *          Paso 2 Gemini Bate y no puede hacer cambios. 
 *          Paso 3 Humano pone características graficas de deepseek y claude
 *          Paso 4 Claude agrega funciones de borrar y cambios menores
 * Descripción: Script de auditoría y migración de archivos locales a DB con validación SHA1.
 */

// 1. CONTROL DE CACHÉ Y CODIFICACIÓN
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Content-Type: text/html; charset=utf-8");

session_start();

// 2. CONFIGURACIÓN Y HARDCODED VALUES
$password_master = "Paladin2026"; // Contraseña Hardcoded
$limite_archivo  = 3 * 1024 * 1024; // 3 MB
$upload_dir      = "./downloads/";
$self            = basename(__FILE__);

/**
 * Alternativa robusta para obtener el MIME type cuando Fileinfo no está disponible
 */
if (!function_exists('mime_content_type')) {
    function mime_content_type($filename) {
        $mime_types = [
            'txt'  => 'text/plain',
            'htm'  => 'text/html',
            'html' => 'text/html',
            'php'  => 'text/html',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'xml'  => 'application/xml',
            'png'  => 'image/png',
            'jpe'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpg'  => 'image/jpeg',
            'gif'  => 'image/gif',
            'bmp'  => 'image/bmp',
            'ico'  => 'image/vnd.microsoft.icon',
            'tiff' => 'image/tiff',
            'tif'  => 'image/tiff',
            'svg'  => 'image/svg+xml',
            'svgz' => 'image/svg+xml',
            'zip'  => 'application/zip',
            'rar'  => 'application/x-rar-compressed',
            'exe'  => 'application/x-msdownload',
            'mp3'  => 'audio/mpeg',
            'qt'   => 'video/quicktime',
            'mov'  => 'video/quicktime',
            'pdf'  => 'application/pdf',
            'psd'  => 'image/vnd.adobe.photoshop',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'rtf'  => 'application/rtf',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odt'  => 'application/vnd.oasis.opendocument.text',
            'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        ];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return array_key_exists($ext, $mime_types) ? $mime_types[$ext] : 'application/octet-stream';
    }
}

// Conexión BD
if (file_exists("config.php")) {
    include("config.php");
} else {
    die("Error: No se encontró config.php");
}

if ($link) {
    mysqli_set_charset($link, "utf8mb4");
}

// Crear tablas si no existen
mysqli_query($link, "CREATE TABLE IF NOT EXISTS `downloads_galeria` (
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
    INDEX (`sha1`), INDEX (`categoria`), INDEX (`visible`), INDEX (`fecha_registro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

mysqli_query($link, "CREATE TABLE IF NOT EXISTS `downloads_documentos` (
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
    INDEX (`sha1`), INDEX (`categoria`), INDEX (`visible`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 3. SERVIR BLOB — debe ir ANTES del HTML
if (isset($_GET["action"]) && $_GET["action"] === "ver_documento") {
    $did  = (int)$_GET["id"];
    $vres = mysqli_query($link, "SELECT nombre_archivo, mime_type, contenido FROM downloads_documentos WHERE id=$did LIMIT 1");
    if ($vres && $vrow = mysqli_fetch_assoc($vres)) {
        header("Content-Type: " . $vrow["mime_type"]);
        header("Content-Disposition: inline; filename=\"" . $vrow["nombre_archivo"] . "\"");
        echo $vrow["contenido"];
        exit;
    }
}

// 4. LÓGICA DE AUTENTICACIÓN
if (isset($_POST["login_pass"])) {
    if ($_POST["login_pass"] === $password_master) {
        $_SESSION["auth_vibecoding"] = true;
    } else {
        $error_auth = "Password incorrecto. Operación cancelada.";
    }
}

if (isset($_GET["action"]) && $_GET["action"] === "logout") {
    session_destroy();
    header("Location: " . $self);
    exit;
}

$is_auth = isset($_SESSION["auth_vibecoding"]) && $_SESSION["auth_vibecoding"] === true;

// 5. PROCESAMIENTO DE OPERACIONES POST

// --- Cambio de Visibilidad ---
if ($is_auth && isset($_POST["toggle_visible"])) {
    $id       = (int)$_POST["target_id"];
    $table    = ($_POST["target_table"] === "galeria") ? "downloads_galeria" : "downloads_documentos";
    $current  = $_POST["current_status"];
    $new_status = ($current === "SI") ? "NO" : "SI";
    mysqli_query($link, "UPDATE $table SET visible = '$new_status' WHERE id = $id");
}

// --- Inserción Verde (Migración) ---
if ($is_auth && isset($_POST["upload_file"])) {
    $filename = $_POST["filename"];
    $filepath = $upload_dir . $filename;

    if (file_exists($filepath) && isset($_POST["confirm_upload"])) {
        $sha1_orig  = sha1_file($filepath);
        $content    = mysqli_real_escape_string($link, file_get_contents($filepath));
        $mime       = mime_content_type($filepath);
        $size       = filesize($filepath);
        $mtime      = date("Y-m-d H:i:s", filemtime($filepath));
        $now        = date("Y-m-d H:i:s");
        $comentario = mysqli_real_escape_string($link, $_POST["comentario"]);
        $categoria  = mysqli_real_escape_string($link, $_POST["categoria"]);
        $tabla      = (str_starts_with($mime, "image/")) ? "downloads_galeria" : "downloads_documentos";
        $tipo       = (str_starts_with($mime, "image/")) ? "Imagen" : "Documento";

        $sql = "INSERT INTO $tabla (nombre_archivo, mime_type, contenido, sha1, size_bytes, comentario, tipo_archivo, categoria, fecha_archivo, fecha_registro)
                VALUES ('$filename', '$mime', '$content', '$sha1_orig', $size, '$comentario', '$tipo', '$categoria', '$mtime', '$now')";

        if (mysqli_query($link, $sql)) {
            $last_id = mysqli_insert_id($link);
            $res     = mysqli_query($link, "SELECT sha1 FROM $tabla WHERE id = $last_id");
            $row     = mysqli_fetch_assoc($res);
            if ($row["sha1"] === $sha1_orig) {
                unlink($filepath);
                header("Location: " . $self . "?module=agregar&success=1");
                exit;
            }
        }
    }
}

// --- Borrado Físico (archivo local) ---
if (isset($_POST["delete_physical"])) {
    if ($_POST["confirm_pass"] === $password_master) {
        $file_to_del = $upload_dir . $_POST["filename"];
        if (file_exists($file_to_del)) {
            unlink($file_to_del);
            $msg_del = "Archivo físico eliminado correctamente.";
        }
    } else {
        $error_del = "Operación cancelada. Password incorrecto.";
    }
}

// --- Borrado Documento de DB ---
if (isset($_POST["action"]) && $_POST["action"] === "delete_doc" && $is_auth) {
    if ($_POST["confirm_pass"] === $password_master) {
        $did = (int)$_POST["doc_id"];
        mysqli_query($link, "DELETE FROM downloads_documentos WHERE id=$did");
        $msg_del = "Documento eliminado correctamente de la base de datos.";
    } else {
        $error_del = "Operación cancelada. Password incorrecto.";
    }
}

// --- Borrado Imagen de DB ---
if (isset($_POST["action"]) && $_POST["action"] === "delete_img" && $is_auth) {
    if ($_POST["confirm_pass"] === $password_master) {
        $iid = (int)$_POST["img_id"];
        mysqli_query($link, "DELETE FROM downloads_galeria WHERE id=$iid");
        $msg_del = "Imagen eliminada correctamente de la base de datos.";
    } else {
        $error_del = "Operación cancelada. Password incorrecto.";
    }
}

$module = $_GET["module"] ?? "galeria";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vibecoding - Gestor de Downloads v2.2</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
    <style>
        body { padding-top: 70px; padding-bottom: 70px; background-color: #f8f9fa; }
        .card-img-top { height: 180px; object-fit: cover; cursor: pointer; }
        .navbar-dark { background-color: #2c3e50; }
        .footer { position: fixed; bottom: 0; width: 100%; height: 60px; background-color: #2c3e50; color: white; line-height: 60px; }
        .btn-verde    { background-color: #28a745; color: white; }
        .btn-amarillo { background-color: #ffc107; color: black; }
        .btn-rojo     { background-color: #dc3545; color: white; }
        .card-galeria { transition: transform .2s, box-shadow .2s; }
        .card-galeria:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,.15); }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark fixed-top">
    <div class="container">
        <a class="navbar-brand" href="#"><i class="fas fa-shield-alt"></i> Gemini 3 Flash V4</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarVibe">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarVibe">
            <ul class="navbar-nav mr-auto">
                <li class="nav-item"><a class="nav-link" href="https://www.google.com" target="_blank"><i class="fab fa-google"></i> Google</a></li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="dropGen" data-toggle="dropdown"><i class="fas fa-tasks"></i> Opciones</a>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="#">Perfil</a>
                        <a class="dropdown-item" href="#"><i class="fas fa-cog"></i> Ajustes</a>
                        <a class="dropdown-item" href="#"><i class="fas fa-database"></i> Logs</a>
                    </div>
                </li>
            </ul>
            <ul class="navbar-nav ml-auto">
                <li class="nav-item <?php echo ($module === 'galeria') ? 'active' : ''; ?>">
                    <a class="nav-link" href="?module=galeria"><i class="fas fa-images"></i> Galería</a>
                </li>
                <li class="nav-item <?php echo ($module === 'manage') ? 'active' : ''; ?>">
                    <a class="nav-link" href="?module=manage"><i class="fas fa-file-alt"></i> Documentos</a>
                </li>
                <li class="nav-item <?php echo ($module === 'agregar') ? 'active' : ''; ?>">
                    <a class="nav-link" href="?module=agregar"><i class="fas fa-upload"></i> Agregar</a>
                </li>
                <?php if ($is_auth): ?>
                <li class="nav-item">
                    <a class="nav-link text-warning" href="?action=logout"><i class="fas fa-sign-out-alt"></i> Salir</a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<main class="container">

    <?php
    // Mensajes globales
    if (isset($_GET["success"]) && $_GET["success"] == "1"):
    ?>
    <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
        <i class="fas fa-check-circle"></i> <strong>¡Éxito!</strong> El archivo fue subido, verificado y eliminado del disco correctamente.
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php endif; ?>

    <?php if (isset($error_auth)): ?>
    <div class="alert alert-warning alert-dismissible fade show mt-3">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_auth); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php endif; ?>

    <?php if (isset($error_del)): ?>
    <div class="alert alert-danger alert-dismissible fade show mt-3">
        <i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($error_del); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php endif; ?>

    <?php if (isset($msg_del)): ?>
    <div class="alert alert-success alert-dismissible fade show mt-3">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg_del); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php endif; ?>

    <?php
    // Verificación de entorno
    if (!is_dir($upload_dir)) {
        echo "<div class='alert alert-danger'>Error: La carpeta <b>" . htmlspecialchars($upload_dir) . "</b> no existe.</div>";
    } elseif (!is_writable($upload_dir)) {
        echo "<div class='alert alert-danger'>Error: No hay permisos de escritura en <b>" . htmlspecialchars($upload_dir) . "</b>.</div>";
    }

    // =========================================================
    // MÓDULO: GALERÍA
    // =========================================================
    if ($module === "galeria"):
        $cat_filter = $_POST["cat_filter"] ?? "";
        $where = "WHERE visible = 'SI' AND tipo_archivo = 'Imagen'";
        if ($cat_filter) {
            $where .= " AND categoria = '" . mysqli_real_escape_string($link, $cat_filter) . "'";
        }
        $res = mysqli_query($link, "SELECT id, nombre_archivo, mime_type, size_bytes, comentario, categoria, visible, fecha_registro
                                    FROM downloads_galeria $where
                                    ORDER BY fecha_registro DESC LIMIT 16");
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3 mt-3">
        <h4><i class="fas fa-images text-primary"></i> Galería de Imágenes</h4>
    </div>

    <!-- Filtro categoría -->
    <div class="row mb-4">
        <div class="col-md-5">
            <form method="POST" class="form-inline">
                <select name="cat_filter" class="form-control form-control-sm mr-2">
                    <option value="">Todas las categorías</option>
                    <?php
                    $cats = mysqli_query($link, "SELECT DISTINCT categoria FROM downloads_galeria WHERE categoria IS NOT NULL AND categoria != '' ORDER BY categoria");
                    while ($c = mysqli_fetch_assoc($cats)) {
                        $sel = ($cat_filter === $c["categoria"]) ? "selected" : "";
                        echo "<option value='" . htmlspecialchars($c["categoria"]) . "' $sel>" . htmlspecialchars($c["categoria"]) . "</option>";
                    }
                    ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary mr-1">
                    <i class="fas fa-search"></i> Filtrar
                </button>
                <?php if ($cat_filter): ?>
                <a href="?module=galeria" class="btn btn-sm btn-secondary">
                    <i class="fas fa-times"></i> Limpiar
                </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="row">
        <?php if ($res && mysqli_num_rows($res) > 0):
            while ($img = mysqli_fetch_assoc($res)):
                $img_id      = $img["id"];
                $img_nombre  = htmlspecialchars($img["nombre_archivo"]);
                $img_cat     = $img["categoria"] ? htmlspecialchars($img["categoria"]) : "N/A";
                $img_size    = round($img["size_bytes"] / 1024, 2) . " KB";
                $img_fecha   = $img["fecha_registro"];
                $img_com     = $img["comentario"] ? htmlspecialchars($img["comentario"]) : "";
                $img_visible = $img["visible"];
        ?>
        <div class="col-md-3 mb-4">
            <div class="card h-100 shadow-sm card-galeria">
                <img src="data:<?php echo $img["mime_type"]; ?>;base64,<?php echo base64_encode(mysqli_fetch_assoc(mysqli_query($link, "SELECT contenido FROM downloads_galeria WHERE id=$img_id"))["contenido"]); ?>"
                     class="card-img-top"
                     data-toggle="modal"
                     data-target="#imgModal"
                     data-nombre="<?php echo $img_nombre; ?>"
                     data-cat="<?php echo $img_cat; ?>"
                     data-size="<?php echo $img_size; ?>"
                     data-fecha="<?php echo $img_fecha; ?>"
                     data-com="<?php echo $img_com; ?>"
                     data-full="data:<?php echo $img["mime_type"]; ?>;base64,<?php echo base64_encode(mysqli_fetch_assoc(mysqli_query($link, "SELECT contenido FROM downloads_galeria WHERE id=$img_id"))["contenido"]); ?>">
                <div class="card-body p-2">
                    <p class="card-text small font-weight-bold mb-1 text-truncate" title="<?php echo $img_nombre; ?>">
                        <?php echo $img_nombre; ?>
                    </p>
                    <p class="card-text small text-muted mb-0">
                        <i class="fas fa-tag fa-xs"></i> <?php echo $img_cat; ?><br>
                        <i class="fas fa-weight-hanging fa-xs"></i> <?php echo $img_size; ?><br>
                        <i class="fas fa-calendar-alt fa-xs"></i> <?php echo date('d/m/Y', strtotime($img_fecha)); ?>
                    </p>
                    <?php if ($is_auth): ?>
                    <div class="mt-2 d-flex justify-content-between">
                        <!-- Toggle visible -->
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="target_id" value="<?php echo $img_id; ?>">
                            <input type="hidden" name="target_table" value="galeria">
                            <input type="hidden" name="current_status" value="<?php echo $img_visible; ?>">
                            <button type="submit" name="toggle_visible"
                                    class="btn btn-xs btn-sm <?php echo ($img_visible === 'SI') ? 'btn-success' : 'btn-secondary'; ?>"
                                    title="Cambiar visibilidad">
                                <i class="fas fa-eye<?php echo ($img_visible === 'NO') ? '-slash' : ''; ?>"></i>
                            </button>
                        </form>
                        <!-- Borrar imagen -->
                        <button class="btn btn-xs btn-sm btn-outline-danger"
                                data-toggle="modal"
                                data-target="#delImgModal"
                                data-id="<?php echo $img_id; ?>"
                                data-nombre="<?php echo $img_nombre; ?>"
                                title="Borrar">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endwhile; else: ?>
        <div class="col-12">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                No hay imágenes disponibles en la galería<?php echo $cat_filter ? " para la categoría <strong>" . htmlspecialchars($cat_filter) . "</strong>" : ""; ?>.
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // =========================================================
    // MÓDULO: MANAGE (DOCUMENTOS)
    // =========================================================
    elseif ($module === "manage"):
        if (!$is_auth):
    ?>
        <div class="card mx-auto mt-5" style="max-width: 400px;">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-lock"></i> Acceso Requerido
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="password" name="login_pass" class="form-control mb-3" placeholder="Contraseña de acceso" required>
                    <button type="submit" class="btn btn-dark btn-block">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                </form>
            </div>
        </div>
    <?php else:
        $filtro_size = isset($_POST["filtro_size"]) ? (int)$_POST["filtro_size"] : 0;
        $where_doc   = "WHERE 1=1";
        if ($filtro_size > 0) {
            $max_bytes  = $filtro_size * 1024 * 1024;
            $where_doc .= " AND size_bytes <= $max_bytes";
        }
        $res = mysqli_query($link, "SELECT id, nombre_archivo, mime_type, sha1, size_bytes, comentario, categoria, visible, fecha_registro
                                    FROM downloads_documentos $where_doc
                                    ORDER BY fecha_registro DESC");
    ?>
        <h4 class="mt-3">
            <i class="fas fa-file-alt"></i>
            Listado de Documentos
        </h4>

        <!-- Filtro por tamaño -->
        <form method="POST" class="form-inline mb-3">
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

        <table class="table table-striped table-sm bg-white shadow-sm">
            <thead class="thead-dark">
                <tr>
                    <th>Nombre</th>
                    <th>MIME</th>
                    <th>Categoría</th>
                    <th>Tamaño</th>
                    <th>Fecha</th>
                    <th>Visible</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($res && mysqli_num_rows($res) > 0):
                while ($doc = mysqli_fetch_assoc($res)):
                    $doc_id      = $doc["id"];
                    $doc_nombre  = htmlspecialchars($doc["nombre_archivo"]);
                    $doc_mime    = htmlspecialchars($doc["mime_type"]);
                    $doc_size    = round($doc["size_bytes"] / 1024, 2) . " KB";
                    $doc_fecha   = $doc["fecha_registro"];
                    $doc_cat     = $doc["categoria"] ? htmlspecialchars($doc["categoria"]) : "N/A";
                    $doc_comment = $doc["comentario"] ? htmlspecialchars($doc["comentario"]) : "";
                    $doc_visible = $doc["visible"];
            ?>
                <tr>
                    <td>
                        <i class="fas fa-file"></i> <?php echo $doc_nombre; ?>
                        <?php if ($doc_comment): ?>
                        <br><small class="text-muted">
                            <i class="fas fa-comment-alt fa-xs"></i> <?php echo $doc_comment; ?>
                        </small>
                        <?php endif; ?>
                    </td>
                    <td><small><?php echo $doc_mime; ?></small></td>
                    <td><?php echo $doc_cat; ?></td>
                    <td><?php echo $doc_size; ?></td>
                    <td><small><?php echo $doc_fecha; ?></small></td>
                    <td>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="target_id" value="<?php echo $doc_id; ?>">
                            <input type="hidden" name="target_table" value="documentos">
                            <input type="hidden" name="current_status" value="<?php echo $doc_visible; ?>">
                            <button type="submit" name="toggle_visible"
                                    class="btn btn-sm <?php echo ($doc_visible === 'SI') ? 'btn-success' : 'btn-secondary'; ?>">
                                <i class="fas fa-eye<?php echo ($doc_visible === 'NO') ? '-slash' : ''; ?>"></i>
                                <?php echo $doc_visible; ?>
                            </button>
                        </form>
                    </td>
                    <td>
                        <a href="?action=ver_documento&id=<?php echo $doc_id; ?>"
                           target="_blank"
                           class="btn btn-sm btn-outline-info"
                           title="Abrir">
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                        <button class="btn btn-sm btn-outline-danger"
                                data-toggle="modal"
                                data-target="#delDocModal"
                                data-id="<?php echo $doc_id; ?>"
                                data-nombre="<?php echo $doc_nombre; ?>"
                                title="Borrar">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-3">
                        <i class="fas fa-inbox"></i> No hay documentos registrados en la base de datos.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php
    // =========================================================
    // MÓDULO: AGREGAR (MIGRACIÓN)
    // =========================================================
    elseif ($module === "agregar"):
        if (!$is_auth):
    ?>
        <div class="card mx-auto mt-5" style="max-width: 400px;">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-lock"></i> Acceso Requerido
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="password" name="login_pass" class="form-control mb-3" placeholder="Contraseña de acceso" required>
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <h4 class="mt-3"><i class="fas fa-folder-open text-warning"></i> Migración de Archivos Local a DB</h4>
        <div class="table-responsive">
            <table class="table table-hover bg-white shadow-sm">
                <thead class="thead-dark">
                    <tr>
                        <th>Archivo</th>
                        <th>Tamaño</th>
                        <th>Estado / Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                if (!is_dir($upload_dir)) {
                    echo "<tr><td colspan='3' class='text-center text-danger'><i class='fas fa-exclamation-triangle'></i> La carpeta downloads no existe.</td></tr>";
                } else {
                    $files = scandir($upload_dir);
                    $hay_archivos = false;
                    foreach ($files as $f) {
                        if ($f === "." || $f === ".." || $f === "index.php" || $f === $self) continue;
                        $path = $upload_dir . $f;
                        if (!is_file($path)) continue;
                        $hay_archivos = true;
                        $size  = filesize($path);
                        $sha1  = sha1_file($path);
                        $dup_g = mysqli_query($link, "SELECT id FROM downloads_galeria WHERE sha1 = '$sha1'");
                        $dup_d = mysqli_query($link, "SELECT id FROM downloads_documentos WHERE sha1 = '$sha1'");
                        $exists = (mysqli_num_rows($dup_g) > 0 || mysqli_num_rows($dup_d) > 0);

                        echo "<tr>";
                        echo "<td><code>" . htmlspecialchars($f) . "</code></td>";
                        echo "<td>" . round($size / 1024, 2) . " KB</td>";
                        echo "<td>";

                        if ($size > $limite_archivo) {
                            echo "<a href='" . htmlspecialchars($path) . "' target='_blank' class='btn btn-rojo btn-sm'>Exceso de Tamaño</a> ";
                            echo "<button class='btn btn-outline-danger btn-sm' data-toggle='modal' data-target='#delModal' data-file='" . htmlspecialchars($f) . "'>Borrar</button>";
                        } elseif ($exists) {
                            echo "<a href='" . htmlspecialchars($path) . "' target='_blank' class='btn btn-amarillo btn-sm'>Ya existe en DB</a> ";
                            echo "<button class='btn btn-outline-danger btn-sm' data-toggle='modal' data-target='#delModal' data-file='" . htmlspecialchars($f) . "'>Borrar</button>";
                        } else {
                            ?>
                            <button class="btn btn-verde btn-sm" data-toggle="collapse" data-target="#form_<?php echo md5($f); ?>">
                                <i class="fas fa-upload"></i> Migrar a DB
                            </button>
                            <div id="form_<?php echo md5($f); ?>" class="collapse mt-2 p-2 border bg-light">
                                <form method="POST">
                                    <input type="hidden" name="filename" value="<?php echo htmlspecialchars($f); ?>">
                                    <input type="text" name="categoria" class="form-control mb-1" placeholder="Categoría">
                                    <textarea name="comentario" class="form-control mb-1" placeholder="Comentario"></textarea>
                                    <div class="form-check mb-2">
                                        <input type="checkbox" name="confirm_upload" class="form-check-input" required>
                                        <label class="form-check-label">Estoy seguro de ingresar a base de datos</label>
                                    </div>
                                    <button type="submit" name="upload_file" class="btn btn-success btn-sm">
                                        <i class="fas fa-database"></i> Ejecutar Migración
                                    </button>
                                </form>
                            </div>
                            <?php
                        }
                        echo "</td></tr>";
                    }
                    if (!$hay_archivos) {
                        echo "<tr><td colspan='3' class='text-center text-muted'><i class='fas fa-inbox'></i> No hay archivos en la carpeta downloads.</td></tr>";
                    }
                }
                ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php endif; ?>

</main>

<footer class="footer text-center">
    <div class="container">
        <span>© 2026 Vibecoding México | Licencia MIT | Gemini 3 Flash + Claude Sonnet 4 v2.2</span>
    </div>
</footer>

<!-- ============================================================ -->
<!-- MODAL VER IMAGEN (GALERÍA)                                   -->
<!-- ============================================================ -->
<div class="modal fade" id="imgModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title" id="m-title"></h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body text-center">
                <img src="" id="m-img" class="img-fluid mb-3" style="max-height:60vh;">
                <div class="text-left border-top pt-2">
                    <p><i class="fas fa-tag"></i> <b>Categoría:</b> <span id="m-cat"></span></p>
                    <p><i class="fas fa-weight-hanging"></i> <b>Tamaño:</b> <span id="m-size"></span></p>
                    <p><i class="fas fa-calendar-alt"></i> <b>Fecha Subida:</b> <span id="m-fecha"></span></p>
                    <p><i class="fas fa-comment-alt"></i> <b>Comentario:</b> <span id="m-com"></span></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL BORRAR IMAGEN                                          -->
<!-- ============================================================ -->
<div class="modal fade" id="delImgModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> ¿Está usted seguro?</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete_img">
                    <input type="hidden" name="img_id" id="delImgId">
                    <div class="alert alert-danger">
                        <strong>Esta acción es permanente e irreversible.</strong><br>
                        Se eliminará: <strong id="delImgNombre"></strong>
                    </div>
                    <div class="form-group">
                        <label>Confirmar con contraseña:</label>
                        <input type="password" name="confirm_pass" class="form-control" placeholder="Contraseña" required>
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

<!-- ============================================================ -->
<!-- MODAL BORRAR DOCUMENTO                                       -->
<!-- ============================================================ -->
<div class="modal fade" id="delDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> ¿Está usted seguro?</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete_doc">
                    <input type="hidden" name="doc_id" id="delDocId">
                    <div class="alert alert-danger">
                        <strong>Esta acción es permanente e irreversible.</strong><br>
                        Se eliminará: <strong id="delDocNombre"></strong>
                    </div>
                    <div class="form-group">
                        <label>Confirmar con contraseña:</label>
                        <input type="password" name="confirm_pass" class="form-control" placeholder="Contraseña" required>
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

<!-- ============================================================ -->
<!-- MODAL BORRAR ARCHIVO FÍSICO (AGREGAR)                        -->
<!-- ============================================================ -->
<div class="modal fade" id="delModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> ¿Está usted seguro?</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p>Va a eliminar físicamente el archivo: <b id="del-filename-text"></b></p>
                    <input type="hidden" name="filename" id="del-filename">
                    <div class="form-group">
                        <label>Confirmar con contraseña:</label>
                        <input type="password" name="confirm_pass" class="form-control" placeholder="Contraseña" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" name="delete_physical" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Borrar Definitivamente
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Modal ver imagen galería
$('#imgModal').on('show.bs.modal', function (event) {
    var btn = $(event.relatedTarget);
    $('#m-title').text(btn.data('nombre'));
    $('#m-img').attr('src', btn.data('full'));
    $('#m-cat').text(btn.data('cat'));
    $('#m-size').text(btn.data('size'));
    $('#m-fecha').text(btn.data('fecha'));
    $('#m-com').text(btn.data('com') || 'Sin comentario');
});

// Modal borrar imagen
$('#delImgModal').on('show.bs.modal', function (event) {
    var btn = $(event.relatedTarget);
    $('#delImgId').val(btn.data('id'));
    $('#delImgNombre').text(btn.data('nombre'));
});

// Modal borrar documento
$('#delDocModal').on('show.bs.modal', function (event) {
    var btn = $(event.relatedTarget);
    $('#delDocId').val(btn.data('id'));
    $('#delDocNombre').text(btn.data('nombre'));
});

// Modal borrar archivo físico
$('#delModal').on('show.bs.modal', function (event) {
    var btn = $(event.relatedTarget);
    $('#del-filename').val(btn.data('file'));
    $('#del-filename-text').text(btn.data('file'));
});

// Auto-ocultar alertas
$(document).ready(function() {
    setTimeout(function() {
        $('.alert-dismissible').fadeOut('slow');
    }, 6000);
});
</script>
</body>
</html>
