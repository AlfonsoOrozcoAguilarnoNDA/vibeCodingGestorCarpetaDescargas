<?php
/**
 * ============================================================
 * GESTOR DE CARPETA DE DOWNLOADS
 * ============================================================
 * Archivo: downloads.php
 * Versión: 4.0
 * Fecha: 25 de marzo de 2026
 * Licencia: MIT
 * https://vibecodingmexico.com/gestor-de-carpeta-descargas/
 * Autor Alfonso Orozco Aguilar
 * Coautoría: Together Chat (MiniMax-M2.5)
 * Co-programador en el experimento vibecodingmexico.com
 * 
 * Stack: PHP 8.x Procedural, MariaDB InnoDB, Bootstrap 4.6, Font Awesome 5.0
 * ============================================================
 */

// ============================================
// HEADERS DE CACHÉ Y CODIFICACIÓN
// ============================================
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
die("Incompleto");
session_start();

// Incluir config.php
include_once 'config.php';

global $link;

// ============================================
// CONSTANTES DE CONFIGURACIÓN
// ============================================
define('PASSWORD_HARDCODED', 'Lemkotir2026!'); // Contraseña para operaciones
define('MAX_SIZE_BYTES', 3 * 1024 * 1024);    // 3 MB configurables
define('DOWNLOADS_DIR', './downloads/');
define('IMAGES_MIME', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// ============================================
// VERIFICAR Y CREAR TABLAS
// ============================================
function verificar_tablas($link) {
    // Tabla de galería (imágenes)
    $sql_galeria = "
    CREATE TABLE IF NOT EXISTS `downloads_galeria` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    // Tabla de documentos
    $sql_documentos = "
    CREATE TABLE IF NOT EXISTS `downloads_documentos` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    mysqli_query($link, $sql_galeria);
    mysqli_query($link, $sql_documentos);
}

// ============================================
// VERIFICAR CARPETA DE DESCARGAS
// ============================================
function verificar_carpeta_downloads() {
    if (!is_dir(DOWNLOADS_DIR)) {
        return ['existe' => false, 'escribible' => false, 'mensaje' => 'La carpeta ' . DOWNLOADS_DIR . ' no existe'];
    }
    
    if (!is_writable(DOWNLOADS_DIR)) {
        return ['existe' => true, 'escribible' => false, 'mensaje' => 'La carpeta ' . DOWNLOADS_DIR . ' no tiene permiso de escritura'];
    }
    
    return ['existe' => true, 'escribible' => true, 'mensaje' => 'Carpeta OK'];
}

// ============================================
// ESCANEAR ARCHIVOS EN DOWNLOADS
// ============================================
function escanear_downloads($link) {
    $archivos = [];
    
    if (!is_dir(DOWNLOADS_DIR)) {
        return $archivos;
    }
    
    $scan = scandir(DOWNLOADS_DIR);
    
    foreach ($scan as $archivo) {
        // Ignorar archivos ocultos, index.php y el propio script
        if ($archivo[0] === '.' || $archivo === 'index.php' || $archivo === basename(__FILE__)) {
            continue;
        }
        
        $ruta = DOWNLOADS_DIR . $archivo;
        
        if (!is_file($ruta)) {
            continue;
        }
        
        $stat = stat($ruta);
        $contenido = file_get_contents($ruta);
        $sha1 = sha1($contenido);
        $size_bytes = $stat['size'];
        
        // Verificar si existe en alguna tabla
        $existe_galeria = false;
        $existe_documento = false;
        
        $stmt = mysqli_prepare($link, "SELECT id FROM downloads_galeria WHERE sha1 = ?");
        mysqli_stmt_bind_param($stmt, 's', $sha1);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $existe_galeria = mysqli_num_rows($result) > 0;
        
        $stmt2 = mysqli_prepare($link, "SELECT id FROM downloads_documentos WHERE sha1 = ?");
        mysqli_stmt_bind_param($stmt2, 's', $sha1);
        mysqli_stmt_execute($stmt2);
        $result2 = mysqli_stmt_get_result($stmt2);
        $existe_documento = mysqli_num_rows($result2) > 0;
        
        $existe = $existe_galeria || $existe_documento;
        
        // Determinar estado (color)
        if ($size_bytes > MAX_SIZE_BYTES) {
            $estado = 'rojo'; // Excede tamaño
        } elseif ($existe) {
            $estado = 'amarillo'; // Ya existe
        } else {
            $estado = 'verde'; // Nuevo y dentro de tamaño
        }
        
        $mime = mime_content_type($ruta);
        $es_imagen = in_array($mime, IMAGES_MIME);
        
        $archivos[] = [
            'nombre' => $archivo,
            'ruta' => $ruta,
            'size_bytes' => $size_bytes,
            'size_kb' => round($size_bytes / 1024, 2),
            'mime' => $mime,
            'es_imagen' => $es_imagen,
            'sha1' => $sha1,
            'fecha_modificacion' => date('Y-m-d H:i:s', $stat['mtime']),
            'estado' => $estado
        ];
    }
    
    return $archivos;
}

// ============================================
// SUBIR ARCHIVO A BASE DE DATOS
// ============================================
function subir_archivo($link, $archivo, $comentario, $categoria) {
    $contenido = file_get_contents($archivo['ruta']);
    $sha1 = sha1($contenido);
    $size_bytes = $archivo['size_bytes'];
    $mime = $archivo['mime'];
    $nombre = $archivo['nombre'];
    $fecha_archivo = $archivo['fecha_modificacion'];
    $fecha_registro = date('Y-m-d H:i:s');
    $es_imagen = in_array($mime, IMAGES_MIME);
    
    // Elegir tabla
    $tabla = $es_imagen ? 'downloads_galeria' : 'downloads_documentos';
    $tipo = $es_imagen ? 'Imagen' : 'Documento';
    
    // Insertar
    $sql = "INSERT INTO {$tabla} (nombre_archivo, mime_type, contenido, sha1, size_bytes, comentario, tipo_archivo, categoria, visible, engine_ia, fecha_archivo, fecha_registro) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'SI', 'MiniMax-M2.5', ?, ?)";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'sssissssss', $nombre, $mime, $contenido, $sha1, $size_bytes, $comentario, $tipo, $categoria, $fecha_archivo, $fecha_registro);
    
    if (mysqli_stmt_execute($stmt)) {
        // Verificar SHA1 del blob insertado
        $stmt_verif = mysqli_prepare($link, "SELECT sha1 FROM {$tabla} WHERE nombre_archivo = ? AND sha1 = ?");
        mysqli_stmt_bind_param($stmt_verif, 'ss', $nombre, $sha1);
        mysqli_stmt_execute($stmt_verif);
        $result_verif = mysqli_stmt_get_result($stmt_verif);
        $row_verif = mysqli_fetch_assoc($result_verif);
        
        if ($row_verif && $row_verif['sha1'] === $sha1) {
            // Eliminar archivo físico
            unlink($archivo['ruta']);
            return ['success' => true, 'message' => 'Archivo subido y verificado correctamente'];
        } else {
            // Eliminar registro si no coincide
            $sql_delete = "DELETE FROM {$tabla} WHERE nombre_archivo = ?";
            $stmt_delete = mysqli_prepare($link, $sql_delete);
            mysqli_stmt_bind_param($stmt_delete, 's', $nombre);
            mysqli_stmt_execute($stmt_delete);
            return ['success' => false, 'message' => 'Error: SHA1 no coincide después de insertar'];
        }
    }
    
    return ['success' => false, 'message' => 'Error al insertar: ' . mysqli_error($link)];
}

// ============================================
// BORRAR ARCHIVO DE BASE DE DATOS
// ============================================
function borrar_archivo($link, $id, $tabla) {
    // Verificar que existe
    $sql = "SELECT nombre_archivo, sha1 FROM {$tabla} WHERE id = ?";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    
    if (!$row) {
        return ['success' => false, 'message' => 'Archivo no encontrado'];
    }
    
    // Borrar
    $sql_delete = "DELETE FROM {$tabla} WHERE id = ?";
    $stmt_delete = mysqli_prepare($link, $sql_delete);
    mysqli_stmt_bind_param($stmt_delete, 'i', $id);
    
    if (mysqli_stmt_execute($stmt_delete)) {
        return ['success' => true, 'message' => 'Archivo eliminado correctamente'];
    }
    
    return ['success' => false, 'message' => 'Error al eliminar'];
}

// ============================================
// CAMBIAR VISIBILIDAD
// ============================================
function cambiar_visibilidad($link, $id, $tabla) {
    // Obtener estado actual
    $sql = "SELECT visible FROM {$tabla} WHERE id = ?";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    
    $nuevo_visible = ($row['visible'] === 'SI') ? 'NO' : 'SI';
    
    $sql_update = "UPDATE {$tabla} SET visible = ? WHERE id = ?";
    $stmt_update = mysqli_prepare($link, $sql_update);
    mysqli_stmt_bind_param($stmt_update, 'si', $nuevo_visible, $id);
    
    if (mysqli_stmt_execute($stmt_update)) {
        return ['success' => true, 'visible' => $nuevo_visible];
    }
    
    return ['success' => false];
}

// ============================================
// OBTENER GALERÍA
// ============================================
function obtener_galeria($link, $categoria = '', $page = 1) {
    $por_pagina = 16;
    $offset = ($page - 1) * $por_pagina;
    
    $where = " WHERE tipo_archivo = 'Imagen' AND visible = 'SI'";
    $params = [];
    
    if (!empty($categoria)) {
        $where .= " AND categoria = ?";
        $params[] = $categoria;
    }
    
    // Contar total
    $sql_count = "SELECT COUNT(*) as total FROM downloads_galeria" . $where;
    $stmt_count = mysqli_prepare($link, $sql_count);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt_count, 's', $params[0]);
    }
    mysqli_stmt_execute($stmt_count);
    $result_count = mysqli_stmt_get_result($stmt_count);
    $row_count = mysqli_fetch_assoc($result_count);
    $total = $row_count['total'];
    
    // Obtener registros
    $sql = "SELECT id, nombre_archivo, mime_type, size_bytes, comentario, categoria, visible, fecha_archivo, fecha_registro 
            FROM downloads_galeria" . $where . " ORDER BY fecha_registro DESC LIMIT ? OFFSET ?";
    $params[] = $por_pagina;
    $params[] = $offset;
    
    $stmt = mysqli_prepare($link, $sql);
    $types = str_repeat('s', count($params) - 2) . 'ii';
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $imagenes = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $imagenes[] = $row;
    }
    
    return ['imagenes' => $imagenes, 'total' => $total, 'paginas' => ceil($total / $por_pagina)];
}

// ============================================
// OBTENER DOCUMENTOS
// ============================================
function obtener_documentos($link, $filtro_tamano = '') {
    $where = " WHERE visible = 'SI'";
    
    if ($filtro_tamano === 'pequenos') {
        $where .= " AND size_bytes < 1048576"; // < 1MB
    } elseif ($filtro_tamano === 'medianos') {
        $where .= " AND size_bytes >= 1048576 AND size_bytes < 5242880"; // 1-5MB
    } elseif ($filtro_tamano === 'grandes') {
        $where .= " AND size_bytes >= 5242880"; // > 5MB
    }
    
    $sql = "SELECT id, nombre_archivo, mime_type, size_bytes, comentario, categoria, visible, fecha_archivo, fecha_registro 
            FROM downloads_documentos" . $where . " ORDER BY fecha_registro DESC";
    
    $result = mysqli_query($link, $sql);
    
    $documentos = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $documentos[] = $row;
    }
    
    return $documentos;
}

// ============================================
// OBTENER IMAGEN PARA VER
// ============================================
function obtener_imagen($link, $id) {
    $sql = "SELECT * FROM downloads_galeria WHERE id = ?";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

// ============================================
// OBTENER CATEGORÍAS ÚNICAS
// ============================================
function obtener_categorias($link, $tabla) {
    $sql = "SELECT DISTINCT categoria FROM {$tabla} WHERE categoria IS NOT NULL AND categoria != '' ORDER BY categoria";
    $result = mysqli_query($link, $sql);
    $categorias = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $categorias[] = $row['categoria'];
    }
    return $categorias;
}

// ============================================
// VARIABLES DE CONTROL
// ============================================
verificar_tablas($link);
$carpeta_ok = verificar_carpeta_downloads();

$module = $_GET['module'] ?? 'galeria';
$autenticado = isset($_SESSION['autenticado']) && $_SESSION['autenticado'] === true;
$mensaje = '';
$tipo_mensaje = '';

// ============================================
// PROCESAMIENTO DE ACCIONES
// ============================================

// Login
if ($module === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if ($password === PASSWORD_HARDCODED) {
        $_SESSION['autenticado'] = true;
        $autenticado = true;
        $mensaje = 'Sesión iniciada correctamente';
        $tipo_mensaje = 'success';
    } else {
        $mensaje = 'Contraseña incorrecta';
        $tipo_mensaje = 'danger';
    }
    $module = 'gestor';
}

// Logout
if ($module === 'logout') {
    session_destroy();
    header('Location: downloads.php');
    exit;
}

// Subir archivo (verde)
if ($module === 'subir' && $_SERVER['REQUEST_METHOD'] === 'POST' && $autenticado) {
    $archivo_json = $_POST['archivo_json'] ?? '';
    $comentario = $_POST['comentario'] ?? '';
    $categoria = $_POST['categoria'] ?? '';
    $confirmado = isset($_POST['confirmado']);
    
    if (!$confirmado) {
        $mensaje = 'Debe confirmar que desea ingresar a base de datos';
        $tipo_mensaje = 'warning';
    } else {
        $archivo_data = json_decode($archivo_json, true);
        if ($archivo_data) {
            $resultado = subir_archivo($link, $archivo_data, $comentario, $categoria);
            if ($resultado['success']) {
                $mensaje = $resultado['message'];
                $tipo_mensaje = 'success';
            } else {
                $mensaje = $resultado['message'];
                $tipo_mensaje = 'danger';
            }
        }
    }
    $module = 'gestor';
}

// Borrar archivo (amarillo/rojo)
if ($module === 'borrar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    $tabla = $_POST['tabla'] ?? 'downloads_galeria';
    
    if ($password !== PASSWORD_HARDCODED) {
        $mensaje = 'Contraseña incorrecta. Operación cancelada.';
        $tipo_mensaje = 'danger';
    } else {
        $resultado = borrar_archivo($link, $id, $tabla);
        if ($resultado['success']) {
            $mensaje = $resultado['message'];
            $tipo_mensaje = 'success';
        } else {
            $mensaje = $resultado['message'];
            $tipo_mensaje = 'danger';
        }
    }
    $module = 'gestor';
}

// Cambiar visibilidad
if ($module === 'toggle_visible' && $autenticado) {
    $id = intval($_GET['id'] ?? 0);
    $tabla = $_GET['tabla'] ?? 'downloads_galeria';
    
    cambiar_visibilidad($link, $id, $tabla);
    header('Location: downloads.php?module=' . ($tabla === 'downloads_galeria' ? 'galeria' : 'documentos'));
    exit;
}

// Obtener datos según módulo
$archivos_descarga = [];
$categorias = [];

if ($module === 'gestor') {
    $archivos_descarga = escanear_downloads($link);
    $categorias = array_merge(obtener_categorias($link, 'downloads_galeria'), obtener_categorias($link, 'downloads_documentos'));
    $categorias = array_unique($categorias);
    sort($categorias);
}

$galeria_data = [];
$documentos_data = [];
$categorias_galeria = [];

if ($module === 'galeria') {
    $filtro_categoria = $_POST['filtro_categoria'] ?? $_SESSION['filtro_categoria'] ?? '';
    $_SESSION['filtro_categoria'] = $filtro_categoria;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $galeria_data = obtener_galeria($link, $filtro_categoria, $page);
    $categorias_galeria = obtener_categorias($link, 'downloads_galeria');
}

if ($module === 'documentos') {
    $filtro_tamano = $_GET['filtro_tamano'] ?? '';
    $documentos_data = obtener_documentos($link, $filtro_tamano);
}

if ($module === 'ver_imagen' && isset($_GET['id'])) {
    $imagen_ver = obtener_imagen($link, intval($_GET['id']));
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Downloads - Lemkotir</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --metro-blue: #3498db;
            --metro-green: #27ae60;
            --metro-red: #e74c3c;
            --metro-orange: #e67e22;
            --metro-yellow: #f1c40f;
            --metro-purple: #9b59b6;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
            background: linear-gradient(135deg, #1a2a6c 0%, #2c3e50 50%, #4a69bd 100%);
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        
        .main-container {
            padding-top: 90px;
            padding-bottom: 100px;
        }
        
        .navbar {
            background: linear-gradient(135deg, #2c3e50, #34495e) !important;
        }
        
        .card-custom {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .header-custom {
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            padding: 15px 20px;
        }
        
        .btn-metro {
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            transition: all 0.3s ease;
        }
        
        .btn-metro:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            color: white;
        }
        
        .btn-verde { background: var(--metro-green); color: white; }
        .btn-verde:hover { background: #219a52; color: white; }
        
        .btn-amarillo { background: var(--metro-yellow); color: #333; }
        .btn-amarillo:hover { background: #d4ac0d; color: #333; }
        
        .btn-rojo { background: var(--metro-red); color: white; }
        .btn-rojo:hover { background: #c0392b; color: white; }
        
        .card-archivo {
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .card-archivo.verde { border-left: 4px solid var(--metro-green); background: #e8f8f5; }
        .card-archivo.amarillo { border-left: 4px solid var(--metro-yellow); background: #fef9e7; }
        .card-archivo.rojo { border-left: 4px solid var(--metro-red); background: #fdedec; }
        
        .galeria-img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .galeria-img:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }
        
        .doc-icon {
            font-size: 2rem;
            color: var(--metro-blue);
        }
        
        .badge-visible {
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .badge-visible:hover {
            transform: scale(1.1);
        }
    </style>
</head>
<body>

<!-- Navbar Fijo -->
<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
    <div class="container">
        <a class="navbar-brand font-weight-bold" href="downloads.php">
            <i class="fas fa-folder-download mr-2"></i>Downloads
        </a>
        <span class="navbar-text text-white">
            <i class="fas fa-robot mr-1"></i>Modelo: MiniMax-M2.5
        </span>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ml-auto">
                <li class="nav-item">
                    <a class="nav-link" href="https://www.google.com" target="_blank">
                        <i class="fas fa-search mr-1"></i>Google
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-toggle="dropdown">
                        <i class="fas fa-layer-group mr-1"></i>Menú
                    </a>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="downloads.php?module=galeria"><i class="fas fa-images mr-2"></i>Galería</a>
                        <a class="dropdown-item" href="downloads.php?module=documentos"><i class="fas fa-file-alt mr-2"></i>Documentos</a>
                        <a class="dropdown-item" href="downloads.php?module=gestor"><i class="fas fa-folder mr-2"></i>Gestor</a>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="downloads.php?module=galeria">
                        <i class="fas fa-images mr-1"></i>Galería
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="downloads.php?module=documentos">
                        <i class="fas fa-file-alt mr-1"></i>Docs
                    </a>
                </li>
                <?php if ($autenticado): ?>
                <li class="nav-item">
                    <a class="nav-link" href="downloads.php?module=gestor">
                        <i class="fas fa-folder-plus mr-1"></i>Gestor
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-warning" href="downloads.php?module=logout">
                        <i class="fas fa-sign-out-alt mr-1"></i>Salir
                    </a>
                </li>
                <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link text-success" href="#" data-toggle="modal" data-target="#loginModal">
                        <i class="fas fa-sign-in-alt mr-1"></i>Login
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Contenido Principal -->
<div class="main-container">
    <div class="container">
        
        <!-- Mensajes -->
        <?php if (!empty($mensaje)): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
            <i class="fas <?php echo ($tipo_mensaje === 'success') ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
            <?php echo htmlspecialchars($mensaje); ?>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
        <?php endif; ?>
        
        <!-- ============================================ -->
        <!-- LOGIN MODAL -->
        <!-- ============================================ -->
        <div class="modal fade" id="loginModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #2c3e50, #34495e); color: white;">
                        <h5 class="modal-title"><i class="fas fa-key mr-2"></i>Iniciar Sesión</h5>
                        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <form method="POST" action="downloads.php?module=login">
                            <div class="form-group">
                                <label>Contraseña</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-metro btn-block">Entrar</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ============================================ -->
        <!-- MODAL GALLERY IMAGE VIEW -->
        <!-- ============================================ -->
        <div class="modal fade" id="imagenModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header" style="background: linear-gradient(135deg, #2c3e50, #34495e); color: white;">
                        <h5 class="modal-title"><i class="fas fa-image mr-2"></i>Detalles de Imagen</h5>
                        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body text-center">
                        <?php if (isset($imagen_ver) && $imagen_ver): ?>
                        <img src="data:<?php echo htmlspecialchars($imagen_ver['mime_type']); ?>;base64,<?php echo base64_encode($imagen_ver['contenido']); ?>" class="img-fluid mb-3" style="max-height: 400px;">
                        <hr>
                        <div class="row text-left">
                            <div class="col-md-6">
                                <p><strong><i class="fas fa-file mr-2"></i>Archivo:</strong> <?php echo htmlspecialchars($imagen_ver['nombre_archivo']); ?></p>
                                <p><strong><i class="fas fa-tag mr-2"></i>Categoría:</strong> <?php echo htmlspecialchars($imagen_ver['categoria'] ?: 'N/A'); ?></p>
                                <p><strong><i class="fas fa-weight mr-2"></i>Tamaño:</strong> <?php echo number_format($imagen_ver['size_bytes'] / 1024, 2); ?> KB</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong><i class="fas fa-calendar mr-2"></i>Fecha archivo:</strong> <?php echo $imagen_ver['fecha_archivo']; ?></p>
                                <p><strong><i class="fas fa-cloud-upload mr-2"></i>Fecha subida:</strong> <?php echo $imagen_ver['fecha_registro']; ?></p>
                                <p><strong><i class="fas fa-comment mr-2"></i>Comentario:</strong> <?php echo htmlspecialchars($imagen_ver['comentario'] ?: 'N/A'); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ============================================ -->
        <!-- MÓDULO GALERÍA -->
        <!-- ============================================ -->
        <?php if ($module === 'galeria'): ?>
        
        <div class="card-custom mb-4">
            <div class="header-custom">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <h4 class="mb-0"><i class="fas fa-images mr-2"></i>Galería de Imágenes</h4>
                    <form method="POST" class="d-flex">
                        <select name="filtro_categoria" class="form-control mr-2" onchange="this.form.submit()">
                            <option value="">Todas las categorías</option>
                            <?php foreach ($categorias_galeria as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($filtro_categoria === $cat) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <div class="card-body p-3">
                <?php if (empty($galeria_data['imagenes'])): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-images fa-3x mb-3"></i>
                    <p>No hay imágenes en la galería</p>
                </div>
                <?php else: ?>
                <div class="row">
                    <?php foreach ($galeria_data['imagenes'] as $img): ?>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card h-100">
                            <img src="data:<?php echo htmlspecialchars($img['mime_type']); ?>;base64,<?php echo base64_encode(obtener_imagen($link, $img['id'])['contenido']); ?>" 
                                 class="galeria-img" 
                                 data-toggle="modal" 
                                 data-target="#imagenModal"
                                 data-id="<?php echo $img['id']; ?>">
                            <div class="card-body p-2">
                                <small class="d-block text-truncate"><?php echo htmlspecialchars($img['nombre_archivo']); ?></small>
                                <small class="text-muted"><?php echo number_format($img['size_bytes'] / 1024, 1); ?> KB</small>
                                <?php if ($autenticado): ?>
                                <br>
                                <a href="downloads.php?module=toggle_visible&id=<?php echo $img['id']; ?>&tabla=downloads_galeria" 
                                   class="badge badge-<?php echo ($img['visible'] === 'SI') ? 'success' : 'secondary'; ?> badge-visible"
                                   title="Cambiar visibilidad">
                                    <i class="fas fa-eye<?php echo ($img['visible'] === 'SI') ? '' : '-slash'; ?>"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Paginación -->
                <?php if ($galeria_data['paginas'] > 1): ?>
                <nav>
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $galeria_data['paginas']; $i++): ?>
                        <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="downloads.php?module=galeria&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <script>
        // Pasar ID al modal
        $('#imagenModal').on('show.bs.modal', function(e) {
            var button = $(e.relatedTarget);
            var id = button.data('id');
            if (id) {
                window.location.href = 'downloads.php?module=ver_imagen&id=' + id;
            }
        });
        </script>
        <?php endif; ?>
        
        <!-- ============================================ -->
        <!-- MÓDULO DOCUMENTOS -->
        <!-- ============================================ -->
        <?php if ($module === 'documentos'): ?>
        
        <div class="card-custom mb-4">
            <div class="header-custom">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <h4 class="mb-0"><i class="fas fa-file-alt mr-2"></i>Documentos</h4>
                    <div class="btn-group">
                        <a href="downloads.php?module=documentos" class="btn btn-sm btn-light <?php echo ($filtro_tamano === '') ? 'active' : ''; ?>">Todos</a>
                        <a href="downloads.php?module=documentos&filtro_tamano=pequenos" class="btn btn-sm btn-light <?php echo ($filtro_tamano === 'pequenos') ? 'active' : ''; ?>">&lt; 1MB</a>
                        <a href="downloads.php?module=documentos&filtro_tamano=medianos" class="btn btn-sm btn-light <?php echo ($filtro_tamano === 'medianos') ? 'active' : ''; ?>">1-5MB</a>
                        <a href="downloads.php?module=documentos&filtro_tamano=grandes" class="btn btn-sm btn-light <?php echo ($filtro_tamano === 'grandes') ? 'active' : ''; ?>">&gt; 5MB</a>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th><i class="fas fa-file mr-1"></i>Nombre</th>
                                <th><i class="fas fa-tag mr-1"></i>Categoría</th>
                                <th><i class="fas fa-weight mr-1"></i>Tamaño</th>
                                <th><i class="fas fa-calendar mr-1"></i>Fecha</th>
                                <th><i class="fas fa-eye mr-1"></i>Visible</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documentos_data as $doc): ?>
                            <tr>
                                <td>
                                    <i class="fas fa-file-archive doc-icon mr-2"></i>
                                    <?php echo htmlspecialchars($doc['nombre_archivo']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($doc['categoria'] ?: 'N/A'); ?></td>
                                <td><?php echo number_format($doc['size_bytes'] / 1024, 2); ?> KB</td>
                                <td><?php echo date('d/m/Y H:i', strtotime($doc['fecha_registro'])); ?></td>
                                <td>
                                    <?php if ($autenticado): ?>
                                    <a href="downloads.php?module=toggle_visible&id=<?php echo $doc['id']; ?>&tabla=downloads_documentos" 
                                       class="badge badge-<?php echo ($doc['visible'] === 'SI') ? 'success' : 'secondary'; ?> badge-visible"
                                       title="Cambiar visibilidad">
                                        <?php echo $doc['visible']; ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="badge badge-<?php echo ($doc['visible'] === 'SI') ? 'success' : 'secondary'; ?>"><?php echo $doc['visible']; ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (empty($documentos_data)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-folder-open fa-3x mb-3"></i>
                    <p>No hay documentos</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- ============================================ -->
        <!-- MÓDULO GESTOR (ADMINISTRACIÓN) -->
        <!-- ============================================ -->
        <?php if ($module === 'gestor'): ?>
        
        <?php if (!$carpeta_ok['existe']): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($carpeta_ok['mensaje']); ?>
        </div>
        <?php elseif (!$carpeta_ok['escribible']): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($carpeta_ok['mensaje']); ?>
        </div>
        <?php endif; ?>
        
        <div class="card-custom mb-4">
            <div class="header-custom">
                <h4 class="mb-0"><i class="fas fa-folder mr-2"></i>Gestor de Descargas</h4>
            </div>
            <div class="card-body p-3">
                <?php if (empty($
