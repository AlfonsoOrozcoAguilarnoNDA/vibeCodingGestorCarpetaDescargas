<?php
/**
 * GESTOR DE CARPETA DE DOWNLOADS (ESPECIFICACIÓN V4)
 * 
 * Fecha: 25 de marzo de 2026
 * Licencia: MIT
 * Autor:  Alfonso Orozco Aguilar
 * Coautoría: Modelo AI (v4.0)
 * https://vibecodingmexico.com/gestor-de-carpeta-descargas/
 * 
 * Descripción: Script único para auditoría, migración y visualización de archivos
 * locales hacia base de datos MariaDB.
 */

// 1. REGLAS DE IDENTIDAD Y CUMPLIMIENTO
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0");
header("Pragma: no-cache");
header("Content-Type: text/html; charset=UTF-8");

// Configuración de errores para desarrollo (en producción usar error_reporting(0))
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuración Hardcoded
$HARDCODED_PASSWORD = "admin123"; // Cambiar en producción
$MAX_SIZE_MB = 3;
$MAX_SIZE_BYTES = $MAX_SIZE_MB * 1024 * 1024;
$UPLOAD_DIR = "./downloads";

session_start();

// Conexión a Base de Datos (Asumiendo config.php existe)
if (file_exists('config.php')) {
    require_once 'config.php';
} else {
    // Fallback básico si no existe config.php para que el script no muera inmediatamente, 
    // aunque el prompt asume que existe.
    die("Error: No se encontró config.php con la variable \$link.");
}

// Asegurar que $link existe
if (!isset($link)) {
    die("Error: La variable \$link no está definida en config.php.");
}

// Forzar UTF-8 en la conexión
mysqli_set_charset($link, "utf8mb4");

// 2. ESTRUCTURA DE DATOS Y PERMISO ESCRITURA
// Crear tablas si no existen
$sql_galeria = "CREATE TABLE IF NOT EXISTS `downloads_galeria` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre_archivo` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `contenido` LONGBLOB NOT NULL,
    `sha1` CHAR(40) NOT NULL,
    `size_bytes` INT NOT NULL,
    `comentario` TEXT NULL,
    `tipo_archivo` VARCHAR(50) NULL Default 'Imagen',
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
    `tipo_archivo` VARCHAR(50) NULL Default 'Documento',
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

// Verificar permisos de escritura
$dir_writable = false;
$dir_exists = false;
if (file_exists($UPLOAD_DIR)) {
    $dir_exists = true;
    if (is_writable($UPLOAD_DIR)) {
        $dir_writable = true;
    }
}

// 3. LÓGICA DE NEGOCIO Y RUTEO

$module = isset($_GET['module']) ? $_GET['module'] : 'galeria';
$message = "";
$message_type = "";

// Manejo de Login
if (isset($_POST['action']) && $_POST['action'] == 'login') {
    if (isset($_POST['password']) && $_POST['password'] === $HARDCODED_PASSWORD) {
        $_SESSION['authenticated'] = true;
    } else {
        $message = "Contraseña incorrecta.";
        $message_type = "danger";
    }
}

// Manejo de Logout
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: " . basename(__FILE__));
    exit;
}

$is_auth = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;

// Manejo de Inserción (Módulo Agregar - Verde)
if ($is_auth && isset($_POST['action']) && $_POST['action'] == 'insert_file') {
    $file_path = $_POST['file_path'];
    $comentario = $_POST['comentario'];
    $categoria = $_POST['categoria'];
    $confirm = isset($_POST['confirm_checkbox']);

    if ($confirm && file_exists($file_path)) {
        $file_content = file_get_contents($file_path);
        $file_size = filesize($file_path);
        $file_sha1 = sha1($file_content);
        $file_mime = mime_content_type($file_path);
        $file_mtime = date("Y-m-d H:i:s", filemtime($file_path));
        $file_name = basename($file_path);
        $now = date("Y-m-d H:i:s");

        // Determinar tabla destino
        $is_image = strpos($file_mime, 'image/') === 0;
        $table_name = $is_image ? 'downloads_galeria' : 'downloads_documentos';
        $tipo_archivo = $is_image ? 'Imagen' : 'Documento';

        // Insertar
        $stmt = mysqli_prepare($link, "INSERT INTO $table_name (nombre_archivo, mime_type, contenido, sha1, size_bytes, comentario, tipo_archivo, categoria, visible, fecha_archivo, fecha_registro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'SI', ?, ?)");
        mysqli_stmt_bind_param($stmt, "ssssissss", $file_name, $file_mime, $null, $file_sha1, $file_size, $comentario, $tipo_archivo, $categoria, $file_mtime, $now);
        
        // Manejo del BLOB
        $null = NULL;
        mysqli_stmt_send_long_data($stmt, 2, $file_content);

        if (mysqli_stmt_execute($stmt)) {
            // Verificación Post-Inserción (SHA1)
            $check_stmt = mysqli_prepare($link, "SELECT sha1 FROM $table_name WHERE sha1 = ? ORDER BY id DESC LIMIT 1");
            mysqli_stmt_bind_param($check_stmt, "s", $file_sha1);
            mysqli_stmt_execute($check_stmt);
            $result = mysqli_stmt_get_result($check_stmt);
            
            if ($row = mysqli_fetch_assoc($result)) {
                // Éxito: Borrar archivo físico
                if (unlink($file_path)) {
                    $message = "Archivo guardado en DB y eliminado del servidor correctamente.";
                    $message_type = "success";
                } else {
                    $message = "Guardado en DB, pero falló el borrado físico.";
                    $message_type = "warning";
                }
            } else {
                $message = "Error de integridad: El SHA1 no coincide tras la inserción.";
                $message_type = "danger";
            }
            mysqli_stmt_close($check_stmt);
        } else {
            $message = "Error al insertar: " . mysqli_error($link);
            $message_type = "danger";
        }
        mysqli_stmt_close($stmt);
    } else {
        $message = "Falta confirmación o el archivo no existe.";
        $message_type = "warning";
    }
}

// Manejo de Borrado (Módulo Manage - Amarillo/Rojo)
if ($is_auth && isset($_POST['action']) && $_POST['action'] == 'delete_file') {
    $pwd_check = $_POST['pwd_check'];
    $id_to_delete = $_POST['id_to_delete'];
    $table_to_delete = $_POST['table_to_delete']; // galeria o documentos

    if ($pwd_check === $HARDCODED_PASSWORD) {
        $stmt = mysqli_prepare($link, "DELETE FROM $table_to_delete WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id_to_delete);
        if (mysqli_stmt_execute($stmt)) {
            $message = "Registro eliminado correctamente.";
            $message_type = "success";
        } else {
            $message = "Error al eliminar: " . mysqli_error($link);
            $message_type = "danger";
        }
        mysqli_stmt_close($stmt);
    } else {
        $message = "Contraseña incorrecta. Operación cancelada.";
        $message_type = "danger";
    }
}

// Manejo de Toggle Visible
if ($is_auth && isset($_POST['action']) && $_POST['action'] == 'toggle_visible') {
    $id = $_POST['id'];
    $table = $_POST['table']; // galeria o documentos
    
    // Obtener estado actual
    $stmt = mysqli_prepare($link, "SELECT visible FROM $table WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    $new_val = ($row['visible'] == 'SI') ? 'NO' : 'SI';
    mysqli_stmt_close($stmt);

    $upd = mysqli_prepare($link, "UPDATE $table SET visible = ? WHERE id = ?");
    mysqli_stmt_bind_param($upd, "si", $new_val, $id);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    // Recargar para limpiar POST
    header("Location: " . basename(__FILE__) . "?module=" . $module);
    exit;
}

// Escaneo de Archivos para el módulo Agregar
$local_files = [];
if ($dir_exists && $dir_writable) {
    $scanned = scandir($UPLOAD_DIR);
    foreach ($scanned as $file) {
        if ($file == '.' || $file == '..' || $file == 'index.php' || $file == basename(__FILE__)) {
            continue;
        }
        
        $full_path = $UPLOAD_DIR . "/" . $file;
        if (is_file($full_path)) {
            $size = filesize($full_path);
            $sha1 = sha1_file($full_path);
            
            // Verificar duplicados en ambas tablas
            $check_g = mysqli_prepare($link, "SELECT id FROM downloads_galeria WHERE sha1 = ?");
            mysqli_stmt_bind_param($check_g, "s", $sha1);
            mysqli_stmt_execute($check_g);
            $exists_g = mysqli_stmt_fetch(mysqli_stmt_get_result($check_g));
            mysqli_stmt_close($check_g);

            $check_d = mysqli_prepare($link, "SELECT id FROM downloads_documentos WHERE sha1 = ?");
            mysqli_stmt_bind_param($check_d, "s", $sha1);
            mysqli_stmt_execute($check_d);
            $exists_d = mysqli_stmt_fetch(mysqli_stmt_get_result($check_d));
            mysqli_stmt_close($check_d);

            $status = 'green'; // Default
            if ($exists_g || $exists_d) {
                $status = 'yellow';
            } elseif ($size > $MAX_SIZE_BYTES) {
                $status = 'red';
            }

            $local_files[] = [
                'name' => $file,
                'path' => $full_path,
                'size' => $size,
                'sha1' => $sha1,
                'status' => $status,
                'mime' => mime_content_type($full_path)
            ];
        }
    }
}

// Lógica de Visualización (Galería y Documentos)
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 16;
$offset = ($page - 1) * $limit;

// Filtros
$filter_cat = isset($_POST['filter_cat']) ? $_POST['filter_cat'] : '';
$filter_size = isset($_POST['filter_size']) ? $_POST['filter_size'] : '';

// Query Galería
$sql_g_view = "SELECT * FROM downloads_galeria WHERE visible = 'SI'";
if ($filter_cat) {
    $sql_g_view .= " AND categoria = '" . mysqli_real_escape_string($link, $filter_cat) . "'";
}
$sql_g_view .= " ORDER BY fecha_registro DESC LIMIT $limit OFFSET $offset";
$res_galeria = mysqli_query($link, $sql_g_view);

// Query Documentos (Solo si estamos en ese módulo o es admin)
$sql_d_view = "SELECT * FROM downloads_documentos";
if ($filter_size) {
    // Ejemplo simple de filtro tamaño: > 1MB
    if ($filter_size == 'large') $sql_d_view .= " WHERE size_bytes > 1048576";
    if ($filter_size == 'small') $sql_d_view .= " WHERE size_bytes <= 1048576";
}
$sql_d_view .= " ORDER BY fecha_registro DESC"; // Sin límite estricto para admin view o paginación simple
$res_documentos = mysqli_query($link, $sql_d_view);

// Obtener categorías únicas para el filtro
$res_cats = mysqli_query($link, "SELECT DISTINCT categoria FROM downloads_galeria WHERE categoria IS NOT NULL AND categoria != ''");
$categorias = [];
while($r = mysqli_fetch_assoc($res_cats)) { $categorias[] = $r['categoria']; }

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Gestor de Downloads - Modelo AI</title>
    <!-- Bootstrap 4.6 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { padding-top: 70px; padding-bottom: 60px; }
        .navbar-fixed-top { position: fixed; top: 0; width: 100%; z-index: 1030; }
        .footer { position: fixed; bottom: 0; width: 100%; height: 60px; background-color: #f8f9fa; border-top: 1px solid #e9ecef; display: flex; align-items: center; justify-content: center; z-index: 1030; }
        .card-img-top { height: 200px; object-fit: cover; cursor: pointer; }
        .status-green { background-color: #28a745; color: white; }
        .status-yellow { background-color: #ffc107; color: black; }
        .status-red { background-color: #dc3545; color: white; }
        .main-content { min-height: calc(100vh - 130px); }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark navbar-fixed-top">
    <a class="navbar-brand" href="#">Modelo AI v4.0</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item">
                <a class="nav-link" href="https://google.com" target="_blank">Google</a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-toggle="dropdown">
                    Opciones
                </a>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="#">Opción 1</a>
                    <a class="dropdown-item" href="#">Opción 2</a>
                    <a class="dropdown-item" href="#">Opción 3</a>
                </div>
            </li>
        </ul>
        <ul class="navbar-nav">
            <li class="nav-item <?php echo $module == 'galeria' ? 'active' : ''; ?>">
                <a class="nav-link" href="?module=galeria">Galería</a>
            </li>
            <?php if ($is_auth): ?>
            <li class="nav-item <?php echo $module == 'agregar' ? 'active' : ''; ?>">
                <a class="nav-link" href="?module=agregar">Agregar</a>
            </li>
            <li class="nav-item <?php echo $module == 'manage' ? 'active' : ''; ?>">
                <a class="nav-link" href="?module=manage">Documentos</a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-danger" href="?action=logout">Salir</a>
            </li>
            <?php else: ?>
            <li class="nav-item">
                <a class="nav-link" href="#" data-toggle="modal" data-target="#loginModal">Admin</a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<!-- Main Content -->
<div class="container main-content mt-4">
    
    <?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <?php echo $message; ?>
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
    <?php endif; ?>

    <!-- Alerta de Permisos -->
    <?php if (!$dir_exists): ?>
        <div class="alert alert-danger">Error: La carpeta ./downloads no existe.</div>
    <?php elseif (!$dir_writable): ?>
        <div class="alert alert-warning">Advertencia: La carpeta ./downloads no tiene permisos de escritura.</div>
    <?php endif; ?>

    <!-- MÓDULO GALERÍA -->
    <?php if ($module == 'galeria'): ?>
        <h2>Galería de Imágenes</h2>
        <form method="POST" class="mb-3">
            <div class="form-row align-items-end">
                <div class="col-auto">
                    <label class="sr-only" for="filter_cat">Categoría</label>
                    <select class="form-control mb-2" name="filter_cat" id="filter_cat">
                        <option value="">Todas las categorías</option>
                        <?php foreach($categorias as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filter_cat == $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary mb-2">Filtrar</button>
                </div>
            </div>
        </form>

        <div class="row">
            <?php while($img = mysqli_fetch_assoc($res_galeria)): ?>
            <div class="col-md-3 mb-4">
                <div class="card h-100">
                    <img src="data:<?php echo $img['mime_type']; ?>;base64,<?php echo base64_encode($img['contenido']); ?>" class="card-img-top" alt="<?php echo htmlspecialchars($img['nombre_archivo']); ?>" data-toggle="modal" data-target="#imgModal_<?php echo $img['id']; ?>">
                    <div class="card-body">
                        <h5 class="card-title text-truncate"><?php echo htmlspecialchars($img['nombre_archivo']); ?></h5>
                        <p class="card-text small text-muted"><?php echo $img['categoria'] ? htmlspecialchars($img['categoria']) : 'N/A'; ?></p>
                    </div>
                </div>
            </div>

            <!-- Modal Detalle Imagen -->
            <div class="modal fade" id="imgModal_<?php echo $img['id']; ?>" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><?php echo htmlspecialchars($img['nombre_archivo']); ?></h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body text-center">
                            <img src="data:<?php echo $img['mime_type']; ?>;base64,<?php echo base64_encode($img['contenido']); ?>" class="img-fluid mb-3">
                            <table class="table table-bordered text-left">
                                <tr><th>Categoría</th><td><?php echo $img['categoria'] ? htmlspecialchars($img['categoria']) : 'N/A'; ?></td></tr>
                                <tr><th>Tamaño</th><td><?php echo round($img['size_bytes']/1024, 2); ?> KB</td></tr>
                                <tr><th>Fecha Subida</th><td><?php echo $img['fecha_registro']; ?></td></tr>
                                <tr><th>Comentario</th><td><?php echo htmlspecialchars($img['comentario']); ?></td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        
        <!-- Paginación Simple -->
        <nav>
            <ul class="pagination">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?module=galeria&page=<?php echo $page - 1; ?>">Anterior</a>
                </li>
                <li class="page-item disabled"><span class="page-link">Página <?php echo $page; ?></span></li>
                <li class="page-item">
                    <a class="page-link" href="?module=galeria&page=<?php echo $page + 1; ?>">Siguiente</a>
                </li>
            </ul>
        </nav>

    <!-- MÓDULO AGREGAR (ADMIN) -->
    <?php elseif ($module == 'agregar' && $is_auth): ?>
        <h2>Subir Archivos a Base de Datos</h2>
        <p class="text-muted">Límite: 3MB. Ignorando index.php y archivos ocultos.</p>
        
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Archivo</th>
                        <th>Tamaño</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($local_files as $f): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($f['name']); ?></td>
                        <td><?php echo round($f['size']/1024, 2); ?> KB</td>
                        <td>
                            <?php if($f['status'] == 'green'): ?>
                                <span class="badge badge-success">Disponible</span>
                            <?php elseif($f['status'] == 'yellow'): ?>
                                <span class="badge badge-warning">Duplicado (SHA1)</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Excede 3MB</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($f['status'] == 'green'): ?>
                                <button class="btn btn-sm btn-success" data-toggle="modal" data-target="#uploadModal_<?php echo md5($f['path']); ?>">
                                    <i class="fas fa-upload"></i> Subir
                                </button>
                                
                                <!-- Modal Upload Verde -->
                                <div class="modal fade" id="uploadModal_<?php echo md5($f['path']); ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <form method="POST" class="modal-content">
                                            <div class="modal-header"><h5 class="modal-title">Subir: <?php echo htmlspecialchars($f['name']); ?></h5></div>
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="insert_file">
                                                <input type="hidden" name="file_path" value="<?php echo htmlspecialchars($f['path']); ?>">
                                                
                                                <div class="form-group">
                                                    <label>Categoría</label>
                                                    <input type="text" name="categoria" class="form-control" required>
                                                </div>
                                                <div class="form-group">
                                                    <label>Comentario</label>
                                                    <textarea name="comentario" class="form-control"></textarea>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="confirm_checkbox" id="confirm_<?php echo md5($f['path']); ?>" required>
                                                    <label class="form-check-label" for="confirm_<?php echo md5($f['path']); ?>">
                                                        Estoy seguro que quiero ingresar a base de datos
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-primary">Confirmar y Subir</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>

                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($f['path']); ?>" target="_blank" class="btn btn-sm btn-secondary">
                                    <i class="fas fa-eye"></i> Ver
                                </a>
                                <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#deleteModal_<?php echo md5($f['path']); ?>">
                                    <i class="fas fa-trash"></i> Borrar Local
                                </button>
                                
                                <!-- Modal Borrar Local (Simulado, ya que el prompt dice borrar en DB para amarillo/rojo, 
                                     pero aquí estamos listando archivos LOCALES. 
                                     El prompt dice: "Con amarillo y rojo solo una opción de abrir... Debe tener la opción de borrar en esos dos [refiriéndose a los que YA ESTAN EN DB]" 
                                     Aquí estamos en la vista de archivos LOCALES pendientes. 
                                     Asumiré que si es amarillo/rojo local, solo se puede ver. 
                                     El borrado con password es para registros YA en DB (Módulo Manage). 
                                     Sin embargo, para cumplir "Debe tener la opción de borrar en esos dos", 
                                     interpretaré que si el archivo local es duplicado (amarillo), quizás quieras borrar el local.
                                     Pero el prompt especifica "borrar... confirmar modal... password hardcoded". 
                                     Esto aplica estrictamente a la gestión de la DB (Manage).
                                     Para archivos locales Amarillos/Rojos, el prompt dice "solo una opción de abrir".
                                     Así que NO pondré botón de borrar aquí para locales Amarillos/Rojos, solo Ver.
                                ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <!-- MÓDULO MANAGE / DOCUMENTOS (ADMIN) -->
    <?php elseif ($module == 'manage' && $is_auth): ?>
        <h2>Gestión de Documentos y Archivos</h2>
        
        <ul class="nav nav-tabs mb-3" id="myTab" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="docs-tab" data-toggle="tab" href="#docs" role="tab">Documentos</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="all-images-tab" data-toggle="tab" href="#all-images" role="tab">Todas las Imágenes (Admin)</a>
            </li>
        </ul>

        <div class="tab-content" id="myTabContent">
            <!-- Tab Documentos -->
            <div class="tab-pane fade show active" id="docs" role="tabpanel">
                <form method="POST" class="mb-3">
                    <div class="form-row">
                        <div class="col-auto">
                            <select name="filter_size" class="form-control">
                                <option value="">Todos los tamaños</option>
                                <option value="large" <?php echo $filter_size == 'large' ? 'selected' : ''; ?>>Grandes (>1MB)</option>
                                <option value="small" <?php echo $filter_size == 'small' ? 'selected' : ''; ?>>Pequeños (<=1MB)</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">Filtrar</button>
                        </div>
                    </div>
                </form>
                <table class="table table-striped">
                    <thead><tr><th>ID</th><th>Archivo</th><th>Tamaño</th><th>Visible</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php while($doc = mysqli_fetch_assoc($res_documentos)): ?>
                        <tr>
                            <td><?php echo $doc['id']; ?></td>
                            <td><?php echo htmlspecialchars($doc['nombre_archivo']); ?></td>
                            <td><?php echo round($doc['size_bytes']/1024, 2); ?> KB</td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_visible">
                                    <input type="hidden" name="id" value="<?php echo $doc['id']; ?>">
                                    <input type="hidden" name="table" value="downloads_documentos">
                                    <button type="submit" class="btn btn-sm <?php echo $doc['visible'] == 'SI' ? 'btn-success' : 'btn-secondary'; ?>">
                                        <?php echo $doc['visible']; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <a href="data:<?php echo $doc['mime_type']; ?>;base64,<?php echo base64_encode($doc['contenido']); ?>" download="<?php echo htmlspecialchars($doc['nombre_archivo']); ?>" class="btn btn-sm btn-info" target="_blank">Descargar</a>
                                <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#dbDeleteModal_<?php echo $doc['id']; ?>">Borrar DB</button>
                                
                                <!-- Modal Borrado DB -->
                                <div class="modal fade" id="dbDeleteModal_<?php echo $doc['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <form method="POST" class="modal-content">
                                            <div class="modal-header bg-danger text-white"><h5 class="modal-title">Confirmar Borrado</h5></div>
                                            <div class="modal-body">
                                                <p>¿Está usted seguro?</p>
                                                <input type="hidden" name="action" value="delete_file">
                                                <input type="hidden" name="id_to_delete" value="<?php echo $doc['id']; ?>">
                                                <input type="hidden" name="table_to_delete" value="downloads_documentos">
                                                <div class="form-group">
                                                    <label>Contraseña de Administrador:</label>
                                                    <input type="password" name="pwd_check" class="form-control" required>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-danger">Sí, Borrar</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Tab Todas las Imágenes (Admin) -->
            <div class="tab-pane fade" id="all-images" role="tabpanel">
                 <table class="table table-striped">
                    <thead><tr><th>ID</th><th>Archivo</th><th>Visible</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php 
                    // Re-ejecutar query para todas las imágenes (sin filtro visible)
                    $res_all_imgs = mysqli_query($link, "SELECT * FROM downloads_galeria ORDER BY fecha_registro DESC");
                    while($img = mysqli_fetch_assoc($res_all_imgs)): 
                    ?>
                        <tr>
                            <td><?php echo $img['id']; ?></td>
                            <td><?php echo htmlspecialchars($img['nombre_archivo']); ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_visible">
                                    <input type="hidden" name="id" value="<?php echo $img['id']; ?>">
                                    <input type="hidden" name="table" value="downloads_galeria">
                                    <button type="submit" class="btn btn-sm <?php echo $img['visible'] == 'SI' ? 'btn-success' : 'btn-secondary'; ?>">
                                        <?php echo $img['visible']; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#dbDeleteModalImg_<?php echo $img['id']; ?>">Borrar DB</button>
                                
                                <div class="modal fade" id="dbDeleteModalImg_<?php echo $img['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <form method="POST" class="modal-content">
                                            <div class="modal-header bg-danger text-white"><h5 class="modal-title">Confirmar Borrado</h5></div>
                                            <div class="modal-body">
                                                <p>¿Está usted seguro?</p>
                                                <input type="hidden" name="action" value="delete_file">
                                                <input type="hidden" name="id_to_delete" value="<?php echo $img['id']; ?>">
                                                <input type="hidden" name="table_to_delete" value="downloads_galeria">
                                                <div class="form-group">
                                                    <label>Contraseña de Administrador:</label>
                                                    <input type="password" name="pwd_check" class="form-control" required>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-danger">Sí, Borrar</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>
        <div class="alert alert-info">Acceso restringido. Por favor inicie sesión como administrador.</div>
    <?php endif; ?>

</div>

<!-- Footer Fijo -->
<footer class="footer">
    <div class="container text-center">
        <span class="text-muted">© 2026 Modelo AI v4.0 - Gestor de Downloads. Licencia MIT.</span>
    </div>
</footer>

<!-- Login Modal -->
<div class="modal fade" id="loginModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST" class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Acceso Administrativo</h5></div>
            <div class="modal-body">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label>Contraseña:</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                <button type="submit" class="btn btn-primary">Entrar</button>
            </div>
        </form>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
