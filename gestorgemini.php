<?php
die ("Funciona pero mejor usar gestorgemini2_2.php");
/**
 * PROYECTO: GESTOR DE CARPETA DE DOWNLOADS (ESPECIFICACIÓN V4)
 * Fecha: 25 de marzo de 2026
 * Licencia: MIT
 * https://vibecodingmexico.com/gestor-de-carpeta-descargas/
 * Autor Alfonso Orozco Aguilar 
 * Coautor: Gemini 3 Flash Web
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
$limite_archivo = 3 * 1024 * 1024; // 3 MB
$upload_dir = "./downloads/";
$self = basename(__FILE__);

// Simulación de config.php si no existe para evitar errores de ejecución en este entorno
if (file_exists("config.php")) {
    include("config.php");
} else {
    // Solo para propósitos de visualización del código
    $link = mysqli_connect("localhost", "root", "", "test");
}

if ($link) {
    mysqli_set_charset($link, "utf8mb4");
}

// 3. LÓGICA DE AUTENTICACIÓN
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

// 4. PROCESAMIENTO DE OPERACIONES (POST)
// --- Cambio de Visibilidad ---
if ($is_auth && isset($_POST["toggle_visible"])) {
    global $link;
    $id = (int)$_POST["target_id"];
    $table = ($_POST["target_table"] === "galeria") ? "downloads_galeria" : "downloads_documentos";
    $current = $_POST["current_status"];
    $new_status = ($current === "SI") ? "NO" : "SI";
    
    $query = "UPDATE $table SET visible = '$new_status' WHERE id = $id";
    mysqli_query($link, $query);
}

// --- Inserción Verde (Migración) ---
if ($is_auth && isset($_POST["upload_file"])) {
    global $link;
    $filename = $_POST["filename"];
    $filepath = $upload_dir . $filename;
    
    if (file_exists($filepath) && isset($_POST["confirm_upload"])) {
        $sha1_orig = sha1_file($filepath);
        $content = mysqli_real_escape_string($link, file_get_contents($filepath));
        $mime = mime_content_type($filepath);
        $size = filesize($filepath);
        $mtime = date("Y-m-d H:i:s", filemtime($filepath));
        $now = date("Y-m-d H:i:s");
        $comentario = mysqli_real_escape_string($link, $_POST["comentario"]);
        $categoria = mysqli_real_escape_string($link, $_POST["categoria"]);
        
        $tabla = (str_starts_with($mime, "image/")) ? "downloads_galeria" : "downloads_documentos";
        $tipo = (str_starts_with($mime, "image/")) ? "Imagen" : "Documento";
        
        $sql = "INSERT INTO $tabla (nombre_archivo, mime_type, contenido, sha1, size_bytes, comentario, tipo_archivo, categoria, fecha_archivo, fecha_registro) 
                VALUES ('$filename', '$mime', '$content', '$sha1_orig', $size, '$comentario', '$tipo', '$categoria', '$mtime', '$now')";
        
        if (mysqli_query($link, $sql)) {
            $last_id = mysqli_insert_id($link);
            // Validación forense SHA1 del BLOB
            $res = mysqli_query($link, "SELECT sha1 FROM $tabla WHERE id = $last_id");
            $row = mysqli_fetch_assoc($res);
            
            if ($row["sha1"] === $sha1_orig) {
                unlink($filepath);
                header("Location: " . $self . "?module=agregar&success=1");
                exit;
            }
        }
    }
}

// --- Borrado Físico ---
if (isset($_POST["delete_physical"])) {
    if ($_POST["confirm_pass"] === $password_master) {
        $file_to_del = $upload_dir . $_POST["filename"];
        if (file_exists($file_to_del)) {
            unlink($file_to_del);
            $msg_del = "Archivo eliminado correctamente.";
        }
    } else {
        $error_del = "Operación cancelada. Password incorrecto.";
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vibecoding - Gestor de Downloads</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { padding-top: 70px; padding-bottom: 70px; background-color: #f8f9fa; }
        .card-img-top { height: 180px; object-fit: cover; cursor: pointer; }
        .navbar-dark { background-color: #2c3e50; }
        .footer { position: fixed; bottom: 0; width: 100%; height: 60px; background-color: #2c3e50; color: white; line-height: 60px; }
        .btn-verde { background-color: #28a745; color: white; }
        .btn-amarillo { background-color: #ffc107; color: black; }
        .btn-rojo { background-color: #dc3545; color: white; }
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
                <li class="nav-item"><a class="nav-link" href="https://www.google.com" target="_blank">Google</a></li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="dropGen" data-toggle="dropdown">Opciones</a>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="#">Perfil</a>
                        <a class="dropdown-item" href="#">Ajustes</a>
                        <a class="dropdown-item" href="#">Logs</a>
                    </div>
                </li>
            </ul>
            <ul class="navbar-nav ml-auto">
                <li class="nav-item"><a class="nav-link" href="?module=galeria">Galería</a></li>
                <li class="nav-item"><a class="nav-link" href="?module=manage">Documentos</a></li>
                <li class="nav-item"><a class="nav-link text-warning" href="?module=agregar">Agregar</a></li>
                <?php if ($is_auth): ?>
                    <li class="nav-item"><a class="nav-link" href="?action=logout">Salir</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<main class="container">
    <?php
    // --- VERIFICACIÓN DE ENTORNO ---
    if (!is_dir($upload_dir)) {
        echo "<div class='alert alert-danger'>Error: La carpeta <b>" . $upload_dir . "</b> no existe.</div>";
    } elseif (!is_writable($upload_dir)) {
        echo "<div class='alert alert-danger'>Error: No hay permisos de escritura en <b>" . $upload_dir . "</b>.</div>";
    }

    if (isset($error_auth)) echo "<div class='alert alert-warning'>$error_auth</div>";
    if (isset($error_del)) echo "<div class='alert alert-danger'>$error_del</div>";

    $module = $_GET["module"] ?? "galeria";

    // ---------------------------------------------------------
    // MÓDULO: AGREGAR (MIGRACIÓN)
    // ---------------------------------------------------------
    if ($module === "agregar"): 
        if (!$is_auth): ?>
            <div class="card mx-auto" style="max-width: 400px;">
                <div class="card-body">
                    <h5 class="card-title">Acceso Administrativo</h5>
                    <form method="POST">
                        <input type="password" name="login_pass" class="form-control mb-3" placeholder="Contraseña Hardcoded" required>
                        <button type="submit" class="btn btn-primary btn-block">Entrar</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <h3>Migración de Archivos Local a DB</h3>
            <div class="table-responsive">
                <table class="table table-hover bg-white">
                    <thead>
                        <tr>
                            <th>Archivo</th>
                            <th>Tamaño</th>
                            <th>Estado / Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $files = scandir($upload_dir);
                        foreach ($files as $f) {
                            if ($f === "." || $f === ".." || $f === "index.php" || $f === $self) continue;
                            
                            $path = $upload_dir . $f;
                            $size = filesize($path);
                            $sha1 = sha1_file($path);
                            
                            // Check duplicados
                            $dup_gal = mysqli_query($link, "SELECT id FROM downloads_galeria WHERE sha1 = '$sha1'");
                            $dup_doc = mysqli_query($link, "SELECT id FROM downloads_documentos WHERE sha1 = '$sha1'");
                            $exists = (mysqli_num_rows($dup_gal) > 0 || mysqli_num_rows($dup_doc) > 0);

                            echo "<tr>";
                            echo "<td>" . $f . "</td>";
                            echo "<td>" . round($size / 1024, 2) . " KB</td>";
                            echo "<td>";

                            if ($size > $limite_archivo) {
                                // ROJO
                                echo "<a href='$path' target='_blank' class='btn btn-rojo btn-sm'>Exceso de Tamaño</a> ";
                                echo "<button class='btn btn-outline-danger btn-sm' data-toggle='modal' data-target='#delModal' data-file='$f'>Borrar</button>";
                            } elseif ($exists) {
                                // AMARILLO
                                echo "<a href='$path' target='_blank' class='btn btn-amarillo btn-sm'>Ya existe en DB</a> ";
                                echo "<button class='btn btn-outline-danger btn-sm' data-toggle='modal' data-target='#delModal' data-file='$f'>Borrar</button>";
                            } else {
                                // VERDE
                                ?>
                                <button class="btn btn-verde btn-sm" data-toggle="collapse" data-target="#form_<?php echo md5($f); ?>">Migrar a DB</button>
                                <div id="form_<?php echo md5($f); ?>" class="collapse mt-2 p-2 border bg-light">
                                    <form method="POST">
                                        <input type="hidden" name="filename" value="<?php echo $f; ?>">
                                        <input type="text" name="categoria" class="form-control mb-1" placeholder="Categoría" required>
                                        <textarea name="comentario" class="form-control mb-1" placeholder="Comentario"></textarea>
                                        <div class="form-check mb-2">
                                            <input type="checkbox" name="confirm_upload" class="form-check-input" required>
                                            <label class="form-check-label">Estoy seguro de ingresar a base de datos</label>
                                        </div>
                                        <button type="submit" name="upload_file" class="btn btn-success btn-sm">Ejecutar Migración</button>
                                    </form>
                                </div>
                                <?php
                            }
                            echo "</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php 
    // ---------------------------------------------------------
    // MÓDULO: GALERÍA (PÚBLICO)
    // ---------------------------------------------------------
    elseif ($module === "galeria"): 
        $cat_filter = $_POST["cat_filter"] ?? "";
        $where = "WHERE visible = 'SI' AND tipo_archivo = 'Imagen'";
        if ($cat_filter) $where .= " AND categoria = '" . mysqli_real_escape_string($link, $cat_filter) . "'";
        
        $res = mysqli_query($link, "SELECT * FROM downloads_galeria $where ORDER BY fecha_registro DESC LIMIT 16");
        ?>
        <div class="row mb-4">
            <div class="col-md-4">
                <form method="POST" class="form-inline">
                    <select name="cat_filter" class="form-control mr-2">
                        <option value="">Todas las categorías</option>
                        <?php
                        $cats = mysqli_query($link, "SELECT DISTINCT categoria FROM downloads_galeria");
                        while($c = mysqli_fetch_assoc($cats)) echo "<option value='".$c["categoria"]."'>".$c["categoria"]."</option>";
                        ?>
                    </select>
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </form>
            </div>
        </div>

        <div class="row">
            <?php while($img = mysqli_fetch_assoc($res)): ?>
                <div class="col-md-3 mb-4">
                    <div class="card h-100 shadow-sm">
                        <img src="data:<?php echo $img["mime_type"]; ?>;base64,<?php echo base64_encode($img["contenido"]); ?>" 
                             class="card-img-top" 
                             data-toggle="modal" 
                             data-target="#imgModal"
                             data-nombre="<?php echo $img["nombre_archivo"]; ?>"
                             data-cat="<?php echo $img["categoria"] ?: 'n/a'; ?>"
                             data-size="<?php echo round($img["size_bytes"]/1024, 2); ?> KB"
                             data-fecha="<?php echo $img["fecha_registro"]; ?>"
                             data-com="<?php echo $img["comentario"]; ?>"
                             data-full="data:<?php echo $img["mime_type"]; ?>;base64,<?php echo base64_encode($img["contenido"]); ?>">
                        <div class="card-body p-2 text-center">
                            <small class="d-block font-weight-bold"><?php echo $img["nombre_archivo"]; ?></small>
                            <?php if ($is_auth): ?>
                                <form method="POST" class="mt-2">
                                    <input type="hidden" name="target_id" value="<?php echo $img["id"]; ?>">
                                    <input type="hidden" name="target_table" value="galeria">
                                    <input type="hidden" name="current_status" value="<?php echo $img["visible"]; ?>">
                                    <button type="submit" name="toggle_visible" class="btn btn-xs <?php echo ($img["visible"]==='SI')?'btn-success':'btn-secondary'; ?>">
                                        Visible: <?php echo $img["visible"]; ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

    <?php 
    // ---------------------------------------------------------
    // MÓDULO: MANAGE (DOCUMENTOS)
    // ---------------------------------------------------------
    elseif ($module === "manage"): 
        if (!$is_auth): ?>
            <div class="alert alert-info">Acceso restringido. Por favor, autentícate en el módulo Agregar.</div>
        <?php else: 
            $res = mysqli_query($link, "SELECT * FROM downloads_documentos ORDER BY size_bytes DESC");
            ?>
            <h3>Listado de Documentos (Orden por Tamaño)</h3>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Categoría</th>
                        <th>Tamaño</th>
                        <th>Visibilidad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($doc = mysqli_fetch_assoc($res)): ?>
                        <tr>
                            <td><?php echo $doc["nombre_archivo"]; ?></td>
                            <td><?php echo $doc["categoria"]; ?></td>
                            <td><?php echo round($doc["size_bytes"]/1024, 2); ?> KB</td>
                            <td>
                                <form method="POST">
                                    <input type="hidden" name="target_id" value="<?php echo $doc["id"]; ?>">
                                    <input type="hidden" name="target_table" value="documentos">
                                    <input type="hidden" name="current_status" value="<?php echo $doc["visible"]; ?>">
                                    <button type="submit" name="toggle_visible" class="btn btn-sm <?php echo ($doc["visible"]==='SI')?'btn-success':'btn-secondary'; ?>">
                                        <?php echo $doc["visible"]; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</main>

<footer class="footer text-center">
    <div class="container">
        <span>© 2026 Vibecoding México | Licencia MIT | Gemini 3 Flash v4.1</span>
    </div>
</footer>

<div class="modal fade" id="imgModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="m-title"></h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body text-center">
                <img src="" id="m-img" class="img-fluid mb-3">
                <div class="text-left border-top pt-2">
                    <p><b>Categoría:</b> <span id="m-cat"></span></p>
                    <p><b>Tamaño:</b> <span id="m-size"></span></p>
                    <p><b>Fecha Subida:</b> <span id="m-fecha"></span></p>
                    <p><b>Comentario:</b> <p id="m-com"></p></p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="delModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">¿Está usted seguro?</h5>
                </div>
                <div class="modal-body">
                    <p>Va a eliminar físicamente el archivo: <b id="del-filename-text"></b></p>
                    <input type="hidden" name="filename" id="del-filename">
                    <input type="password" name="confirm_pass" class="form-control" placeholder="Confirme con Password Hardcoded" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" name="delete_physical" class="btn btn-danger">Borrar Definitivamente</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Inyectar datos en modal de imagen
    $('#imgModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        $('#m-title').text(button.data('nombre'));
        $('#m-img').attr('src', button.data('full'));
        $('#m-cat').text(button.data('cat'));
        $('#m-size').text(button.data('size'));
        $('#m-fecha').text(button.data('fecha'));
        $('#m-com').text(button.data('com'));
    });

    // Inyectar datos en modal de borrado
    $('#delModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        $('#del-filename').val(button.data('file'));
        $('#del-filename-text').text(button.data('file'));
    });
</script>
</body>
</html>
