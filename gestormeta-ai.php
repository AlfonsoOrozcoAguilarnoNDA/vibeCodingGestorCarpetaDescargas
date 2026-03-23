<?php
/**
 * PROMPT : GESTOR DE CARPETA DE DOWNLOADS (ESPECIFICACIÓN V4)
 * https://vibecodingmexico.com/gestor-de-carpeta-descargas/
 * @ Autor Alfonso Orozco Aguilar
 * @coauthor Meta AI (Modelo de Lenguaje de Gran Escala)
 * @copyright 2026 Meta AI
 * @license MIT License
 * @version 1.0.0
 * @date 2026-03-25
 */

// -----------------------------------------------------------
// 1. REGLAS DE IDENTIDAD Y CUMPLIMIENTO
// -----------------------------------------------------------

// Control de Caché: El script debe iniciar con cabeceras PHP header para NO CACHE
header("Expires: Tue, 01 Jan 1980 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Codificación: Todo el sistema, conexión y salida debe ser estrictamente UTF-8 MB4.
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Habilitar reportes de errores de MySQLi

// Iniciar sesión para la autenticación
session_start();

// -----------------------------------------------------------
// 2. STACK TECNOLÓGICO Y ESTILO & CONFIGURACIÓN HARDCODED
// -----------------------------------------------------------

// Suponemos que config.php ya tiene mysqli_connection establecido en la variable $link
// Para este ejemplo, simularemos la conexión
// En un entorno real, descomentarías la siguiente línea y asegurarías que config.php exista.
// include 'config.php';

// --- SIMULACIÓN DE config.php ---
// En un proyecto real, esto estaría en un archivo config.php separado.
$db_host = "localhost";
$db_user = "root";
$db_pass = ""; // ¡Cambia esto por tu contraseña de MariaDB!
$db_name = "downloads_db"; // ¡Cambia esto por el nombre de tu base de datos!

$link = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($link->connect_error) {
    die("Error de conexión a la base de datos: " . $link->connect_error);
}
$link->set_charset("utf8mb4");
// --- FIN SIMULACIÓN DE config.php ---

// Contraseña hardcoded para el módulo administrativo
define('HARDCODED_PASSWORD', 'MiPasswordSecreto123'); // ¡CAMBIA ESTO POR UNA CONTRASEÑA SEGURA!
define('MAX_FILE_SIZE_MB', 3); // Tamaño máximo de archivo configurable en MB
define('DOWNLOADS_DIR', 'downloads'); // Directorio donde se buscarán los archivos

// -----------------------------------------------------------
// 3. ESTRUCTURA DE DATOS Y PERMISO ESCRITURA
// -----------------------------------------------------------

// Función para ejecutar sentencias SQL de creación de tablas
function create_tables() {
    global $link;

    $tables = [
        "downloads_galeria" => "
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
        ",
        "downloads_documentos" => "
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
        "
    ];

    $messages = [];
    foreach ($tables as $name => $sql) {
        if ($link->query($sql) === TRUE) {
            $messages[] = "<div class=\"alert alert-success\" role=\"alert\">Tabla `{$name}` verificada/creada correctamente.</div>";
        } else {
            $messages[] = "<div class=\"alert alert-danger\" role=\"alert\">Error al crear la tabla `{$name}`: " . $link->error . "</div>";
        }
    }
    return implode("", $messages);
}

// -----------------------------------------------------------
// Funciones Auxiliares
// -----------------------------------------------------------

function is_image_mime($mime_type) {
    return (strpos($mime_type, 'image/') === 0);
}

function get_file_sha1_from_db($sha1) {
    global $link;
    $stmt = $link->prepare("SELECT id FROM downloads_galeria WHERE sha1 = ? UNION SELECT id FROM downloads_documentos WHERE sha1 = ? LIMIT 1");
    $stmt->bind_param("ss", $sha1, $sha1);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function get_mime_type($filepath) {
    if (function_exists('mime_content_type')) {
        return mime_content_type($filepath);
    } elseif (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filepath);
        finfo_close($finfo);
        return $mime;
    }
    return 'application/octet-stream'; // Fallback
}

// -----------------------------------------------------------
// Lógica de Autenticación
// -----------------------------------------------------------

$auth_message = '';
if (isset($_POST['password']) && $_GET['module'] === 'agregar') {
    if ($_POST['password'] === HARDCODED_PASSWORD) {
        $_SESSION['authenticated'] = true;
        header("Location: " . basename(__FILE__) . "?module=agregar");
        exit();
    } else {
        $auth_message = "<div class=\"alert alert-danger mt-3\">Contraseña incorrecta.</div>";
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['authenticated']);
    session_destroy();
    header("Location: " . basename(__FILE__));
    exit();
}

// -----------------------------------------------------------
// HTML DUCUMENT & HEAD
// -----------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Meta AI: Gestor de Descargas (Especificación V4)</title>
    <!-- Bootstrap 4.6.x CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" integrity="sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N" crossorigin="anonymous">
    <!-- Font Awesome 5.x CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" integrity="sha512-1ycn6IcaQQ40/MKBW2W4Rhis/DbILU74C1vSrLJxCq57o941Ym01SwNsOMqvEBFlcgUa6xLiPY/NS5R+E6ztJQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body {
            padding-top: 56px; /* Altura del navbar fijo */
            padding-bottom: 56px; /* Altura del footer fijo */
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            height: 56px; /* Altura del footer */
            line-height: 56px; /* Centra verticalmente el texto */
            background-color: #f5f5f5;
            text-align: center;
        }
        .file-card {
            margin-bottom: 1rem;
        }
        .modal-xl {
            max-width: 90%;
        }
        .img-fluid-modal {
            max-width: 100%;
            height: auto;
            display: block;
            margin: auto;
        }
        .form-check-label a {
            color: inherit;
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <!-- Fixed Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <a class="navbar-brand" href="<?php echo basename(__FILE__); ?>">Meta AI: Gestor Descargas</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNavDropdown">
            <ul class="navbar-nav mr-auto">
                <li class="nav-item">
                    <a class="nav-link" href="https://www.google.com" target="_blank">Google</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        Opciones Genéricas
                    </a>
                    <div class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
                        <a class="dropdown-item" href="#">Opción 1</a>
                        <a class="dropdown-item" href="#">Opción 2</a>
                        <a class="dropdown-item" href="#">Opción 3</a>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo basename(__FILE__); ?>?module=galeria">Galería</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo basename(__FILE__); ?>?module=documentos">Documentos</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo basename(__FILE__); ?>?module=agregar">Administrar Descargas</a>
                </li>
            </ul>
            <ul class="navbar-nav">
                <?php if (isset($_SESSION['authenticated']) && $_SESSION['authenticated']): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo basename(__FILE__); ?>?action=logout">Salir</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <div class="container-fluid mt-3">

    <?php
    // Mensajes de estado (tablas, permisos)
    echo create_tables();

    $downloads_path = './' . DOWNLOADS_DIR . '/';
    $write_perm_ok = false;
    if (!is_dir($downloads_path)) {
        echo "<div class=\"alert alert-info\" role=\"alert\">La carpeta '" . DOWNLOADS_DIR . "' no existe. Intentando crearla...</div>";
        if (mkdir($downloads_path, 0775, true)) {
            echo "<div class=\"alert alert-success\" role=\"alert\">Carpeta '" . DOWNLOADS_DIR . "' creada exitosamente.</div>";
            $write_perm_ok = is_writable($downloads_path);
        } else {
            echo "<div class=\"alert alert-danger\" role=\"alert\">Error: No se pudo crear la carpeta '" . DOWNLOADS_DIR . "'. Verifique permisos.</div>";
        }
    } else {
        if (is_writable($downloads_path)) {
            $write_perm_ok = true;
        } else {
            echo "<div class=\"alert alert-danger\" role=\"alert\">Error: El directorio '" . DOWNLOADS_DIR . "' no tiene permisos de escritura.</div>";
        }
    }

    $current_script_name = basename(__FILE__);

    // -----------------------------------------------------------
    // Módulo de Agregar/Administrar Descargas (?module=agregar)
    // -----------------------------------------------------------
    if (isset($_GET['module']) && $_GET['module'] === 'agregar') {
        if (!(isset($_SESSION['authenticated']) && $_SESSION['authenticated'])) {
            // Formulario de autenticación
            echo "
                <div class=\"row justify-content-center\">
                    <div class=\"col-md-6\">
                        <div class=\"card mt-5\">
                            <div class=\"card-header\">
                                Autenticación Requerida
                            </div>
                            <div class=\"card-body\">
                                <form method=\"POST\" action=\"{$current_script_name}?module=agregar\">
                                    <div class=\"form-group\">
                                        <label for=\"password\">Contraseña:</label>
                                        <input type=\"password\" class=\"form-control\" id=\"password\" name=\"password\" required>
                                    </div>
                                    <button type=\"submit\" class=\"btn btn-primary\">Acceder</button>
                                </form>
                                {$auth_message}
                            </div>
                        </div>
                    </div>
                </div>
            ";
        } else {
            // Lógica para añadir archivos
            if (!$write_perm_ok) {
                echo "<div class=\"alert alert-danger mt-3\" role=\"alert\">No es posible procesar archivos sin permisos de escritura en la carpeta '" . DOWNLOADS_DIR . "'.</div>";
            } else {
                echo "<h2>Administrar Archivos en '" . DOWNLOADS_DIR . "'</h2>";

                // Procesar acciones de usuario (borrar/insertar)
                if (isset($_POST['action'])) {
                    $file_path = $downloads_path . $_POST['filename'];
                    if (!file_exists($file_path)) {
                        echo "<div class=\"alert alert-danger\">Error: El archivo no existe o ya fue movido/borrado.</div>";
                    } else {
                        if ($_POST['action'] === 'delete' && isset($_POST['password_confirm']) && $_POST['password_confirm'] === HARDCODED_PASSWORD) {
                            if (unlink($file_path)) {
                                echo "<div class=\"alert alert-success\">Archivo '" . htmlspecialchars($_POST['filename']) . "' borrado exitosamente.</div>";
                            } else {
                                echo "<div class=\"alert alert-danger\">Error al borrar el archivo '" . htmlspecialchars($_POST['filename']) . "'.</div>";
                            }
                        } elseif ($_POST['action'] === 'delete' && isset($_POST['password_confirm']) && $_POST['password_confirm'] !== HARDCODED_PASSWORD) {
                            echo "<div class=\"alert alert-danger\">Operación cancelada: Contraseña incorrecta para borrar archivo.</div>";
                        } elseif ($_POST['action'] === 'insert' && isset($_POST['confirm_insert'])) {
                            $filename = $_POST['filename'];
                            $file_sha1 = $_POST['file_sha1'];
                            $file_size = $_POST['file_size'];
                            $file_comment = $_POST['comment'] ?? null;
                            $file_category = $_POST['category'] ?? null;
                            $file_mtime = date('Y-m-d H:i:s', filemtime($file_path));
                            $upload_time = date('Y-m-d H:i:s');
                            $mime_type = get_mime_type($file_path);
                            $content = file_get_contents($file_path);

                            $target_table = is_image_mime($mime_type) ? "downloads_galeria" : "downloads_documentos";
                            $tipo_archivo = is_image_mime($mime_type) ? "Imagen" : "Documento";

                            $stmt = $link->prepare("INSERT INTO {$target_table} (nombre_archivo, mime_type, contenido, sha1, size_bytes, comentario, tipo_archivo, categoria, fecha_archivo, fecha_registro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("sssbisssss", $filename, $mime_type, $content, $file_sha1, $file_size, $file_comment, $tipo_archivo, $file_category, $file_mtime, $upload_time);

                            if ($stmt->execute()) {
                                // Verificar SHA1 del BLOB insertado vs archivo original
                                $inserted_id = $stmt->insert_id;
                                $stmt_check = $link->prepare("SELECT SHA1(contenido) FROM {$target_table} WHERE id = ?");
                                $stmt_check->bind_param("i", $inserted_id);
                                $stmt_check->execute();
                                $stmt_check->bind_result($blob_sha1);
                                $stmt_check->fetch();
                                $stmt_check->close();

                                if ($blob_sha1 === $file_sha1) {
                                    if (unlink($file_path)) {
                                        echo "<div class=\"alert alert-success\">Archivo '" . htmlspecialchars($filename) . "' insertado en DB y borrado del disco.</div>";
                                    } else {
                                        echo "<div class=\"alert alert-warning\">Archivo '" . htmlspecialchars($filename) . "' insertado en DB, pero error al borrar del disco.</div>";
                                    }
                                } else {
                                    echo "<div class=\"alert alert-danger\">Error: SHA1 del BLOB en DB no coincide con el archivo original. Archivo NO borrado del disco.</div>";
                                    // Considerar borrar el registro de DB si hay inconsistencia
                                }
                            } else {
                                echo "<div class=\"alert alert-danger\">Error al insertar '" . htmlspecialchars($filename) . "' en la base de datos: " . $stmt->error . "</div>";
                            }
                            $stmt->close();
                        }
                    }
                    echo "<meta http-equiv='refresh' content='0'>"; // Recargar página
                    exit();
                }

                // Escaneo y listado de archivos
                $files = scandir($downloads_path);
                echo "<div class=\"row mt-4\">";
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..' || $file === $current_script_name || strtolower($file) === 'index.php') {
                        continue;
                    }

                    $filepath = $downloads_path . $file;
                    if (!is_file($filepath)) {
                        continue;
                    }

                    $file_size = filesize($filepath);
                    $file_sha1 = sha1_file($filepath);
                    $mime_type = get_mime_type($filepath);

                    $color_class = "btn-secondary";
                    $status_text = "Desconocido";
                    $action_buttons = "";
                    $form_insert_fields = "";
                    $is_insertable = false;

                    if ($file_size > MAX_FILE_SIZE_MB * 1024 * 1024) {
                        $color_class = "btn-danger";
                        $status_text = "Demasiado Grande (> " . MAX_FILE_SIZE_MB . "MB)";
                        $action_buttons .= "<a href=\"" . htmlspecialchars($filepath) . "\" target=\"_blank\" class=\"btn btn-sm btn-info\"><i class=\"fas fa-external-link-alt\"></i> Ver</a> ";
                        $action_buttons .= "<button type=\"button\" class=\"btn btn-sm btn-danger\" data-toggle=\"modal\" data-target=\"#deleteModal\" data-filename=\"" . htmlspecialchars($file) . "\"><i class=\"fas fa-trash\"></i> Borrar</button>";
                    } elseif (get_file_sha1_from_db($file_sha1)) {
                        $color_class = "btn-warning";
                        $status_text = "Ya Existe en DB (SHA1)";
                        $action_buttons .= "<a href=\"" . htmlspecialchars($filepath) . "\" target=\"_blank\" class=\"btn btn-sm btn-info\"><i class=\"fas fa-external-link-alt\"></i> Ver</a> ";
                        $action_buttons .= "<button type=\"button\" class=\"btn btn-sm btn-danger\" data-toggle=\"modal\" data-target=\"#deleteModal\" data-filename=\"" . htmlspecialchars($file) . "\"><i class=\"fas fa-trash\"></i> Borrar</button>";
                    } else {
                        $color_class = "btn-success";
                        $status_text = "Listo para Subir";
                        $is_insertable = true;
                        $form_insert_fields = "
                            <div class=\"form-group\">
                                <label for=\"comment_{$file_sha1}\">Comentario:</label>
                                <textarea class=\"form-control\" id=\"comment_{$file_sha1}\" name=\"comment\" rows=\"2\"></textarea>
                            </div>
                            <div class=\"form-group\">
                                <label for=\"category_{$file_sha1}\">Categoría:</label>
                                <input type=\"text\" class=\"form-control\" id=\"category_{$file_sha1}\" name=\"category\">
                            </div>
                            <div class=\"form-check\">
                                <input type=\"checkbox\" class=\"form-check-input\" id=\"confirm_insert_{$file_sha1}\" name=\"confirm_insert\" required>
                                <label class=\"form-check-label\" for=\"confirm_insert_{$file_sha1}\">Estoy seguro que quiero ingresar a base de datos</label>
                            </div>
                            <button type=\"submit\" class=\"btn btn-sm btn-success mt-2\">Insertar en DB</button>
                        ";
                    }

                    echo "
                        <div class=\"col-md-4 file-card\">
                            <div class=\"card\">
                                <div class=\"card-body\">
                                    <h5 class=\"card-title\">" . htmlspecialchars($file) . "</h5>
                                    <p class=\"card-text\">
                                        <strong>Tamaño:</strong> " . round($file_size / 1024, 2) . " KB<br>
                                        <strong>MIME Type:</strong> " . htmlspecialchars($mime_type) . "<br>
                                        <strong>SHA1:</strong> " . htmlspecialchars($file_sha1) . "<br>
                                        <strong>Estado:</strong> <span class=\"badge badge-" . (strpos($color_class, 'success') !== false ? 'success' : (strpos($color_class, 'warning') !== false ? 'warning' : 'danger')) . "\">" . $status_text . "</span>
                                    </p>
                                    <form method=\"POST\" action=\"{$current_script_name}?module=agregar\" onsubmit=\"return confirm('¿Está seguro de esta acción para " . htmlspecialchars($file) . "?');\">
                                        <input type=\"hidden\" name=\"filename\" value=\"" . htmlspecialchars($file) . "\">
                                        <input type=\"hidden\" name=\"file_sha1\" value=\"" . htmlspecialchars($file_sha1) . "\">
                                        <input type=\"hidden\" name=\"file_size\" value=\"" . htmlspecialchars($file_size) . "\">
                                        ";
                    if ($is_insertable) {
                        echo "<input type=\"hidden\" name=\"action\" value=\"insert\">";
                        echo $form_insert_fields;
                    } else {
                        echo $action_buttons;
                    }
                    echo "
                                    </form>
                                </div>
                            </div>
                        </div>
                    ";
                }
                echo "</div>"; // row
            }
        }
    }
    // -----------------------------------------------------------
    // Módulo Galería (?module=galeria)
    // -----------------------------------------------------------
    elseif (isset($_GET['module']) && $_GET['module'] === 'galeria') {
        echo "<h2>Galería de Imágenes</h2>";

        $current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $items_per_page = 16;
        $offset = ($current_page - 1) * $items_per_page;

        $category_filter = $_POST['category_filter'] ?? '';

        $sql_where = " WHERE visible = 'SI' ";
        $params = [];
        $types = "";

        if (!empty($category_filter)) {
            $sql_where .= " AND categoria = ? ";
            $params[] = $category_filter;
            $types .= "s";
        }

        // Obtener categorías para el filtro
        $categories_res = $link->query("SELECT DISTINCT categoria FROM downloads_galeria WHERE categoria IS NOT NULL AND categoria != '' ORDER BY categoria");
        $categories = [];
        if ($categories_res) {
            while ($row = $categories_res->fetch_assoc()) {
                $categories[] = $row['categoria'];
            }
        }
        ?>
        <div class="row mb-3">
            <div class="col-md-4">
                <form method="POST" action="<?php echo basename(__FILE__); ?>?module=galeria">
                    <div class="form-group">
                        <label for="category_filter">Filtrar por Categoría:</label>
                        <div class="input-group">
                            <select class="form-control" id="category_filter" name="category_filter">
                                <option value="">Todas las Categorías</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($category_filter === $cat) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="input-group-append">
                                <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-filter"></i> Aplicar</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
        <?php
        $sql = "SELECT id, nombre_archivo, mime_type, size_bytes, comentario, categoria, visible, fecha_registro FROM downloads_galeria " . $sql_where . " ORDER BY fecha_registro DESC LIMIT ? OFFSET ?";
        $stmt = $link->prepare($sql);
        $params[] = $items_per_page;
        $params[] = $offset;
        $types .= "ii";
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                ?>
                <div class="col-sm-6 col-md-4 col-lg-3 mb-4">
                    <div class="card h-100">
                        <img src="<?php echo basename(__FILE__); ?>?action=view_file&id=<?php echo $row['id']; ?>&table=galeria" class="card-img-top" alt="<?php echo htmlspecialchars($row['nombre_archivo']); ?>" style="height: 200px; object-fit: cover;">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title"><?php echo htmlspecialchars($row['nombre_archivo']); ?></h5>
                            <p class="card-text">
                                <small class="text-muted">Categoría: <?php echo htmlspecialchars($row['categoria'] ?? 'N/A'); ?></small><br>
                                <small class="text-muted">Subido: <?php echo date('Y-m-d H:i', strtotime($row['fecha_registro'])); ?></small>
                            </p>
                            <div class="mt-auto">
                                <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#imageModal"
                                        data-id="<?php echo $row['id']; ?>"
                                        data-nombre="<?php echo htmlspecialchars($row['nombre_archivo']); ?>"
                                        data-size="<?php echo round($row['size_bytes'] / 1024, 2); ?> KB"
                                        data-fechasubida="<?php echo date('Y-m-d H:i', strtotime($row['fecha_registro'])); ?>"
                                        data-categoria="<?php echo htmlspecialchars($row['categoria'] ?? 'N/A'); ?>"
                                        data-comentario="<?php echo htmlspecialchars($row['comentario'] ?? 'Sin comentario'); ?>"
                                        data-visible="<?php echo htmlspecialchars($row['visible']); ?>">
                                    Ver Detalle
                                </button>
                                <?php if (isset($_SESSION['authenticated']) && $_SESSION['authenticated']): ?>
                                    <button type="button" class="btn btn-<?php echo ($row['visible'] == 'SI' ? 'success' : 'secondary'); ?> btn-sm toggle-visibility"
                                            data-id="<?php echo $row['id']; ?>" data-table="downloads_galeria" data-current-visibility="<?php echo $row['visible']; ?>">
                                        <?php echo ($row['visible'] == 'SI' ? 'Visible' : 'Oculto'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
            }
        } else {
            echo "<div class=\"col-12 alert alert-info\">No hay imágenes en la galería que coincidan con los criterios.</div>";
        }
        $stmt->close();
        ?>
        </div>

        <?php
        // Paginación
        $sql_count = "SELECT COUNT(*) FROM downloads_galeria " . $sql_where;
        $stmt_count = $link->prepare($sql_count);
        $count_params = $params; // Copia los parámetros para el count
        array_pop($count_params); // Quita LIMIT
        array_pop($count_params); // Quita OFFSET
        $count_types = substr($types, 0, -2); // Quita 'ii' de los tipos
        if (!empty($count_params)) {
             $stmt_count->bind_param($count_types, ...$count_params);
        }
        $stmt_count->execute();
        $stmt_count->bind_result($total_items);
        $stmt_count->fetch();
        $stmt_count->close();

        $total_pages = ceil($total_items / $items_per_page);

        if ($total_pages > 1): ?>
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo basename(__FILE__); ?>?module=galeria&page=<?php echo $current_page - 1; ?>" aria-label="Previous">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($current_page == $i) ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo basename(__FILE__); ?>?module=galeria&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo basename(__FILE__); ?>?module=galeria&page=<?php echo $current_page + 1; ?>" aria-label="Next">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>

        <!-- Image Modal -->
        <div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="imageModalLabel">Detalle de Imagen</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <img src="" id="modalImage" class="img-fluid-modal mb-3" alt="Imagen en detalle">
                        <p><strong>Archivo:</strong> <span id="modalFileName"></span></p>
                        <p><strong>Categoría:</strong> <span id="modalCategory"></span></p>
                        <p><strong>Tamaño:</strong> <span id="modalFileSize"></span></p>
                        <p><strong>Fecha de Subida:</strong> <span id="modalUploadDate"></span></p>
                        <p><strong>Comentario:</strong> <span id="modalComment"></span></p>
                        <?php if (isset($_SESSION['authenticated']) && $_SESSION['authenticated']): ?>
                            <p><strong>Visible:</strong> <span id="modalVisibility"></span></p>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>

        <?php
    }
    // -----------------------------------------------------------
    // Módulo de Documentos (?module=documentos)
    // -----------------------------------------------------------
    elseif (isset($_GET['module']) && $_GET['module'] === 'documentos') {
        if (!(isset($_SESSION['authenticated']) && $_SESSION['authenticated'])) {
            // Formulario de autenticación para ver documentos
            echo "
                <div class=\"row justify-content-center\">
                    <div class=\"col-md-6\">
                        <div class=\"card mt-5\">
                            <div class=\"card-header\">
                                Autenticación Requerida para ver Documentos
                            </div>
                            <div class=\"card-body\">
                                <form method=\"POST\" action=\"{$current_script_name}?module=documentos\">
                                    <div class=\"form-group\">
                                        <label for=\"password\">Contraseña:</label>
                                        <input type=\"password\" class=\"form-control\" id=\"password\" name=\"password\" required>
                                    </div>
                                    <button type=\"submit\" class=\"btn btn-primary\">Acceder</button>
                                </form>
                                {$auth_message}
                            </div>
                        </div>
                    </div>
                </div>
            ";
        } else {
            echo "<h2>Listado de Documentos</h2>";

            $current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $items_per_page = 20; // Más documentos por página
            $offset = ($current_page - 1) * $items_per_page;

            $size_filter = $_POST['size_filter'] ?? ''; // 'small', 'medium', 'large'

            $sql_where = " WHERE 1=1 ";
            $params = [];
            $types = "";

            if (!empty($size_filter)) {
                switch ($size_filter) {
                    case 'small':
                        $sql_where .= " AND size_bytes <= 102400 "; // <= 100KB
                        break;
                    case 'medium':
                        $sql_where .= " AND size_bytes > 102400 AND size_bytes <= 1048576 "; // 100KB - 1MB
                        break;
                    case 'large':
                        $sql_where .= " AND size_bytes > 1048576 "; // > 1MB
                        break;
                }
            }

            // Obtener categorías para el filtro (opcional para documentos, pero mantener consistencia)
            $categories_res = $link->query("SELECT DISTINCT categoria FROM downloads_documentos WHERE categoria IS NOT NULL AND categoria != '' ORDER BY categoria");
            $categories = [];
            if ($categories_res) {
                while ($row = $categories_res->fetch_assoc()) {
                    $categories[] = $row['categoria'];
                }
            }
            ?>
            <div class="row mb-3">
                <div class="col-md-4">
                    <form method="POST" action="<?php echo basename(__FILE__); ?>?module=documentos">
                        <div class="form-group">
                            <label for="size_filter">Filtrar por Tamaño:</label>
                            <div class="input-group">
                                <select class="form-control" id="size_filter" name="size_filter">
                                    <option value="">Todos los Tamaños</option>
                                    <option value="small" <?php echo ($size_filter === 'small') ? 'selected' : ''; ?>>Pequeños (&le; 100KB)</option>
                                    <option value="medium" <?php echo ($size_filter === 'medium') ? 'selected' : ''; ?>>Medianos (100KB - 1MB)</option>
                                    <option value="large" <?php echo ($size_filter === 'large') ? 'selected' : ''; ?>>Grandes (&gt; 1MB)</option>
                                </select>
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-filter"></i> Aplicar</button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Nombre de Archivo</th>
                        <th>Categoría</th>
                        <th>Tamaño</th>
                        <th>MIME Type</th>
                        <th>Fecha Registro</th>
                        <th>Visible</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT id, nombre_archivo, mime_type, size_bytes, comentario, categoria, visible, fecha_registro FROM downloads_documentos " . $sql_where . " ORDER BY fecha_registro DESC LIMIT ? OFFSET ?";
                    $stmt = $link->prepare($sql);
                    $params[] = $items_per_page;
                    $params[] = $offset;
                    $types .= "ii";
                    $stmt->bind_param($types, ...$params);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['nombre_archivo']); ?></td>
                                <td><?php echo htmlspecialchars($row['categoria'] ?? 'N/A'); ?></td>
                                <td><?php echo round($row['size_bytes'] / 1024, 2); ?> KB</td>
                                <td><?php echo htmlspecialchars($row['mime_type']); ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($row['fecha_registro'])); ?></td>
                                <td>
                                    <button type="button" class="btn btn-<?php echo ($row['visible'] == 'SI' ? 'success' : 'secondary'); ?> btn-sm toggle-visibility"
                                            data-id="<?php echo $row['id']; ?>" data-table="downloads_documentos" data-current-visibility="<?php echo $row['visible']; ?>">
                                        <?php echo ($row['visible'] == 'SI' ? 'SI' : 'NO'); ?>
                                    </button>
                                </td>
                                <td>
                                    <a href="<?php echo basename(__FILE__); ?>?action=view_file&id=<?php echo $row['id']; ?>&table=documentos" target="_blank" class="btn btn-info btn-sm"><i class="fas fa-eye"></i> Ver</a>
                                    <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="#documentModal"
                                            data-id="<?php echo $row['id']; ?>"
                                            data-nombre="<?php echo htmlspecialchars($row['nombre_archivo']); ?>"
                                            data-size="<?php echo round($row['size_bytes'] / 1024, 2); ?> KB"
                                            data-fechasubida="<?php echo date('Y-m-d H:i', strtotime($row['fecha_registro'])); ?>"
                                            data-categoria="<?php echo htmlspecialchars($row['categoria'] ?? 'N/A'); ?>"
                                            data-comentario="<?php echo htmlspecialchars($row['comentario'] ?? 'Sin comentario'); ?>">
                                        Detalle
                                    </button>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo "<tr><td colspan=\"7\" class=\"text-center\">No hay documentos que coincidan con los criterios.</td></tr>";
                    }
                    $stmt->close();
                    ?>
                </tbody>
            </table>

            <?php
            // Paginación
            $sql_count = "SELECT COUNT(*) FROM downloads_documentos " . $sql_where;
            $stmt_count = $link->prepare($sql_count);
            $count_params = $params;
            array_pop($count_params);
            array_pop($count_params);
            $count_types = substr($types, 0, -2);
            if (!empty($count_params)) {
                $stmt_count->bind_param($count_types, ...$count_params);
            }
            $stmt_count->execute();
            $stmt_count->bind_result($total_items);
            $stmt_count->fetch();
            $stmt_count->close();

            $total_pages = ceil($total_items / $items_per_page);

            if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo basename(__FILE__); ?>?module=documentos&page=<?php echo $current_page - 1; ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo ($current_page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo basename(__FILE__); ?>?module=documentos&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo basename(__FILE__); ?>?module=documentos&page=<?php echo $current_page + 1; ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>

            <!-- Document Detail Modal -->
            <div class="modal fade" id="documentModal" tabindex="-1" aria-labelledby="documentModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="documentModalLabel">Detalle de Documento</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p><strong>Archivo:</strong> <span id="modalDocFileName"></span></p>
                            <p><strong>Categoría:</strong> <span id="modalDocCategory"></span></p>
                            <p><strong>Tamaño:</strong> <span id="modalDocFileSize"></span></p>
                            <p><strong>Fecha de Subida:</strong> <span id="modalDocUploadDate"></span></p>
                            <p><strong>Comentario:</strong> <span id="modalDocComment"></span></p>
                            <p><strong>Visible:</strong> <span id="modalDocVisibility"></span></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php
        }
    }
    // -----------------------------------------------------------
    // Acción de ver archivo en base de datos (?action=view_file)
    // -----------------------------------------------------------
    elseif (isset($_GET['action']) && $_GET['action'] === 'view_file' && isset($_GET['id']) && isset($_GET['table'])) {
        $id = (int)$_GET['id'];
        $table_name = '';

        if ($_GET['table'] === 'galeria') {
            $table_name = 'downloads_galeria';
        } elseif ($_GET['table'] === 'documentos') {
            $table_name = 'downloads_documentos';
             if (!(isset($_SESSION['authenticated']) && $_SESSION['authenticated'])) {
                // Si no está autenticado, redirigir a la autenticación de documentos
                header("Location: " . basename(__FILE__) . "?module=documentos");
                exit();
            }
        } else {
            die("Tabla no válida.");
        }

        $stmt = $link->prepare("SELECT mime_type, contenido, nombre_archivo FROM {$table_name} WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->bind_result($mime_type, $contenido, $nombre_archivo);
        $stmt->fetch();

        if ($contenido) {
            header("Content-Type: " . $mime_type);
            header("Content-Disposition: inline; filename=\"" . $nombre_archivo . "\"");
            echo $contenido;
        } else {
            echo "Archivo no encontrado.";
        }
        $stmt->close();
        exit(); // Terminar la ejecución para servir el archivo
    }
    // -----------------------------------------------------------
    // Acción de cambiar visibilidad (?action=toggle_visibility)
    // -----------------------------------------------------------
    elseif (isset($_POST['action']) && $_POST['action'] === 'toggle_visibility' && isset($_SESSION['authenticated']) && $_SESSION['authenticated']) {
        $id = (int)$_POST['id'];
        $table = $_POST['table'];
        $current_visibility = $_POST['current_visibility'];

        $new_visibility = ($current_visibility === 'SI') ? 'NO' : 'SI';

        $stmt = $link->prepare("UPDATE {$table} SET visible = ? WHERE id = ?");
        $stmt->bind_param("si", $new_visibility, $id);
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "new_visibility" => $new_visibility]);
        } else {
            echo json_encode(["success" => false, "error" => $stmt->error]);
        }
        $stmt->close();
        exit();
    }
    ?>

    </div> <!-- /container-fluid -->

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Confirmar Borrado</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" action="<?php echo basename(__FILE__); ?>?module=agregar">
                    <div class="modal-body">
                        <p>¿Está usted seguro que desea borrar el archivo <strong id="filenameToDelete"></strong>?</p>
                        <div class="form-group">
                            <label for="deletePassword">Ingrese la contraseña para confirmar:</label>
                            <input type="password" class="form-control" id="deletePassword" name="password_confirm" required>
                        </div>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="filename" id="modalDeleteFilename">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">Borrar Archivo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Fixed Footer -->
    <footer class="footer">
        <div class="container">
            <span class="text-muted">© 2026 Meta AI (Modelo de Lenguaje de Gran Escala) - Licencia MIT.</span>
        </div>
    </footer>

    <!-- Bootstrap 4.6.x JS, jQuery and Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js" integrity="sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.min.js" integrity="sha384-7CYPRURXmQ4+Xgny/vP4FhNxWCV+d7z/sLhC+G6f9v4Gg1kF3V4Z7S4g0K3z0m1" crossorigin="anonymous"></script>

    <script>
        $(document).ready(function() {
            // Manejar el modal de borrado
            $('#deleteModal').on('show.bs.modal', function (event) {
                var button = $(event.relatedTarget); // Botón que disparó el modal
                var filename = button.data('filename'); // Extrae info de los atributos data-*
                var modal = $(this);
                modal.find('#filenameToDelete').text(filename);
                modal.find('#modalDeleteFilename').val(filename);
            });

            // Manejar el modal de imagen de la galería
            $('#imageModal').on('show.bs.modal', function (event) {
                var button = $(event.relatedTarget);
                var id = button.data('id');
                var nombre = button.data('nombre');
                var size = button.data('size');
                var fechasubida = button.data('fechasubida');
                var categoria = button.data('categoria');
                var comentario = button.data('comentario');
                var visible = button.data('visible');

                var modal = $(this);
                modal.find('#modalImage').attr('src', '<?php echo basename(__FILE__); ?>?action=view_file&id=' + id + '&table=galeria');
                modal.find('#modalFileName').text(nombre);
                modal.find('#modalFileSize').text(size);
                modal.find('#modalUploadDate').text(fechasubida);
                modal.find('#modalCategory').text(categoria);
                modal.find('#modalComment').text(comentario);
                modal.find('#modalVisibility').text(visible);
            });

            // Manejar el modal de documento
            $('#documentModal').on('show.bs.modal', function (event) {
                var button = $(event.relatedTarget);
                var id = button.data('id');
                var nombre = button.data('nombre');
                var size = button.data('size');
                var fechasubida = button.data('fechasubida');
                var categoria = button.data('categoria');
                var comentario = button.data('comentario');

                var modal = $(this);
                modal.find('#modalDocFileName').text(nombre);
                modal.find('#modalDocFileSize').text(size);
                modal.find('#modalDocUploadDate').text(fechasubida);
                modal.find('#modalDocCategory').text(categoria);
                modal.find('#modalDocComment').text(comentario);

                // Aquí puedes agregar la lógica para mostrar el estado de visibilidad si es necesario
                // modal.find('#modalDocVisibility').text(visible);
            });

            // Lógica para cambiar la visibilidad SI/NO (solo si está autenticado)
            $('.toggle-visibility').on('click', function() {
                var button = $(this);
                var id = button.data('id');
                var table = button.data('table');
                var currentVisibility = button.data('current-visibility');

                $.ajax({
                    url: '<?php echo basename(__FILE__); ?>',
                    type: 'POST',
                    data: {
                        action: 'toggle_visibility',
                        id: id,
                        table: table,
                        current_visibility: currentVisibility
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            var newVisibility = response.new_visibility;
                            button.data('current-visibility', newVisibility);
                            button.text(newVisibility);
                            if (newVisibility === 'SI') {
                                button.removeClass('btn-secondary').addClass('btn-success');
                            } else {
                                button.removeClass('btn-success').addClass('btn-secondary');
                            }
                            // Si estamos en la galería, podríamos necesitar recargar para aplicar el filtro visible='SI'
                            // o actualizar el DOM para ocultar/mostrar elementos.
                            // Por simplicidad, una recarga simple o actualización del texto es suficiente aquí.
                        } else {
                            alert('Error al cambiar la visibilidad: ' + response.error);
                        }
                    },
                    error: function(xhr, status, error) {
                        alert('Error de AJAX: ' + error);
                    }
                });
            });
        });
    </script>
</body>
</html>
<?php
$link->close();
?>
