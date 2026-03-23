<?php
/*
Licencia : MIT
Autor Alfonso Orozco Aguilar
Coautor Mistral / lechat
https://vibecodingmexico.com/gestor-de-carpeta-descargas/
*/
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Gestor de Descargas - V4</title>
    <!-- No Cache -->
    <?php
    header("Expires: Tue, 03 Jul 2001 06:00:00 GMT");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    ?>
    <!-- Bootstrap 4.6.x CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" integrity="sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N" crossorigin="anonymous">
    <!-- Font Awesome 5.x -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css" integrity="sha384-DyZ88mC6Up2uqS4h/KRgHuoeGwBcD4Ng9SiP4dIRy0EXTlnuz47vAwmeGwVChigm" crossorigin="anonymous">
    <style>
        body { padding-top: 56px; padding-bottom: 56px; }
        .footer { position: fixed; bottom: 0; width: 100%; height: 56px; line-height: 56px; background-color: #f5f5f5; }
        .modal-content { max-width: 800px; }
        .card-img-top { height: 180px; object-fit: cover; }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <a class="navbar-brand" href="#">Gestor de Descargas (Le Chat v2026.3)</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNavDropdown">
            <ul class="navbar-nav mr-auto">
                <li class="nav-item">
                    <a class="nav-link" href="https://www.google.com" target="_blank"><i class="fab fa-google"></i> Google</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        Opciones
                    </a>
                    <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
                        <a class="dropdown-item" href="#">Opción 1</a>
                        <a class="dropdown-item" href="#">Opción 2</a>
                        <a class="dropdown-item" href="#">Opción 3</a>
                    </div>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="?module=galeria">Galería</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="?module=agregar">Agregar</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="?module=manage">Gestionar</a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4">
        <?php
        // Configuración y funciones
        session_start();
        $PASSWORD_HARDCODED = "LeChat2026!"; // Contraseña hardcoded
        $MAX_FILE_SIZE = 3 * 1024 * 1024; // 3MB
        $DOWNLOADS_DIR = './downloads';

        // Verificar/crear tablas
        function checkTables($link) {
            $queries = [
                "CREATE TABLE IF NOT EXISTS `downloads_galeria` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                "CREATE TABLE IF NOT EXISTS `downloads_documentos` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            ];

            foreach ($queries as $query) {
                if (!$link->query($query)) {
                    die("Error al crear tablas: " . $link->error);
                }
            }
        }

        // Verificar directorio
        function checkDownloadsDir() {
            global $DOWNLOADS_DIR;
            if (!file_exists($DOWNLOADS_DIR)) {
                echo "<div class='alert alert-danger'>La carpeta '$DOWNLOADS_DIR' no existe.</div>";
                return false;
            }
            if (!is_writable($DOWNLOADS_DIR)) {
                echo "<div class='alert alert-danger'>No tienes permisos de escritura en '$DOWNLOADS_DIR'.</div>";
                return false;
            }
            return true;
        }

        // Obtener MIME type
        function getMimeType($file) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file);
            finfo_close($finfo);
            return $mime;
        }

        // Verificar si SHA1 existe en DB
        function sha1Exists($link, $sha1) {
            $query = "SELECT COUNT(*) as count FROM downloads_galeria WHERE sha1 = ? UNION ALL SELECT COUNT(*) as count FROM downloads_documentos WHERE sha1 = ?";
            $stmt = $link->prepare($query);
            $stmt->bind_param("ss", $sha1, $sha1);
            $stmt->execute();
            $result = $stmt->get_result();
            $count = 0;
            while ($row = $result->fetch_assoc()) {
                $count += $row['count'];
            }
            return $count > 0;
        }

        // Insertar archivo en DB
        function insertFile($link, $file, $comentario, $categoria) {
            $nombre_archivo = basename($file);
            $mime_type = getMimeType($file);
            $sha1 = sha1_file($file);
            $size_bytes = filesize($file);
            $fecha_archivo = date("Y-m-d H:i:s", filemtime($file));
            $fecha_registro = date("Y-m-d H:i:s");
            $contenido = file_get_contents($file);
            $tipo_archivo = strpos($mime_type, 'image/') === 0 ? 'Imagen' : 'Documento';
            $table = $tipo_archivo === 'Imagen' ? 'downloads_galeria' : 'downloads_documentos';

            $query = "INSERT INTO `$table` (nombre_archivo, mime_type, contenido, sha1, size_bytes, comentario, categoria, fecha_archivo, fecha_registro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $link->prepare($query);
            $stmt->bind_param("ssssissss", $nombre_archivo, $mime_type, $contenido, $sha1, $size_bytes, $comentario, $categoria, $fecha_archivo, $fecha_registro);
            $stmt->send_long_data(2, $contenido);
            $success = $stmt->execute();

            if ($success) {
                $blob_sha1 = sha1($contenido);
                if ($blob_sha1 === $sha1) {
                    unlink($file);
                    return true;
                } else {
                    $stmt = $link->prepare("DELETE FROM `$table` WHERE sha1 = ?");
                    $stmt->bind_param("s", $sha1);
                    $stmt->execute();
                    return false;
                }
            }
            return false;
        }

        // Mostrar galería
        function showGaleria($link) {
            $categoria = isset($_POST['categoria']) ? $_POST['categoria'] : '';
            $where = $categoria ? "WHERE categoria = ? AND visible = 'SI'" : "WHERE visible = 'SI'";
            $query = "SELECT id, nombre_archivo, size_bytes, fecha_registro, categoria, comentario FROM downloads_galeria $where ORDER BY fecha_registro DESC LIMIT 16";
            $stmt = $link->prepare($query);
            if ($categoria) {
                $stmt->bind_param("s", $categoria);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            echo "<form method='post' class='mb-4'><div class='form-group'><select name='categoria' class='form-control' onchange='this.form.submit()'><option value=''>Todas las categorías</option>";
            $cats = $link->query("SELECT DISTINCT categoria FROM downloads_galeria WHERE categoria IS NOT NULL");
            while ($cat = $cats->fetch_assoc()) {
                echo "<option value='" . htmlspecialchars($cat['categoria']) . "'" . ($categoria === $cat['categoria'] ? " selected" : "") . ">" . htmlspecialchars($cat['categoria']) . "</option>";
            }
            echo "</select></div></form>";

            echo "<div class='row'>";
            while ($row = $result->fetch_assoc()) {
                echo "<div class='col-md-3 mb-4'>";
                echo "<div class='card'>";
                echo "<img src='data:image;base64," . base64_encode(file_get_contents($DOWNLOADS_DIR . '/' . $row['nombre_archivo'])) . "' class='card-img-top' alt='" . htmlspecialchars($row['nombre_archivo']) . "'>";
                echo "<div class='card-body'>";
                echo "<h5 class='card-title'>" . htmlspecialchars($row['nombre_archivo']) . "</h5>";
                echo "<button class='btn btn-primary' data-toggle='modal' data-target='#modal-" . $row['id'] . "'>Ver</button>";
                echo "</div></div>";

                // Modal
                echo "<div class='modal fade' id='modal-" . $row['id'] . "' tabindex='-1' role='dialog' aria-hidden='true'>";
                echo "<div class='modal-dialog modal-dialog-centered' role='document'><div class='modal-content'>";
                echo "<div class='modal-header'><h5 class='modal-title'>" . htmlspecialchars($row['nombre_archivo']) . "</h5><button type='button' class='close' data-dismiss='modal' aria-label='Close'><span aria-hidden='true'>&times;</span></button></div>";
                echo "<div class='modal-body text-center'>";
                echo "<img src='data:image;base64," . base64_encode(file_get_contents($DOWNLOADS_DIR . '/' . $row['nombre_archivo'])) . "' class='img-fluid'>";
                echo "<p><strong>Categoría:</strong> " . ($row['categoria'] ?? 'N/A') . "</p>";
                echo "<p><strong>Tamaño:</strong> " . round($row['size_bytes'] / 1024, 2) . " KB</p>";
                echo "<p><strong>Fecha:</strong> " . $row['fecha_registro'] . "</p>";
                echo "<p><strong>Comentario:</strong> " . ($row['comentario'] ?? 'N/A') . "</p>";
                echo "</div></div></div>";
            }
            echo "</div>";
        }

        // Mostrar lista de documentos
        function showDocumentos($link) {
            $size_filter = isset($_POST['size_filter']) ? (int)$_POST['size_filter'] : 0;
            $where = $size_filter ? "WHERE size_bytes <= ?" : "";
            $query = "SELECT id, nombre_archivo, size_bytes, fecha_registro, categoria, comentario FROM downloads_documentos $where ORDER BY fecha_registro DESC";
            $stmt = $link->prepare($query);
            if ($size_filter) {
                $stmt->bind_param("i", $size_filter);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            echo "<form method='post' class='mb-4'><div class='form-group'><select name='size_filter' class='form-control' onchange='this.form.submit()'><option value='0'>Todos los tamaños</option><option value='102400' " . ($size_filter === 102400 ? "selected" : "") . ">≤ 100KB</option><option value='1024000' " . ($size_filter === 1024000 ? "selected" : "") . ">≤ 1MB</option></select></div></form>";

            echo "<table class='table'><thead><tr><th>Nombre</th><th>Tamaño</th><th>Fecha</th><th>Categoría</th><th>Acciones</th></tr></thead><tbody>";
            while ($row = $result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['nombre_archivo']) . "</td>";
                echo "<td>" . round($row['size_bytes'] / 1024, 2) . " KB</td>";
                echo "<td>" . $row['fecha_registro'] . "</td>";
                echo "<td>" . ($row['categoria'] ?? 'N/A') . "</td>";
                echo "<td><a href='?module=download&id=" . $row['id'] . "' class='btn btn-sm btn-info' target='_blank'>Descargar</a></td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
        }

        // Procesar módulo
        if (isset($_GET['module'])) {
            switch ($_GET['module']) {
                case 'galeria':
                    showGaleria($link);
                    break;
                case 'agregar':
                    if (!checkDownloadsDir()) break;
                    if (isset($_POST['password']) && $_POST['password'] === $PASSWORD_HARDCODED) {
                        $_SESSION['auth'] = true;
                    }
                    if (!isset($_SESSION['auth'])) {
                        echo "<form method='post'><div class='form-group'><label>Contraseña</label><input type='password' name='password' class='form-control'></div><button type='submit' class='btn btn-primary'>Autenticar</button></form>";
                        break;
                    }
                    $files = scandir($DOWNLOADS_DIR);
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..' || $file === 'index.php' || $file === basename(__FILE__)) continue;
                        $file_path = $DOWNLOADS_DIR . '/' . $file;
                        $size = filesize($file_path);
                        $sha1 = sha1_file($file_path);
                        $exists = sha1Exists($link, $sha1);
                        $color = $size > $MAX_FILE_SIZE ? 'danger' : ($exists ? 'warning' : 'success');
                        $disabled = $color !== 'success';
                        echo "<div class='card mb-3'><div class='card-body'>";
                        echo "<h5 class='card-title'>$file</h5>";
                        if ($color === 'success') {
                            echo "<form method='post' action='?module=agregar&file=" . urlencode($file) . "'>";
                            echo "<div class='form-group'><label>Comentario</label><textarea name='comentario' class='form-control'></textarea></div>";
                            echo "<div class='form-group'><label>Categoría</label><input type='text' name='categoria' class='form-control'></div>";
                            echo "<div class='form-check'><input type='checkbox' name='confirm' class='form-check-input' required><label class='form-check-label'>Estoy seguro</label></div>";
                            echo "<button type='submit' class='btn btn-$color'>Procesar</button>";
                            echo "</form>";
                        } else {
                            echo "<a href='$file_path' target='_blank' class='btn btn-$color'>Abrir</a>";
                            echo "<button class='btn btn-danger' data-toggle='modal' data-target='#delete-$file'>Borrar</button>";
                            echo "<div class='modal fade' id='delete-$file' tabindex='-1' role='dialog'><div class='modal-dialog' role='document'><div class='modal-content'><div class='modal-header'><h5 class='modal-title'>Confirmar</h5></div><div class='modal-body'><p>¿Está seguro?</p></div><div class='modal-footer'><form method='post' action='?module=agregar&delete=" . urlencode($file) . "'><input type='password' name='password' class='form-control mb-2' placeholder='Contraseña' required><button type='submit' class='btn btn-danger'>Borrar</button></form></div></div></div>";
                        }
                        echo "</div></div>";
                    }
                    if (isset($_GET['file']) && isset($_POST['confirm'])) {
                        $file = $DOWNLOADS_DIR . '/' . urldecode($_GET['file']);
                        if (insertFile($link, $file, $_POST['comentario'], $_POST['categoria'])) {
                            echo "<div class='alert alert-success'>Archivo procesado correctamente.</div>";
                            echo "<script>window.location.href='?module=agregar';</script>";
                        } else {
                            echo "<div class='alert alert-danger'>Error al procesar el archivo.</div>";
                        }
                    }
                    if (isset($_GET['delete'])) {
                        $file = $DOWNLOADS_DIR . '/' . urldecode($_GET['delete']);
                        if (isset($_POST['password']) && $_POST['password'] === $PASSWORD_HARDCODED) {
                            unlink($file);
                            echo "<div class='alert alert-success'>Archivo borrado.</div>";
                            echo "<script>window.location.href='?module=agregar';</script>";
                        } else {
                            echo "<div class='alert alert-danger'>Contraseña incorrecta. Operación cancelada.</div>";
                        }
                    }
                    break;
                case 'manage':
                    if (!isset($_SESSION['auth'])) {
                        echo "<form method='post'><div class='form-group'><label>Contraseña</label><input type='password' name='password' class='form-control'></div><button type='submit' class='btn btn-primary'>Autenticar</button></form>";
                        break;
                    }
                    showDocumentos($link);
                    break;
                default:
                    showGaleria($link);
            }
        } else {
            showGaleria($link);
        }
        ?>
    </div>

    <!-- Footer -->
    <footer class="footer bg-light">
        <div class="container text-center">
            <span class="text-muted">Gestor de Descargas V4 - 25 de marzo de 2026 | Coautor: Le Chat (Mistral AI) | Licencia MIT</span>
        </div>
    </footer>

    <!-- Bootstrap 4.6.x JS -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-Fy6S3B9q64WdZWQUiU+q4/2Lc9npb8tCaSX9FK7E8HnRr0Jz8D6OP9dO5Vg3Q9ct" crossorigin="anonymous"></script>
</body>
</html>
