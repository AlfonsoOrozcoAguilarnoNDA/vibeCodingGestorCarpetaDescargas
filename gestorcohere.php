<?php
// GESTOR DE CARPETA DE DOWNLOADS (ESPECIFICACIÓN V4)
// https://vibecodingmexico.com/gestor-de-carpeta-descargas/
// Fecha: 25 de marzo de 2026
// Licencia: MIT
// Autor Alfonso Orozco Aguilar
// Coautor: Command (Modelo de Lenguaje) Cohere
// Versión: 1.0

// No Cache Headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// Configuración
$password_hardcoded = 'tu_contraseña_segura';
$max_file_size = 3 * 1024 * 1024; // 3MB
$downloads_dir = './downloads/';

// Verificar y crear tablas si no existen
function crearTablas() {
    global $link;
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    mysqli_query($link, $sql_galeria);
    mysqli_query($link, $sql_documentos);
}

// Verificar permisos de escritura
function verificarPermisos() {
    if (!is_writable($downloads_dir)) {
        echo "<div class='alert alert-danger'>No se puede escribir en la carpeta downloads.</div>";
        return false;
    }
    return true;
}

// Escanear archivos en la carpeta downloads
function escanearArchivos() {
    $archivos = scandir($downloads_dir);
    $resultados = [];
    $script_name = basename(__FILE__);

    foreach ($archivos as $archivo) {
        if ($archivo === '.' || $archivo === '..' || $archivo === 'index.php' || $archivo === $script_name) {
            continue;
        }

        $ruta_completa = $downloads_dir . $archivo;
        $size = filesize($ruta_completa);
        $sha1 = sha1_file($ruta_completa);

        $existe = verificarExistenciaSHA1($sha1);
        $color = $existe ? ($size > $max_file_size ? 'red' : 'yellow') : 'green';

        $resultados[] = [
            'nombre' => $archivo,
            'size' => $size,
            'sha1' => $sha1,
            'color' => $color,
            'existe' => $existe
        ];
    }

    return $resultados;
}

// Verificar existencia de SHA1 en la base de datos
function verificarExistenciaSHA1($sha1) {
    global $link;
    $sql = "SELECT * FROM downloads_galeria WHERE sha1 = ? UNION SELECT * FROM downloads_documentos WHERE sha1 = ?";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $sha1, $sha1);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_num_rows($result) > 0;
}

// Insertar archivo en la base de datos
function insertarArchivo($nombre, $ruta, $comentario, $categoria, $tipo) {
    global $link;
    $contenido = file_get_contents($ruta);
    $size = filesize($ruta);
    $sha1 = sha1($contenido);
    $mime = mime_content_type($ruta);
    $fecha_archivo = date("Y-m-d H:i:s", filemtime($ruta));
    $fecha_registro = date("Y-m-d H:i:s");

    $tabla = ($tipo === 'Imagen') ? 'downloads_galeria' : 'downloads_documentos';
    $sql = "INSERT INTO $tabla (nombre_archivo, mime_type, contenido, sha1, size_bytes, comentario, categoria, tipo_archivo, fecha_archivo, fecha_registro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, "sssissiiss", $nombre, $mime, $contenido, $sha1, $size, $comentario, $categoria, $tipo, $fecha_archivo, $fecha_registro);
    
    if (mysqli_stmt_execute($stmt)) {
        if (sha1_file($ruta) === $sha1) {
            unlink($ruta);
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// Eliminar archivo de la base de datos
function eliminarArchivo($sha1) {
    global $link;
    if ($_POST['password'] !== $password_hardcoded) {
        echo "<div class='alert alert-danger'>Contraseña incorrecta. Operación cancelada.</div>";
        return;
    }

    $sql = "DELETE FROM downloads_galeria WHERE sha1 = ? OR DELETE FROM downloads_documentos WHERE sha1 = ?";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, "s", $sha1);
    mysqli_stmt_execute($stmt);
}

// Navbar
function navbar() {
    echo "<nav class='navbar navbar-expand-lg navbar-dark bg-dark fixed-top'>
            <a class='navbar-brand' href='#'>Gestor de Descargas</a>
            <button class='navbar-toggler' type='button' data-toggle='collapse' data-target='#navbarNav' aria-controls='navbarNav' aria-expanded='false' aria-label='Toggle navigation'>
                <span class='navbar-toggler-icon'></span>
            </button>
            <div class='collapse navbar-collapse' id='navbarNav'>
                <ul class='navbar-nav mr-auto'>
                    <li class='nav-item'><a class='nav-link' href='https://google.com' target='_blank'>Google</a></li>
                    <li class='nav-item dropdown'>
                        <a class='nav-link dropdown-toggle' href='#' id='navbarDropdown' role='button' data-toggle='dropdown' aria-haspopup='true' aria-expanded='false'>Opciones</a>
                        <div class='dropdown-menu' aria-labelledby='navbarDropdown'>
                            <a class='dropdown-item' href='#'>Opción 1</a>
                            <a class='dropdown-item' href='#'>Opción 2</a>
                            <a class='dropdown-item' href='#'>Opción 3</a>
                        </div>
                    </li>
                </ul>
                <ul class='navbar-nav'>
                    <li class='nav-item'><a class='nav-link' href='?module=galeria'>Galería</a></li>
                    <li class='nav-item'><a class='nav-link' href='?module=agregar'>Agregar</a></li>
                    <li class='nav-item'><a class='nav-link' href='?module=manage'>Administrar</a></li>
                </ul>
            </div>
        </nav>";
}

// Footer
function footer() {
    echo "<footer class='footer fixed-bottom bg-dark text-white text-center py-2'>
            <p>© 2026 Gestor de Descargas | Desarrollado por Command (Modelo de Lenguaje)</p>
        </footer>";
}

// Modal de confirmación
function modalConfirmacion() {
    echo "<div class='modal fade' id='confirmModal' tabindex='-1' role='dialog' aria-labelledby='confirmModalLabel' aria-hidden='true'>
            <div class='modal-dialog' role='document'>
                <div class='modal-content'>
                    <div class='modal-header'>
                        <h5 class='modal-title' id='confirmModalLabel'>Confirmación</h5>
                        <button type='button' class='close' data-dismiss='modal' aria-label='Close'>
                            <span aria-hidden='true'>&times;</span>
                        </button>
                    </div>
                    <div class='modal-body'>
                        ¿Está usted seguro?
                        <form method='post'>
                            <input type='hidden' name='sha1' id='sha1' value=''>
                            <div class='form-group'>
                                <label for='password'>Contraseña:</label>
                                <input type='password' class='form-control' id='password' name='password' required>
                            </div>
                    </div>
                    <div class='modal-footer'>
                        <button type='button' class='btn btn-secondary' data-dismiss='modal'>Cancelar</button>
                        <button type='submit' class='btn btn-danger' name='eliminar'>Eliminar</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>";
}

// Modal de imágenes
function modalImagen() {
    echo "<div class='modal fade' id='imageModal' tabindex='-1' role='dialog' aria-labelledby='imageModalLabel' aria-hidden='true'>
            <div class='modal-dialog modal-lg' role='document'>
                <div class='modal-content'>
                    <div class='modal-header'>
                        <h5 class='modal-title' id='imageModalLabel'>Detalles de la Imagen</h5>
                        <button type='button' class='close' data-dismiss='modal' aria-label='Close'>
                            <span aria-hidden='true'>&times;</span>
                        </button>
                    </div>
                    <div class='modal-body'>
                        <img id='modalImage' src='' class='img-fluid' alt='Imagen'>
                        <p><strong>Categoría:</strong> <span id='modalCategoria'></span></p>
                        <p><strong>Archivo:</strong> <span id='modalArchivo'></span></p>
                        <p><strong>Tamaño:</strong> <span id='modalTamanio'></span></p>
                        <p><strong>Fecha de Subida:</strong> <span id='modalFecha'></span></p>
                        <p><strong>Comentario:</strong> <span id='modalComentario'></span></p>
                    </div>
                </div>
            </div>
        </div>";
}

// Inicio del script
require 'config.php';
crearTablas();

if (!verificarPermisos()) {
    exit();
}

$module = isset($_GET['module']) ? $_GET['module'] : 'galeria';

navbar();

switch ($module) {
    case 'agregar':
        if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
            echo "<div class='container mt-5'>
                    <div class='alert alert-danger'>Acceso denegado. Ingrese la contraseña.</div>
                    <form method='post'>
                        <div class='form-group'>
                            <label for='password'>Contraseña:</label>
                            <input type='password' class='form-control' id='password' name='password' required>
                        </div>
                        <button type='submit' class='btn btn-primary' name='autenticar'>Autenticar</button>
                    </form>
                  </div>";
            if (isset($_POST['autenticar']) && $_POST['password'] === $password_hardcoded) {
                $_SESSION['autenticado'] = true;
                header("Location: " . $_SERVER['PHP_SELF'] . "?module=agregar");
                exit();
            }
            break;
        }

        $archivos = escanearArchivos();
        echo "<div class='container mt-5'>
                <h2>Agregar Archivos</h2>
                <div class='row'>";
        foreach ($archivos as $archivo) {
            echo "<div class='col-md-4 mb-4'>
                    <div class='card'>
                        <div class='card-body'>
                            <h5 class='card-title'>{$archivo['nombre']}</h5>
                            <p class='card-text'>Tamaño: " . number_format($archivo['size'] / 1024, 2) . " KB</p>";
            if ($archivo['color'] === 'green') {
                echo "<form method='post'>
                        <div class='form-group'>
                            <label for='comentario'>Comentario:</label>
                            <textarea class='form-control' id='comentario' name='comentario' required></textarea>
                        </div>
                        <div class='form-group'>
                            <label for='categoria'>Categoría:</label>
                            <input type='text' class='form-control' id='categoria' name='categoria' required>
                        </div>
                        <div class='form-check'>
                            <input type='checkbox' class='form-check-input' id='confirmar' name='confirmar' required>
                            <label class='form-check-label' for='confirmar'>Estoy seguro</label>
                        </div>
                        <input type='hidden' name='nombre' value='{$archivo['nombre']}'>
                        <input type='hidden' name='ruta' value='{$downloads_dir}{$archivo['nombre']}'>
                        <button type='submit' class='btn btn-{$archivo['color']}' name='subir'>Subir</button>
                      </form>";
            } else {
                echo "<a href='{$downloads_dir}{$archivo['nombre']}' class='btn btn-{$archivo['color']}' target='_blank'>Ver</a>
                      <button type='button' class='btn btn-danger' data-toggle='modal' data-target='#confirmModal' data-sha1='{$archivo['sha1']}'>Borrar</button>";
            }
            echo "</div></div></div>";
        }
        echo "</div></div>";

        if (isset($_POST['subir']) && $_POST['confirmar'] && $archivo['color'] === 'green') {
            insertarArchivo($_POST['nombre'], $_POST['ruta'], $_POST['comentario'], $_POST['categoria'], 'Imagen');
        }

        if (isset($_POST['eliminar'])) {
            eliminarArchivo($_POST['sha1']);
        }
        break;

    case 'galeria':
        $filtro = isset($_POST['categoria']) ? $_POST['categoria'] : '';
        $sql = "SELECT * FROM downloads_galeria WHERE visible = 'SI' AND tipo_archivo = 'Imagen'";
        if (!empty($filtro)) {
            $sql .= " AND categoria = ?";
        }
        $sql .= " ORDER BY fecha_registro DESC LIMIT 16";

        $stmt = mysqli_prepare($link, $sql);
        if (!empty($filtro)) {
            mysqli_stmt_bind_param($stmt, "s", $filtro);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        echo "<div class='container mt-5'>
                <h2>Galería</h2>
                <form method='post' class='mb-4'>
                    <div class='form-group'>
                        <label for='categoria'>Filtrar por Categoría:</label>
                        <input type='text' class='form-control' id='categoria' name='categoria' value='$filtro'>
                    </div>
                    <button type='submit' class='btn btn-primary'>Filtrar</button>
                </form>
                <div class='row'>";
        while ($row = mysqli_fetch_assoc($result)) {
            echo "<div class='col-md-3 mb-4'>
                    <div class='card'>
                        <img src='data:{$row['mime_type']};base64," . base64_encode($row['contenido']) . "' class='card-img-top' alt='{$row['nombre_archivo']}' data-toggle='modal' data-target='#imageModal' data-id='{$row['id']}'>
                        <div class='card-body'>
                            <h5 class='card-title'>{$row['nombre_archivo']}</h5>
                        </div>
                    </div>
                  </div>";
        }
        echo "</div></div>";
        break;

    case 'manage':
        if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
            echo "<div class='container mt-5'>
                    <div class='alert alert-danger'>Acceso denegado. Ingrese la contraseña.</div>
                    <form method='post'>
                        <div class='form-group'>
                            <label for='password'>Contraseña:</label>
                            <input type='password' class='form-control' id='password' name='password' required>
                        </div>
                        <button type='submit' class='btn btn-primary' name='autenticar'>Autenticar</button>
                    </form>
                  </div>";
            if (isset($_POST['autenticar']) && $_POST['password'] === $password_hardcoded) {
                $_SESSION['autenticado'] = true;
                header("Location: " . $_SERVER['PHP_SELF'] . "?module=manage");
                exit();
            }
            break;
        }

        $sql = "SELECT * FROM downloads_documentos ORDER BY fecha_registro DESC";
        $result = mysqli_query($link, $sql);

        echo "<div class='container mt-5'>
                <h2>Administrar Documentos</h2>
                <table class='table table-striped'>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Tamaño</th>
                            <th>Categoría</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>";
        while ($row = mysqli_fetch_assoc($result)) {
            echo "<tr>
                    <td>{$row['nombre_archivo']}</td>
                    <td>" . number_format($row['size_bytes'] / 1024, 2) . " KB</td>
                    <td>{$row['categoria']}</td>
                    <td>{$row['fecha_registro']}</td>
                    <td>
                        <a href='data:{$row['mime_type']};base64," . base64_encode($row['contenido']) . "' class='btn btn-primary' target='_blank'>Ver</a>
                        <button type='button' class='btn btn-danger' data-toggle='modal' data-target='#confirmModal' data-sha1='{$row['sha1']}'>Borrar</button>
                    </td>
                  </tr>";
        }
        echo "</tbody></table></div>";
        break;
}

modalConfirmacion();
modalImagen();
footer();

// Bootstrap y Font Awesome vía jsDelivr
echo "<link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css'>
      <script src='https://code.jquery.com/jquery-3.5.1.slim.min.js'></script>
      <script src='https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js'></script>
      <script src='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/js/all.min.js'></script>
      <script>
        $(document).ready(function() {
            $('.card-img-top').click(function() {
                var id = $(this).data('id');
                $.ajax({
                    url: 'obtener_detalles.php',
                    type: 'POST',
                    data: {id: id},
                    success: function(response) {
                        var data = JSON.parse(response);
                        $('#modalImage').attr('src', 'data:' + data.mime_type + ';base64,' + data.contenido);
                        $('#modalCategoria').text(data.categoria || 'N/A');
                        $('#modalArchivo').text(data.nombre_archivo);
                        $('#modalTamanio').text(data.size_bytes / 1024 + ' KB');
                        $('#modalFecha').text(data.fecha_registro);
                        $('#modalComentario').text(data.comentario || 'N/A');
                    }
                });
            });

            $('#confirmModal').on('show.bs.modal', function(event) {
                var button = $(event.relatedTarget);
                var sha1 = button.data('sha1');
                var modal = $(this);
                modal.find('#sha1').val(sha1);
            });
        });
      </script>";

// Archivo obtener_detalles.php (debe estar en el mismo directorio)
/*
<?php
require 'config.php';
$id = $_POST['id'];
$sql = "SELECT * FROM downloads_galeria WHERE id = ?";
$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);
echo json_encode($row);
?>
*/
?>
