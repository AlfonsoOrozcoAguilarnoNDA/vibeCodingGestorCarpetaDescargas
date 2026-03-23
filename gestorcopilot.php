<?php
/**
 * GESTOR DE CARPETA DE DOWNLOADS (ESPECIFICACIÓN V4)
 * Fecha: 25 de marzo de 2026
 * Licencia: MIT
 * Autor Alfonso Orozco Aguilar
 * Coautoría: Microsoft Copilot (Modelo AI)
* https://vibecodingmexico.com/gestor-de-carpeta-descargas/
 */

header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
mb_internal_encoding("UTF-8");

// Configuración
require_once("config.php");
global $link;
$hardcoded_password = "MiClaveSegura2026";
$max_size = 3 * 1024 * 1024; // 3MB

// Crear tablas si no existen
mysqli_query($link, "CREATE TABLE IF NOT EXISTS downloads_galeria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_archivo VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    contenido LONGBLOB NOT NULL,
    sha1 CHAR(40) NOT NULL,
    size_bytes INT NOT NULL,
    comentario TEXT NULL,
    tipo_archivo VARCHAR(50) DEFAULT 'Imagen',
    categoria VARCHAR(30) NULL,
    visible VARCHAR(3) DEFAULT 'SI',
    engine_ia VARCHAR(30) NULL,
    fecha_archivo DATETIME NOT NULL,
    fecha_registro DATETIME NOT NULL,
    INDEX (sha1), INDEX (categoria), INDEX (visible), INDEX (fecha_registro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

mysqli_query($link, "CREATE TABLE IF NOT EXISTS downloads_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_archivo VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    contenido LONGBLOB NOT NULL,
    sha1 CHAR(40) NOT NULL,
    size_bytes INT NOT NULL,
    comentario TEXT NULL,
    tipo_archivo VARCHAR(50) DEFAULT 'Documento',
    categoria VARCHAR(30) NULL,
    visible VARCHAR(3) DEFAULT 'SI',
    engine_ia VARCHAR(30) NULL,
    fecha_archivo DATETIME NOT NULL,
    fecha_registro DATETIME NOT NULL,
    INDEX (sha1), INDEX (categoria), INDEX (visible)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Verificar carpeta
$downloads_dir = __DIR__ . "/downloads";
if (!is_dir($downloads_dir)) {
    echo "<div class='alert alert-danger'>La carpeta downloads no existe.</div>";
}
if (!is_writable($downloads_dir)) {
    echo "<div class='alert alert-danger'>La carpeta downloads no tiene permisos de escritura.</div>";
}

// Escaneo de archivos
$files = [];
if (is_dir($downloads_dir)) {
    foreach (scandir($downloads_dir) as $f) {
        if ($f == "." || $f == ".." || $f == "index.php" || $f == basename(__FILE__)) continue;
        $files[] = $f;
    }
}

// HTML
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Gestor de Downloads</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
  <a class="navbar-brand" href="#">Copilot Modelo</a>
  <ul class="navbar-nav mr-auto">
    <li class="nav-item"><a class="nav-link" href="https://www.google.com" target="_blank">Google</a></li>
    <li class="nav-item dropdown">
      <a class="nav-link dropdown-toggle" href="#" id="menu" data-toggle="dropdown">Opciones</a>
      <div class="dropdown-menu">
        <a class="dropdown-item" href="#">Opción 1</a>
        <a class="dropdown-item" href="#">Opción 2</a>
        <a class="dropdown-item" href="#">Opción 3</a>
      </div>
    </li>
    <li class="nav-item"><a class="nav-link" href="?module=galeria">Galería</a></li>
    <li class="nav-item"><a class="nav-link" href="?module=agregar">Agregar</a></li>
    <li class="nav-item"><a class="nav-link" href="?module=documentos">Documentos</a></li>
  </ul>
</nav>

<div class="container" style="margin-top:80px;">
<h3>Archivos detectados en carpeta downloads</h3>
<div class="row">
<?php
foreach ($files as $file) {
    $path = $downloads_dir . "/" . $file;
    $size = filesize($path);
    $sha1 = sha1_file($path);
    $mime = mime_content_type($path);

    // Verificar existencia en DB
    $exists = false;
    $res = mysqli_query($link, "SELECT id FROM downloads_galeria WHERE sha1='$sha1' UNION SELECT id FROM downloads_documentos WHERE sha1='$sha1'");
    if (mysqli_num_rows($res) > 0) $exists = true;

    $btn_class = "btn-success";
    $status = "Nuevo";
    if ($exists) { $btn_class = "btn-warning"; $status = "Duplicado"; }
    if ($size > $max_size) { $btn_class = "btn-danger"; $status = "Excede tamaño"; }

    echo "<div class='col-md-3'><div class='card mb-4'>";
    echo "<div class='card-body'>";
    echo "<h5 class='card-title'>" . htmlspecialchars($file) . "</h5>";
    echo "<p>Tamaño: " . round($size/1024,2) . " KB<br>Estado: $status</p>";
    if ($btn_class == "btn-success") {
        echo "<form method='post' enctype='multipart/form-data'>";
        echo "<input type='hidden' name='archivo' value='" . htmlspecialchars($file) . "'>";
        echo "<textarea name='comentario' class='form-control' placeholder='Comentario'></textarea><br>";
        echo "<input type='text' name='categoria' class='form-control' placeholder='Categoría'><br>";
        echo "<input type='checkbox' name='confirmar'> Estoy seguro<br><br>";
        echo "<input type='password' name='clave' class='form-control' placeholder='Contraseña'><br>";
        echo "<button type='submit' class='btn $btn_class btn-block'>Ingresar</button>";
        echo "</form>";
    } else {
        echo "<a href='downloads/" . urlencode($file) . "' target='_blank' class='btn $btn_class btn-block'>Abrir</a>";
        echo "<button class='btn btn-secondary btn-block' data-toggle='modal' data-target='#confirmModal'>Borrar</button>";
    }
    echo "</div></div></div>";
}
?>
</div>
</div>

<footer class="footer bg-dark text-white fixed-bottom text-center">
  <div class="container">
    <small>Gestor de Downloads | MIT | Copilot coautor | 25 de marzo de 2026</small>
  </div>
</footer>

<!-- Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Confirmación</h5></div>
    <div class="modal-body">¿Está usted seguro?</div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
      <form method="post">
        <input type="password" name="clave" class="form-control" placeholder="Contraseña">
        <button type="submit" class="btn btn-danger">Borrar</button>
      </form>
    </div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
