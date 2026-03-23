<?php
/**
 * GESTOR DE CARPETA DE DOWNLOADS - VERSIÓN 4.0
 * 
 * Licencia: MIT
 * Fecha: 25 de marzo de 2026
 * Autor: Alfonso Orozco Aguilar
 * Coautor: Modelo AI Asistente (DeepSeek) - Arquitectura PHP Robusta
 * https://vibecodingmexico.com/gestor-de-carpeta-descargas/
 * 
 * Script único para migración de archivos locales a base de datos con gestión de imágenes y documentos.
 * 
 * Stack: PHP 8.x procedural, MariaDB InnoDB, Bootstrap 4.6, Font Awesome 5, jsDelivr.
 * 
 * Características principales:
 * - Escaneo de carpeta ./downloads/
 * - Botones con colores según estado del archivo (Verde = Nuevo, Amarillo = Duplicado, Rojo = Excede tamaño)
 * - Gestión de imágenes (tabla downloads_galeria) y documentos (tabla downloads_documentos)
 * - Filtros por categoría y tamaño
 * - Galería pública con modales
 * - Protección por contraseña hardcoded para operaciones críticas
 * - Menú de navegación fijo y footer fijo
 */

// --- Cabeceras de NO CACHE ---
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- Configuración de la aplicación ---
define('MAX_FILE_SIZE_BYTES', 3 * 1024 * 1024); // 3 MB
define('CONTRASENA_ADMIN', 'Harecoded'); // Contraseña hardcoded para operaciones críticas
define('ITEMS_POR_PAGINA', 16);

// --- Incluir configuración de base de datos ---
// Se asume que config.php existe y establece $link como conexión mysqli
require_once 'config.php'; // Este archivo debe crear $link (conexión MySQLi)

// --- Verificar/Crear estructura de tablas ---
function verificarTablas($link) {
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
}

// --- Inicialización de sesión y verificación ---
session_start();
verificarTablas($link);

// --- Variables globales para el flujo ---
$mensaje = "";
$mensaje_tipo = "";

// --- Verificar carpeta downloads y permisos ---
$carpeta_downloads = "./downloads/";
if (!file_exists($carpeta_downloads)) {
    $mensaje = "La carpeta 'downloads' no existe. Por favor créela.";
    $mensaje_tipo = "danger";
} elseif (!is_writable($carpeta_downloads)) {
    $mensaje = "La carpeta 'downloads' no tiene permisos de escritura.";
    $mensaje_tipo = "danger";
}

// --- Manejo de autenticación ---
function estaAutenticado() {
    return isset($_SESSION['autenticado']) && $_SESSION['autenticado'] === true;
}

function autenticar($password) {
    if ($password === CONTRASENA_ADMIN) {
        $_SESSION['autenticado'] = true;
        return true;
    }
    return false;
}

function cerrarSesion() {
    unset($_SESSION['autenticado']);
    session_destroy();
}

// --- Procesar login ---
if (isset($_POST['login_submit'])) {
    if (autenticar($_POST['password'])) {
        $mensaje = "Autenticación exitosa.";
        $mensaje_tipo = "success";
    } else {
        $mensaje = "Contraseña incorrecta. Operación cancelada.";
        $mensaje_tipo = "danger";
    }
}

// --- Procesar logout ---
if (isset($_GET['logout'])) {
    cerrarSesion();
    $mensaje = "Sesión cerrada correctamente.";
    $mensaje_tipo = "success";
}

// --- Verificar SHA1 en base de datos ---
function existeSHA1($link, $sha1, $es_imagen) {
    $tabla = $es_imagen ? "downloads_galeria" : "downloads_documentos";
    $stmt = mysqli_prepare($link, "SELECT id FROM $tabla WHERE sha1 = ?");
    mysqli_stmt_bind_param($stmt, "s", $sha1);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $existe = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $existe;
}

// --- Insertar archivo en base de datos ---
function insertarArchivo($link, $ruta, $nombre, $comentario, $categoria, $es_imagen) {
    $contenido = file_get_contents($ruta);
    $sha1 = sha1($contenido);
    $mime = mime_content_type($ruta);
    $size = filesize($ruta);
    $fecha_archivo = date("Y-m-d H:i:s", filemtime($ruta));
    $fecha_registro = date("Y-m-d H:i:s");
    $tipo_archivo = $es_imagen ? "Imagen" : "Documento";
    
    $tabla = $es_imagen ? "downloads_galeria" : "downloads_documentos";
    
    $stmt = mysqli_prepare($link, "INSERT INTO $tabla (nombre_archivo, mime_type, contenido, sha1, size_bytes, comentario, tipo_archivo, categoria, visible, fecha_archivo, fecha_registro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'SI', ?, ?)");
    mysqli_stmt_bind_param($stmt, "ssssisssss", $nombre, $mime, $contenido, $sha1, $size, $comentario, $tipo_archivo, $categoria, $fecha_archivo, $fecha_registro);
    $resultado = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    return $resultado;
}

// --- Borrar archivo de base de datos ---
function borrarArchivoDB($link, $id, $es_imagen) {
    $tabla = $es_imagen ? "downloads_galeria" : "downloads_documentos";
    $stmt = mysqli_prepare($link, "DELETE FROM $tabla WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    $resultado = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $resultado;
}

// --- Cambiar visibilidad de un archivo ---
function toggleVisibilidad($link, $id, $es_imagen) {
    $tabla = $es_imagen ? "downloads_galeria" : "downloads_documentos";
    $stmt = mysqli_prepare($link, "UPDATE $tabla SET visible = IF(visible='SI', 'NO', 'SI') WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    $resultado = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $resultado;
}

// --- Obtener todas las categorías únicas (para filtros) ---
function obtenerCategorias($link, $es_imagen) {
    $tabla = $es_imagen ? "downloads_galeria" : "downloads_documentos";
    $query = "SELECT DISTINCT categoria FROM $tabla WHERE categoria IS NOT NULL AND categoria != '' ORDER BY categoria";
    $result = mysqli_query($link, $query);
    $categorias = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $categorias[] = $row['categoria'];
    }
    return $categorias;
}

// --- Procesar inserción de archivo (módulo agregar) ---
if (isset($_POST['insertar_archivo']) && isset($_POST['confirmacion']) && $_POST['confirmacion'] == "1") {
    if (!estaAutenticado()) {
        $mensaje = "Debe autenticarse primero.";
        $mensaje_tipo = "warning";
    } else {
        $archivo_ruta = $_POST['archivo_ruta'];
        $archivo_nombre = $_POST['archivo_nombre'];
        $comentario = $_POST['comentario'];
        $categoria = $_POST['categoria'];
        $es_imagen = isset($_POST['es_imagen']) && $_POST['es_imagen'] == "1";
        
        if (file_exists($archivo_ruta)) {
            if (insertarArchivo($link, $archivo_ruta, $archivo_nombre, $comentario, $categoria, $es_imagen)) {
                // Verificar integridad
                $contenido_db = "";
                $tabla = $es_imagen ? "downloads_galeria" : "downloads_documentos";
                $stmt = mysqli_prepare($link, "SELECT contenido FROM $tabla WHERE sha1 = ? ORDER BY id DESC LIMIT 1");
                $sha1_calc = sha1(file_get_contents($archivo_ruta));
                mysqli_stmt_bind_param($stmt, "s", $sha1_calc);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $contenido_db);
                mysqli_stmt_fetch($stmt);
                mysqli_stmt_close($stmt);
                
                if ($contenido_db === file_get_contents($archivo_ruta)) {
                    unlink($archivo_ruta); // Borrar archivo físico
                    $mensaje = "Archivo insertado correctamente y eliminado del disco.";
                    $mensaje_tipo = "success";
                } else {
                    $mensaje = "Error de integridad: El contenido no coincide. El archivo físico no se eliminará.";
                    $mensaje_tipo = "danger";
                }
            } else {
                $mensaje = "Error al insertar el archivo en la base de datos.";
                $mensaje_tipo = "danger";
            }
        } else {
            $mensaje = "El archivo físico ya no existe.";
            $mensaje_tipo = "danger";
        }
    }
}

// --- Procesar borrado de archivo ---
if (isset($_POST['borrar_archivo']) && isset($_POST['id_borrar']) && isset($_POST['tipo_borrar'])) {
    if (!estaAutenticado()) {
        $mensaje = "Debe autenticarse primero.";
        $mensaje_tipo = "warning";
    } else {
        if (borrarArchivoDB($link, $_POST['id_borrar'], $_POST['tipo_borrar'] == "imagen")) {
            $mensaje = "Archivo eliminado correctamente de la base de datos.";
            $mensaje_tipo = "success";
        } else {
            $mensaje = "Error al eliminar el archivo.";
            $mensaje_tipo = "danger";
        }
    }
}

// --- Procesar cambio de visibilidad ---
if (isset($_POST['toggle_visibilidad']) && isset($_POST['id_visibilidad']) && isset($_POST['tipo_visibilidad'])) {
    if (!estaAutenticado()) {
        $mensaje = "Debe autenticarse primero.";
        $mensaje_tipo = "warning";
    } else {
        if (toggleVisibilidad($link, $_POST['id_visibilidad'], $_POST['tipo_visibilidad'] == "imagen")) {
            $mensaje = "Visibilidad cambiada correctamente.";
            $mensaje_tipo = "success";
        } else {
            $mensaje = "Error al cambiar la visibilidad.";
            $mensaje_tipo = "danger";
        }
    }
}

// --- Filtros ---
$filtro_categoria = isset($_POST['filtro_categoria']) ? $_POST['filtro_categoria'] : (isset($_GET['cat']) ? $_GET['cat'] : '');
$filtro_tamano = isset($_POST['filtro_tamano']) ? $_POST['filtro_tamano'] : (isset($_GET['tam']) ? $_GET['tam'] : '');
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$offset = ($pagina - 1) * ITEMS_POR_PAGINA;

// --- Obtener elementos para galería (solo imágenes visibles) ---
function obtenerGalería($link, $categoria, $offset, $limit) {
    $sql = "SELECT id, nombre_archivo, mime_type, size_bytes, comentario, categoria, fecha_registro 
            FROM downloads_galeria 
            WHERE visible = 'SI' AND tipo_archivo = 'Imagen'";
    $params = [];
    $types = "";
    
    if (!empty($categoria)) {
        $sql .= " AND categoria = ?";
        $params[] = $categoria;
        $types .= "s";
    }
    
    $sql .= " ORDER BY fecha_registro DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = mysqli_prepare($link, $sql);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $items;
}

function contarGalería($link, $categoria) {
    $sql = "SELECT COUNT(*) as total FROM downloads_galeria WHERE visible = 'SI' AND tipo_archivo = 'Imagen'";
    if (!empty($categoria)) {
        $sql .= " AND categoria = '$categoria'";
    }
    $result = mysqli_query($link, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['total'];
}

// --- Obtener documentos con filtros ---
function obtenerDocumentos($link, $categoria, $filtro_tamano, $offset, $limit) {
    $sql = "SELECT id, nombre_archivo, mime_type, size_bytes, comentario, categoria, fecha_registro 
            FROM downloads_documentos 
            WHERE 1=1";
    $params = [];
    $types = "";
    
    if (!empty($categoria)) {
        $sql .= " AND categoria = ?";
        $params[] = $categoria;
        $types .= "s";
    }
    
    if ($filtro_tamano == "pequeno") {
        $sql .= " AND size_bytes < 1048576"; // < 1MB
    } elseif ($filtro_tamano == "mediano") {
        $sql .= " AND size_bytes BETWEEN 1048576 AND 2097152"; // 1MB - 2MB
    } elseif ($filtro_tamano == "grande") {
        $sql .= " AND size_bytes > 2097152"; // > 2MB
    }
    
    $sql .= " ORDER BY fecha_registro DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = mysqli_prepare($link, $sql);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $items;
}

function contarDocumentos($link, $categoria, $filtro_tamano) {
    $sql = "SELECT COUNT(*) as total FROM downloads_documentos WHERE 1=1";
    if (!empty($categoria)) {
        $sql .= " AND categoria = '$categoria'";
    }
    if ($filtro_tamano == "pequeno") {
        $sql .= " AND size_bytes < 1048576";
    } elseif ($filtro_tamano == "mediano") {
        $sql .= " AND size_bytes BETWEEN 1048576 AND 2097152";
    } elseif ($filtro_tamano == "grande") {
        $sql .= " AND size_bytes > 2097152";
    }
    $result = mysqli_query($link, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['total'];
}

// --- Obtener archivos del sistema para el módulo agregar ---
function obtenerArchivosSistema($carpeta, $excluir = []) {
    $archivos = scandir($carpeta);
    $resultado = [];
    foreach ($archivos as $archivo) {
        if ($archivo == '.' || $archivo == '..') continue;
        if (in_array($archivo, $excluir)) continue;
        $ruta = $carpeta . $archivo;
        if (is_file($ruta)) {
            $resultado[] = $ruta;
        }
    }
    return $resultado;
}

$archivos_sistema = [];
if (file_exists($carpeta_downloads) && is_readable($carpeta_downloads)) {
    $archivos_sistema = obtenerArchivosSistema($carpeta_downloads, ['index.php', basename(__FILE__)]);
}

// --- Preparar datos para la galería ---
$total_galeria = contarGalería($link, $filtro_categoria);
$total_paginas = ceil($total_galeria / ITEMS_POR_PAGINA);
$items_galeria = obtenerGalería($link, $filtro_categoria, $offset, ITEMS_POR_PAGINA);

// --- Preparar datos para documentos ---
$total_documentos = contarDocumentos($link, $filtro_categoria, $filtro_tamano);
$total_paginas_docs = ceil($total_documentos / ITEMS_POR_PAGINA);
$items_documentos = obtenerDocumentos($link, $filtro_categoria, $filtro_tamano, $offset, ITEMS_POR_PAGINA);

// --- Obtener categorías para filtros ---
$categorias_galeria = obtenerCategorias($link, true);
$categorias_docs = obtenerCategorias($link, false);

// --- Función para determinar color de botón según estado del archivo ---
function getEstadoArchivo($link, $ruta) {
    if (!file_exists($ruta)) return "no_existe";
    $size = filesize($ruta);
    $sha1 = sha1(file_get_contents($ruta));
    $es_imagen = strpos(mime_content_type($ruta), 'image/') === 0;
    
    if ($size > MAX_FILE_SIZE_BYTES) {
        return "rojo"; // Excede tamaño
    }
    
    if (existeSHA1($link, $sha1, $es_imagen)) {
        return "amarillo"; // Ya existe
    }
    
    return "verde"; // Nuevo
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Gestor de Descargas - Modelo AI</title>
    <!-- Bootstrap 4.6 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <!-- Font Awesome 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
    <style>
        body {
            padding-top: 70px;
            padding-bottom: 70px;
            background-color: #f8f9fa;
        }
        .navbar {
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            background-color: #343a40;
            color: white;
            text-align: center;
            padding: 10px 0;
            z-index: 1000;
        }
        .card-img-top {
            height: 200px;
            object-fit: cover;
        }
        .card {
            transition: transform 0.2s;
            margin-bottom: 20px;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .btn-verde {
            background-color: #28a745;
            color: white;
        }
        .btn-amarillo {
            background-color: #ffc107;
            color: #212529;
        }
        .btn-rojo {
            background-color: #dc3545;
            color: white;
        }
        .modal-img {
            max-width: 100%;
            max-height: 70vh;
            margin: 0 auto;
            display: block;
        }
        .filtro-bar {
            background-color: white;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .tab-content {
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <!-- Navbar fija -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-database"></i> Gestor Descargas v4.0
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mr-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="https://www.google.com" target="_blank"><i class="fab fa-google"></i> Google</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fas fa-tasks"></i> Opciones
                        </a>
                        <div class="dropdown-menu" aria-labelledby="navbarDropdown">
                            <a class="dropdown-item" href="#galeria">Galería</a>
                            <a class="dropdown-item" href="#documentos">Documentos</a>
                            <a class="dropdown-item" href="#agregar">Agregar Archivos</a>
                        </div>
                    </li>
                    <?php if(estaAutenticado()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="?logout=1"><i class="fas fa-sign-out-alt"></i> Salir</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <span class="navbar-text">
                    <i class="fas fa-user-robot"></i> Modelo AI Coautor
                </span>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if($mensaje): ?>
        <div class="alert alert-<?php echo $mensaje_tipo; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($mensaje); ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php endif; ?>
        
        <?php if(!estaAutenticado()): ?>
        <div class="row justify-content-center mt-5">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4><i class="fas fa-lock"></i> Autenticación Requerida</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="form-group">
                                <label for="password">Contraseña de administrador:</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" name="login_submit" class="btn btn-primary btn-block">
                                <i class="fas fa-sign-in-alt"></i> Autenticar
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        
        <!-- Tabs -->
        <ul class="nav nav-tabs" id="myTab" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="galeria-tab" data-toggle="tab" href="#galeria" role="tab" aria-controls="galeria" aria-selected="true">
                    <i class="fas fa-images"></i> Galería de Imágenes
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="documentos-tab" data-toggle="tab" href="#documentos" role="tab" aria-controls="documentos" aria-selected="false">
                    <i class="fas fa-file-alt"></i> Documentos
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="agregar-tab" data-toggle="tab" href="#agregar" role="tab" aria-controls="agregar" aria-selected="false">
                    <i class="fas fa-upload"></i> Agregar Archivos
                </a>
            </li>
        </ul>
        
        <div class="tab-content">
            <!-- Galería de Imágenes -->
            <div class="tab-pane fade show active" id="galeria" role="tabpanel" aria-labelledby="galeria-tab">
                <div class="filtro-bar">
                    <form method="POST" action="" class="form-inline">
                        <div class="form-group mr-2">
                            <label for="filtro_categoria_galeria" class="mr-2">Filtrar por categoría:</label>
                            <select name="filtro_categoria" id="filtro_categoria_galeria" class="form-control">
                                <option value="">Todas las categorías</option>
                                <?php foreach($categorias_galeria as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filtro_categoria == $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                    </form>
                </div>
                
                <div class="row">
                    <?php if(count($items_galeria) > 0): ?>
                        <?php foreach($items_galeria as $item): ?>
                        <div class="col-md-3">
                            <div class="card">
                                <img class="card-img-top" src="data:<?php echo htmlspecialchars($item['mime_type']); ?>;base64,<?php echo base64_encode(obtenerImagenPorId($link, $item['id'])); ?>" alt="<?php echo htmlspecialchars($item['nombre_archivo']); ?>">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo htmlspecialchars($item['nombre_archivo']); ?></h5>
                                    <p class="card-text">
                                        <small class="text-muted">
                                            <i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i', strtotime($item['fecha_registro'])); ?><br>
                                            <i class="fas fa-tag"></i> <?php echo htmlspecialchars($item['categoria'] ?: 'N/A'); ?><br>
                                            <i class="fas fa-file-alt"></i> <?php echo round($item['size_bytes'] / 1024, 2); ?> KB
                                        </small>
                                    </p>
                                    <button type="button" class="btn btn-info btn-sm btn-block" data-toggle="modal" data-target="#modalImagen<?php echo $item['id']; ?>">
                                        <i class="fas fa-eye"></i> Ver
                                    </button>
                                    <?php if(estaAutenticado()): ?>
                                    <form method="POST" action="" class="mt-2">
                                        <input type="hidden" name="id_visibilidad" value="<?php echo $item['id']; ?>">
                                        <input type="hidden" name="tipo_visibilidad" value="imagen">
                                        <button type="submit" name="toggle_visibilidad" class="btn btn-warning btn-sm btn-block">
                                            <i class="fas fa-eye-slash"></i> Cambiar Visibilidad
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Modal -->
                            <div class="modal fade" id="modalImagen<?php echo $item['id']; ?>" tabindex="-1" role="dialog" aria-labelledby="modalLabel<?php echo $item['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog modal-lg" role="document">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="modalLabel<?php echo $item['id']; ?>">
                                                <i class="fas fa-image"></i> <?php echo htmlspecialchars($item['nombre_archivo']); ?>
                                            </h5>
                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </div>
                                        <div class="modal-body">
                                            <img src="data:<?php echo htmlspecialchars($item['mime_type']); ?>;base64,<?php echo base64_encode(obtenerImagenPorId($link, $item['id'])); ?>" class="modal-img" alt="<?php echo htmlspecialchars($item['nombre_archivo']); ?>">
                                            <hr>
                                            <p><strong><i class="fas fa-tag"></i> Categoría:</strong> <?php echo htmlspecialchars($item['categoria'] ?: 'N/A'); ?></p>
                                            <p><strong><i class="fas fa-file"></i> Archivo:</strong> <?php echo htmlspecialchars($item['nombre_archivo']); ?></p>
                                            <p><strong><i class="fas fa-weight-hanging"></i> Tamaño:</strong> <?php echo round($item['size_bytes'] / 1024, 2); ?> KB</p>
                                            <p><strong><i class="fas fa-calendar-alt"></i> Fecha de subida:</strong> <?php echo date('d/m/Y H:i:s', strtotime($item['fecha_registro'])); ?></p>
                                            <p><strong><i class="fas fa-comment"></i> Comentario:</strong> <?php echo nl2br(htmlspecialchars($item['comentario'] ?: 'Sin comentario')); ?></p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="col-12">
                        <div class="alert alert-info">No hay imágenes disponibles en la galería.</div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Paginación Galería -->
                <?php if($total_paginas > 1): ?>
                <nav aria-label="Navegación galería">
                    <ul class="pagination justify-content-center">
                        <?php for($i = 1; $i <= $total_paginas; $i++): ?>
                        <li class="page-item <?php echo $pagina == $i ? 'active' : ''; ?>">
                            <a class="page-link" href="?pagina=<?php echo $i; ?>&cat=<?php echo urlencode($filtro_categoria); ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
            
            <!-- Documentos -->
            <div class="tab-pane fade" id="documentos" role="tabpanel" aria-labelledby="documentos-tab">
                <div class="filtro-bar">
                    <form method="POST" action="" class="form-inline">
                        <div class="form-group mr-2">
                            <label for="filtro_categoria_docs" class="mr-2">Categoría:</label>
                            <select name="filtro_categoria" id="filtro_categoria_docs" class="form-control">
                                <option value="">Todas</option>
                                <?php foreach($categorias_docs as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filtro_categoria == $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mr-2">
                            <label for="filtro_tamano" class="mr-2">Tamaño:</label>
                            <select name="filtro_tamano" id="filtro_tamano" class="form-control">
                                <option value="">Todos</option>
                                <option value="pequeno" <?php echo $filtro_tamano == 'pequeno' ? 'selected' : ''; ?>>Pequeño (&lt;1MB)</option>
                                <option value="mediano" <?php echo $filtro_tamano == 'mediano' ? 'selected' : ''; ?>>Mediano (1-2MB)</option>
                                <option value="grande" <?php echo $filtro_tamano == 'grande' ? 'selected' : ''; ?>>Grande (&gt;2MB)</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
                    </form>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="thead-dark">
                            <tr>
                                <th>Nombre</th>
                                <th>Tipo</th>
                                <th>Tamaño</th>
                                <th>Categoría</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($items_documentos) > 0): ?>
                                <?php foreach($items_documentos as $doc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($doc['nombre_archivo']); ?></td>
                                    <td><?php echo htmlspecialchars($doc['mime_type']); ?></td>
                                    <td><?php echo round($doc['size_bytes'] / 1024, 2); ?> KB</td>
                                    <td><?php echo htmlspecialchars($doc['categoria'] ?: 'N/A'); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($doc['fecha_registro'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-info btn-sm" data-toggle="modal" data-target="#modalDoc<?php echo $doc['id']; ?>">
                                            <i class="fas fa-eye"></i> Ver
                                        </button>
                                        <?php if(estaAutenticado()): ?>
                                        <form method="POST" action="" style="display:inline;">
                                            <input type="hidden" name="id_borrar" value="<?php echo $doc['id']; ?>">
                                            <input type="hidden" name="tipo_borrar" value="documento">
                                            <button type="submit" name="borrar_archivo" class="btn btn-danger btn-sm" onclick="return confirm('¿Está usted seguro de eliminar este documento?');">
                                                <i class="fas fa-trash"></i> Eliminar
                                            </button>
                                        </form>
                                        <form method="POST" action="" style="display:inline;">
                                            <input type="hidden" name="id_visibilidad" value="<?php echo $doc['id']; ?>">
                                            <input type="hidden" name="tipo_visibilidad" value="documento">
                                            <button type="submit" name="toggle_visibilidad" class="btn btn-warning btn-sm">
                                                <i class="fas fa-eye-slash"></i> Cambiar Visibilidad
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                
                                <!-- Modal Documento -->
                                <div class="modal fade" id="modalDoc<?php echo $doc['id']; ?>" tabindex="-1" role="dialog" aria-labelledby="modalDocLabel<?php echo $doc['id']; ?>" aria-hidden="true">
                                    <div class="modal-dialog" role="document">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="modalDocLabel<?php echo $doc['id']; ?>">
                                                    <i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($doc['nombre_archivo']); ?>
                                                </h5>
                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                    <span aria-hidden="true">&times;</span>
                                                </button>
                                            </div>
                                            <div class="modal-body">
                                                <p><strong>Categoría:</strong> <?php echo htmlspecialchars($doc['categoria'] ?: 'N/A'); ?></p>
                                                <p><strong>Archivo:</strong> <?php echo htmlspecialchars($doc['nombre_archivo']); ?></p>
                                                <p><strong>Tipo:</strong> <?php echo htmlspecialchars($doc['mime_type']); ?></p>
                                                <p><strong>Tamaño:</strong> <?php echo round($doc['size_bytes'] / 1024, 2); ?> KB</p>
                                                <p><strong>Fecha de subida:</strong> <?php echo date('d/m/Y H:i:s', strtotime($doc['fecha_registro'])); ?></p>
                                                <p><strong>Comentario:</strong> <?php echo nl2br(htmlspecialchars($doc['comentario'] ?: 'Sin comentario')); ?></p>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center">No hay documentos disponibles.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginación Documentos -->
                <?php if($total_paginas_docs > 1): ?>
                <nav aria-label="Navegación documentos">
                    <ul class="pagination justify-content-center">
                        <?php for($i = 1; $i <= $total_paginas_docs; $i++): ?>
                        <li class="page-item <?php echo $pagina == $i ? 'active' : ''; ?>">
                            <a class="page-link" href="?pagina=<?php echo $i; ?>&cat=<?php echo urlencode($filtro_categoria); ?>&tam=<?php echo urlencode($filtro_tamano); ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
            
            <!-- Agregar Archivos -->
            <div class="tab-pane fade" id="agregar" role="tabpanel" aria-labelledby="agregar-tab">
                <div class="row">
                    <div class="col-12">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Estado de los archivos:
                            <span class="badge badge-success">Verde</span> = Nuevo (puede subirse) | 
                            <span class="badge badge-warning">Amarillo</span> = Ya existe en BD | 
                            <span class="badge badge-danger">Rojo</span> = Excede tamaño (<?php echo MAX_FILE_SIZE_BYTES / 1024 / 1024; ?>MB)
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <?php if(count($archivos_sistema) > 0): ?>
                        <?php foreach($archivos_sistema as $ruta): 
                            $nombre = basename($ruta);
                            $estado = getEstadoArchivo($link, $ruta);
                            $color = "";
                            $texto_boton = "";
                            $disabled = false;
                            
                            switch($estado) {
                                case "verde":
                                    $color = "btn-verde";
                                    $texto_boton = "Subir a BD";
                                    break;
                                case "amarillo":
                                    $color = "btn-amarillo";
                                    $texto_boton = "Ya existe en BD";
                                    break;
                                case "rojo":
                                    $color = "btn-rojo";
                                    $texto_boton = "Excede tamaño";
                                    break;
                                default:
                                    $color = "btn-secondary";
                                    $texto_boton = "Error";
                                    $disabled = true;
                            }
                            
                            $es_imagen = strpos(mime_content_type($ruta), 'image/') === 0;
                        ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo htmlspecialchars($nombre); ?></h5>
                                    <p class="card-text">
                                        <small class="text-muted">
                                            Tamaño: <?php echo round(filesize($ruta) / 1024, 2); ?> KB<br>
                                            Tipo: <?php echo mime_content_type($ruta); ?><br>
                                            <?php if($es_imagen): ?>
                                            <i class="fas fa-image text-info"></i> Imagen
                                            <?php else: ?>
                                            <i class="fas fa-file text-secondary"></i> Documento
                                            <?php endif; ?>
                                        </small>
                                    </p>
                                    
                                    <?php if($estado == "verde"): ?>
                                    <button type="button" class="btn <?php echo $color; ?> btn-block" data-toggle="modal" data-target="#modalSubir<?php echo md5($ruta); ?>">
                                        <i class="fas fa-upload"></i> <?php echo $texto_boton; ?>
                                    </button>
                                    <?php else: ?>
                                    <a href="#" class="btn <?php echo $color; ?> btn-block" target="_blank" onclick="return false;">
                                        <i class="fas fa-ban"></i> <?php echo $texto_boton; ?>
                                    </a>
                                    <form method="POST" action="" class="mt-2">
                                        <input type="hidden" name="id_borrar" value="<?php echo $ruta; ?>">
                                        <button type="button" class="btn btn-danger btn-block" data-toggle="modal" data-target="#modalBorrarArchivo<?php echo md5($ruta); ?>">
                                            <i class="fas fa-trash"></i> Eliminar
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <a href="<?php echo $ruta; ?>" class="btn btn-info btn-block mt-2" target="_blank">
                                        <i class="fas fa-external-link-alt"></i> Abrir en nueva ventana
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <?php if($estado == "verde"): ?>
                        <!-- Modal para subir archivo -->
                        <div class="modal fade" id="modalSubir<?php echo md5($ruta); ?>" tabindex="-1" role="dialog" aria-labelledby="modalSubirLabel<?php echo md5($ruta); ?>" aria-hidden="true">
                            <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                    <form method="POST" action="">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="modalSubirLabel<?php echo md5($ruta); ?>">
                                                <i class="fas fa-upload"></i> Subir archivo: <?php echo htmlspecialchars($nombre); ?>
                                            </h5>
                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="archivo_ruta" value="<?php echo htmlspecialchars($ruta); ?>">
                                            <input type="hidden" name="archivo_nombre" value="<?php echo htmlspecialchars($nombre); ?>">
                                            <input type="hidden" name="es_imagen" value="<?php echo $es_imagen ? '1' : '0'; ?>">
                                            
                                            <div class="form-group">
                                                <label for="comentario_<?php echo md5($ruta); ?>">Comentario:</label>
                                                <textarea class="form-control" id="comentario_<?php echo md5($ruta); ?>" name="comentario" rows="3"></textarea>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label for="categoria_<?php echo md5($ruta); ?>">Categoría:</label>
                                                <input type="text" class="form-control" id="categoria_<?php echo md5($ruta); ?>" name="categoria">
                                            </div>
                                            
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="confirmacion_<?php echo md5($ruta); ?>" name="confirmacion" value="1" required>
                                                <label class="form-check-label" for="confirmacion_<?php echo md5($ruta); ?>">
                                                    Estoy seguro que quiero ingresar a base de datos
                                                </label>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                                            <button type="submit" name="insertar_archivo" class="btn btn-success">
                                                <i class="fas fa-check"></i> Confirmar y Subir
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Modal para borrar archivo físico -->
                        <div class="modal fade" id="modalBorrarArchivo<?php echo md5($ruta); ?>" tabindex="-1" role="dialog" aria-labelledby="modalBorrarLabel<?php echo md5($ruta); ?>" aria-hidden="true">
                            <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="modalBorrarLabel<?php echo md5($ruta); ?>">
                                            <i class="fas fa-exclamation-triangle"></i> Confirmar eliminación
                                        </h5>
                                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                            <span aria-hidden="true">&times;</span>
                                        </button>
                                    </div>
                                    <div class="modal-body">
                                        <p>¿Está usted seguro de eliminar el archivo <strong><?php echo htmlspecialchars($nombre); ?></strong>?</p>
                                        <p>Esta acción eliminará el archivo físico del disco.</p>
                                        <form method="POST" action="" id="formBorrarArchivo<?php echo md5($ruta); ?>">
                                            <input type="hidden" name="archivo_eliminar" value="<?php echo htmlspecialchars($ruta); ?>">
                                            <div class="form-group">
                                                <label for="password_borrar_<?php echo md5($ruta); ?>">Contraseña de administrador:</label>
                                                <input type="password" class="form-control" id="password_borrar_<?php echo md5($ruta); ?>" name="password_borrar" required>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                                        <button type="button" class="btn btn-danger" onclick="validarYeliminar('<?php echo md5($ruta); ?>')">
                                            <i class="fas fa-trash"></i> Eliminar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="col-12">
                        <div class="alert alert-warning">No se encontraron archivos en la carpeta downloads.</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Footer fijo -->
    <div class="footer">
        <div class="container">
            <span>© 2026 - Gestor de Descargas v4.0 | Modelo AI Coautor | Licencia MIT | Fecha: 25/03/2026</span>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.min.js"></script>
    <script>
        function validarYeliminar(md5) {
            var password = document.getElementById('password_borrar_' + md5).value;
            if (password === '<?php echo CONTRASENA_ADMIN; ?>') {
                document.getElementById('formBorrarArchivo' + md5).submit();
            } else {
                alert('Contraseña incorrecta. Operación cancelada.');
            }
        }
    </script>
</body>
</html>
<?php
// Función auxiliar para obtener imagen por ID (para mostrar en modal)
function obtenerImagenPorId($link, $id) {
    $stmt = mysqli_prepare($link, "SELECT contenido FROM downloads_galeria WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $contenido);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $contenido;
}
?>
