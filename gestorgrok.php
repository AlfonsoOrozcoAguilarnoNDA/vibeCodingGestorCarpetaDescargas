<?php
/**
 * GESTOR DE CARPETA DE DOWNLOADS - v4
 * 
 * Licencia: MIT
 * Fecha: 25 de marzo de 2026
 * Autor principal: Alfonso Orozco Aguilar
 * Coautor: Grok 3 (xAI) - modelo de lenguaje de asistencia en generación de código
 * https://vibecodingmexico.com/gestor-de-carpeta-descargas/
 *
 * Archivo único de gestión de migración de archivos → base de datos
 * PHP 8.x procedural • MariaDB/InnoDB • Bootstrap 4.6 • Font Awesome 5
 */

header('Content-Type: text/html; charset=UTF-8');
header('Expires: Thu, 19 Nov 1981 08:52:00 GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// ================================================
// CONFIGURACIÓN PRINCIPAL
// ================================================

$PASSWORD_HARD = 'cambiaESTAclave2026_!!';     // ¡CAMBIAR OBLIGATORIAMENTE!
$DIR_DOWNLOADS = './downloads';
$MAX_SIZE_BYTES = 3 * 1024 * 1024;              // 3 MB
$ITEMS_POR_PAGINA = 16;

$self = basename(__FILE__);

// ================================================
// INICIO DE SESIÓN Y AUTENTICACIÓN
// ================================================

session_start();

$is_logged = isset($_SESSION['downloads_auth']) && $_SESSION['downloads_auth'] === true;

if (isset($_POST['password']) && $_POST['password'] === $PASSWORD_HARD) {
    $_SESSION['downloads_auth'] = true;
    $is_logged = true;
    header("Location: $self");
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: $self");
    exit;
}

// ================================================
// CONEXIÓN (se espera config.php)
// ================================================

if (!file_exists('config.php')) {
    die('<div class="alert alert-danger">Falta config.php con $link (mysqli)</div>');
}

require_once 'config.php';
if (!isset($link) || !$link instanceof mysqli) {
    die('<div class="alert alert-danger">No hay conexión mysqli válida en $link</div>');
}

mysqli_set_charset($link, 'utf8mb4');

// ================================================
// CREACIÓN / VERIFICACIÓN DE TABLAS
// ================================================

$tablas_sql = [
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
        INDEX(`sha1`),
        INDEX(`categoria`),
        INDEX(`visible`),
        INDEX(`fecha_registro`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

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
        INDEX(`sha1`),
        INDEX(`categoria`),
        INDEX(`visible`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
];

foreach ($tablas_sql as $sql) {
    if (!$link->query($sql)) {
        echo '<div class="alert alert-warning">Error al verificar/crear tabla: ' . $link->error . '</div>';
    }
}

// ================================================
// VERIFICACIÓN DE CARPETA downloads
// ================================================

$dir_ok = is_dir($DIR_DOWNLOADS) && is_writable($DIR_DOWNLOADS);

if (!$dir_ok) {
    $alerta_dir = '<div class="alert alert-danger mb-4">La carpeta <code>downloads</code> no existe o no tiene permisos de escritura.</div>';
}

// ================================================
// ACCIONES POST (protegidas cuando corresponda)
// ================================================

$msg = '';
$msg_type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_logged) {

    // ── Eliminar archivo físico + registro DB ───────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id']) && isset($_POST['tipo'])) {
        $id   = (int)$_POST['id'];
        $tipo = $_POST['tipo'] === 'galeria' ? 'galeria' : 'documentos';
        $tabla = $tipo === 'galeria' ? 'downloads_galeria' : 'downloads_documentos';

        $r = $link->query("SELECT nombre_archivo FROM `$tabla` WHERE id = $id LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) {
            $nombre = $row['nombre_archivo'];
            $link->query("DELETE FROM `$tabla` WHERE id = $id");
            if ($link->affected_rows > 0) {
                $msg = "Registro <strong>$nombre</strong> eliminado de la base de datos.";
                $msg_type = 'success';
            }
        }
    }

    // ── Cambiar visibilidad ──────────────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_visible' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $link->query("UPDATE downloads_galeria SET visible = IF(visible='SI','NO','SI') WHERE id = $id");
        if ($link->affected_rows > 0) {
            $msg = "Visibilidad actualizada.";
            $msg_type = 'success';
        }
    }

    // ── Subir archivo verde (confirmado) ─────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'upload' && isset($_POST['filename'])) {
        $filename = basename($_POST['filename']);
        $ruta = $DIR_DOWNLOADS . '/' . $filename;

        if (!file_exists($ruta) || !is_readable($ruta)) {
            $msg = "Archivo ya no existe: $filename";
            $msg_type = 'danger';
        } else {
            $size = filesize($ruta);
            $sha1_file = sha1_file($ruta);
            $mtime = date('Y-m-d H:i:s', filemtime($ruta));

            // Verificar duplicado otra vez (por si acaso)
            $existe = $link->query("SELECT id FROM downloads_galeria WHERE sha1 = '$sha1_file' LIMIT 1")
                        ->num_rows > 0 ||
                      $link->query("SELECT id FROM downloads_documentos WHERE sha1 = '$sha1_file' LIMIT 1")
                        ->num_rows > 0;

            if ($existe) {
                $msg = "Duplicado detectado (sha1). No se subió $filename";
                $msg_type = 'warning';
            } elseif ($size > $MAX_SIZE_BYTES) {
                $msg = "Excede límite de tamaño: $filename";
                $msg_type = 'danger';
            } else {
                $comentario = trim($_POST['comentario'] ?? '');
                $categoria  = trim($_POST['categoria'] ?? '');
                $mime = mime_content_type($ruta) ?: 'application/octet-stream';

                $es_imagen = stripos($mime, 'image/') === 0;
                $tabla = $es_imagen ? 'downloads_galeria' : 'downloads_documentos';

                $contenido = file_get_contents($ruta);
                $sha1_db = sha1($contenido);

                $stmt = $link->prepare("INSERT INTO `$tabla` 
                    (nombre_archivo, mime_type, contenido, sha1, size_bytes, comentario, categoria, fecha_archivo, fecha_registro)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

                $stmt->bind_param("ssssisss", $filename, $mime, $contenido, $sha1_db, $size, $comentario, $categoria, $mtime);

                if ($stmt->execute()) {
                    if (sha1_file($ruta) === $sha1_db) {
                        @unlink($ruta);
                        $msg = "Archivo <strong>$filename</strong> migrado correctamente y eliminado del disco.";
                        $msg_type = 'success';
                    } else {
                        $msg = "Inserción OK pero SHA1 no coincide después de guardar. Archivo NO eliminado.";
                        $msg_type = 'warning';
                    }
                } else {
                    $msg = "Error al guardar en base de datos: " . $link->error;
                    $msg_type = 'danger';
                }
                $stmt->close();
            }
        }
    }
}

// ================================================
// LÓGICA PRINCIPAL – LISTADO DE ARCHIVOS LOCALES
// ================================================

$archivos = [];
$alerta_dir = $alerta_dir ?? '';

if ($dir_ok) {
    $items = scandir($DIR_DOWNLOADS);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === 'index.php' || $item === $self) {
            continue;
        }
        $ruta = $DIR_DOWNLOADS . '/' . $item;
        if (!is_file($ruta)) continue;

        $size = filesize($ruta);
        $sha1 = sha1_file($ruta);
        $mime = mime_content_type($ruta) ?: 'application/octet-stream';

        $existe_db = $link->query("SELECT id FROM downloads_galeria WHERE sha1 = '$sha1' LIMIT 1")->num_rows > 0 ||
                     $link->query("SELECT id FROM downloads_documentos WHERE sha1 = '$sha1' LIMIT 1")->num_rows > 0;

        $estado = 'red';
        if ($size <= $MAX_SIZE_BYTES) {
            $estado = $existe_db ? 'yellow' : 'green';
        }

        $archivos[] = [
            'nombre'  => $item,
            'size'    => $size,
            'sha1'    => $sha1,
            'mime'    => $mime,
            'estado'  => $estado,
        ];
    }
}

// ================================================
// PAGINACIÓN GALERÍA (solo imágenes visibles)
// ================================================

$pagina = max(1, (int)($_GET['p'] ?? 1));
$offset = ($pagina - 1) * $ITEMS_POR_PAGINA;

$categoria_filtro = trim($_POST['categoria_filtro'] ?? $_GET['cat'] ?? '');
$where = "WHERE visible = 'SI' AND tipo_archivo = 'Imagen'";
if ($categoria_filtro !== '') {
    $cat_esc = $link->real_escape_string($categoria_filtro);
    $where .= " AND categoria = '$cat_esc'";
}

$total_galeria = $link->query("SELECT COUNT(*) FROM downloads_galeria $where")->fetch_row()[0];
$paginas_total = max(1, ceil($total_galeria / $ITEMS_POR_PAGINA));

$sql_galeria = "SELECT id, nombre_archivo, mime_type, size_bytes, categoria, comentario, fecha_registro 
                FROM downloads_galeria $where 
                ORDER BY fecha_registro DESC 
                LIMIT $offset, $ITEMS_POR_PAGINA";

$res_galeria = $link->query($sql_galeria);

// ================================================
// LISTADO DOCUMENTOS (modo manage)
// ================================================

$documentos = [];
if ($is_logged && isset($_GET['module']) && $_GET['module'] === 'manage') {
    $res_doc = $link->query("SELECT id, nombre_archivo, mime_type, size_bytes, categoria, fecha_registro 
                             FROM downloads_documentos 
                             ORDER BY fecha_registro DESC LIMIT 200");
    while ($row = $res_doc->fetch_assoc()) {
        $documentos[] = $row;
    }
}

// ================================================
// HTML – INICIO
// ================================================
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<title>Gestor de Descargas → Base de Datos</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N" crossorigin="anonymous">
<link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css" rel="stylesheet">
<style>
    body { padding-top: 70px; padding-bottom: 70px; }
    .file-green  { background:#e8f5e9; }
    .file-yellow { background:#fff3cd; }
    .file-red    { background:#ffebee; }
    .thumb { max-height:180px; object-fit:contain; width:100%; }
    footer { position:fixed; bottom:0; width:100%; z-index:900; }
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-md navbar-dark bg-dark fixed-top">
    <a class="navbar-brand" href="#">Grok 3 + Alfonso</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navMain">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item"><a class="nav-link" href="https://google.com" target="_blank">Google</a></li>
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle" href="#" id="dropdownGen" data-toggle="dropdown">Opciones genéricas</a>
                <div class="dropdown-menu">
                    <a class="dropdown-item" href="#">Opción 1</a>
                    <a class="dropdown-item" href="#">Opción 2</a>
                    <a class="dropdown-item" href="#">Opción 3</a>
                </div>
            </li>
        </ul>
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" href="?">Galería</a></li>
            <?php if ($is_logged): ?>
            <li class="nav-item"><a class="nav-link" href="?module=agregar">Agregar</a></li>
            <li class="nav-item"><a class="nav-link" href="?module=manage">Documentos</a></li>
            <li class="nav-item"><a class="nav-link text-warning" href="?logout=1">Salir</a></li>
            <?php else: ?>
            <li class="nav-item"><a class="nav-link text-info" href="#" data-toggle="modal" data-target="#loginModal">Iniciar sesión</a></li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<!-- MODAL LOGIN -->
<div class="modal fade" id="loginModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="post" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Acceso administrativo</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="password" name="password" class="form-control" placeholder="Contraseña..." required autofocus>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Entrar</button>
            </div>
        </form>
    </div>
</div>

<div class="container">

<?php if (!$dir_ok) echo $alerta_dir; ?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg_type ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($msg) ?>
    <button type="button" class="close" data-dismiss="alert">&times;</button>
</div>
<?php endif; ?>

<?php
// ================================================
// VISTAS PRINCIPALES
// ================================================

$module = $_GET['module'] ?? '';

if ($module === 'agregar' && $is_logged):
// ── MODO AGREGAR / AUDITORÍA ────────────────────────────────────────
?>

<h2 class="mb-4">Archivos pendientes de migrar</h2>

<?php if (empty($archivos)): ?>
<div class="alert alert-info">No hay archivos en <code>downloads/</code> (o todos ya están migrados).</div>
<?php else: ?>

<div class="row">
<?php foreach ($archivos as $f): 
    $cls = 'file-' . $f['estado'];
    $disabled = $f['estado'] !== 'green';
?>
    <div class="col-md-6 col-lg-4 mb-4">
        <div class="card <?= $cls ?>">
            <div class="card-body">
                <h5 class="card-title text-truncate" title="<?= htmlspecialchars($f['nombre']) ?>">
                    <?= htmlspecialchars($f['nombre']) ?>
                </h5>
                <p class="small mb-1">
                    <?= number_format($f['size']/1024,1) ?> KB • <?= $f['mime'] ?>
                </p>

                <?php if ($f['estado'] === 'green'): ?>
                <form method="post">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="filename" value="<?= htmlspecialchars($f['nombre']) ?>">

                    <div class="form-group">
                        <label>Categoría</label>
                        <input type="text" name="categoria" class="form-control form-control-sm" maxlength="30">
                    </div>
                    <div class="form-group">
                        <label>Comentario</label>
                        <textarea name="comentario" rows="2" class="form-control form-control-sm"></textarea>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" name="confirm" class="form-check-input" required>
                        <label class="form-check-label">Estoy seguro de ingresar a BD</label>
                    </div>
                    <button type="submit" class="btn btn-success btn-sm">Migrar → BD</button>
                </form>

                <?php else: ?>
                <div class="mt-3">
                    <a href="<?= htmlspecialchars($DIR_DOWNLOADS.'/'.$f['nombre']) ?>" 
                       target="_blank" class="btn btn-info btn-sm">Abrir</a>

                    <?php if ($f['estado'] !== 'red'): ?>
                    <button type="button" class="btn btn-danger btn-sm" 
                            data-toggle="modal" data-target="#modalDelete<?= md5($f['sha1']) ?>">
                        Borrar BD
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Modal borrar (solo si existe en BD) -->
                <?php if ($f['estado'] === 'yellow'): 
                    $r = $link->query("SELECT id, 'galeria' AS tipo FROM downloads_galeria WHERE sha1 = '{$f['sha1']}' 
                                       UNION SELECT id, 'documentos' FROM downloads_documentos WHERE sha1 = '{$f['sha1']}' LIMIT 1");
                    if ($r && $row = $r->fetch_assoc()):
                ?>
                <div class="modal fade" id="modalDelete<?= md5($f['sha1']) ?>">
                    <div class="modal-dialog">
                        <form method="post" class="modal-content">
                            <div class="modal-header">
                                <h5>¿Está usted seguro?</h5>
                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                            </div>
                            <div class="modal-body">
                                Se eliminará el registro en base de datos<br>
                                <strong><?= htmlspecialchars($f['nombre']) ?></strong><br>
                                (no se toca el archivo físico)
                            </div>
                            <div class="modal-footer">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <input type="hidden" name="tipo" value="<?= $row['tipo'] ?>">
                                <button type="submit" class="btn btn-danger">Sí, borrar</button>
                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; endif; ?>

                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php endif; // archivos ?>

<?php
elseif ($module === 'manage' && $is_logged):
// ── LISTA DE DOCUMENTOS ─────────────────────────────────────────────
?>

<h2>Documentos en base de datos</h2>

<?php if (empty($documentos)): ?>
<div class="alert alert-info">No hay documentos registrados.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-hover">
<thead>
    <tr>
        <th>Archivo</th>
        <th>Tamaño</th>
        <th>Categoría</th>
        <th>Fecha</th>
    </tr>
</thead>
<tbody>
<?php foreach ($documentos as $d): ?>
    <tr>
        <td><?= htmlspecialchars($d['nombre_archivo']) ?></td>
        <td><?= number_format($d['size_bytes']/1024,1) ?> KB</td>
        <td><?= htmlspecialchars($d['categoria'] ?: '—') ?></td>
        <td><?= date('d/m/Y H:i', strtotime($d['fecha_registro'])) ?></td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php
else:
// ── GALERÍA PÚBLICA ─────────────────────────────────────────────────
?>

<h2>Galería de Imágenes
    <small class="text-muted">(<?= $total_galeria ?>)</small>
</h2>

<form method="post" class="mb-4">
    <div class="form-row align-items-center">
        <div class="col-auto">
            <label class="sr-only">Categoría</label>
            <select name="categoria_filtro" class="custom-select">
                <option value="">Todas las categorías</option>
                <?php
                $cats = $link->query("SELECT DISTINCT categoria FROM downloads_galeria WHERE categoria IS NOT NULL AND categoria != '' ORDER BY categoria");
                while ($c = $cats->fetch_row()) {
                    $sel = $c[0] === $categoria_filtro ? 'selected' : '';
                    echo "<option value=\"" . htmlspecialchars($c[0]) . "\" $sel>" . htmlspecialchars($c[0]) . "</option>";
                }
                ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary">Filtrar</button>
        </div>
    </div>
</form>

<?php if ($res_galeria->num_rows === 0): ?>
<div class="alert alert-info">No hay imágenes visibles en este momento<?= $categoria_filtro ? " en la categoría <strong>$categoria_filtro</strong>" : '' ?>.</div>
<?php else: ?>

<div class="row">
<?php while ($img = $res_galeria->fetch_assoc()): 
    $src = "data:" . $img['mime_type'] . ";base64," . base64_encode($link->query("SELECT contenido FROM downloads_galeria WHERE id={$img['id']}")->fetch_row()[0]);
?>
    <div class="col-6 col-md-4 col-lg-3 mb-4">
        <div class="card h-100 shadow-sm">
            <img src="<?= $src ?>" class="card-img-top thumb" alt="<?= htmlspecialchars($img['nombre_archivo']) ?>" 
                 data-toggle="modal" data-target="#modalImg<?= $img['id'] ?>" style="cursor:pointer;">
            <div class="card-body text-center p-2">
                <small class="text-muted">
                    <?= htmlspecialchars($img['categoria'] ?: '—') ?>
                </small>
                <?php if ($is_logged): ?>
                <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="toggle_visible">
                    <input type="hidden" name="id" value="<?= $img['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-link p-0 border-0 bg-transparent">
                        <i class="fas fa-eye<?= $img['visible']==='SI'?' text-success':' text-secondary' ?>"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal imagen -->
    <div class="modal fade" id="modalImg<?= $img['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= htmlspecialchars($img['nombre_archivo']) ?></h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body text-center">
                    <img src="<?= $src ?>" class="img-fluid mb-3" alt="vista completa" style="max-height:70vh;">
                    <table class="table table-sm table-borderless text-left">
                        <tr><th>Categoría</th><td><?= htmlspecialchars($img['categoria'] ?: 'N/A') ?></td></tr>
                        <tr><th>Archivo</th><td><?= htmlspecialchars($img['nombre_archivo']) ?></td></tr>
                        <tr><th>Tamaño</th><td><?= number_format($img['size_bytes']/1024,1) ?> KB</td></tr>
                        <tr><th>Subido</th><td><?= date('d/m/Y H:i', strtotime($img['fecha_registro'])) ?></td></tr>
                        <tr><th>Comentario</th><td><?= nl2br(htmlspecialchars($img['comentario'] ?: '—')) ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endwhile; ?>
</div>

<!-- Paginación simple -->
<?php if ($paginas_total > 1): ?>
<nav aria-label="Paginación galería">
    <ul class="pagination justify-content-center mt-4">
    <?php for ($i=1; $i<=$paginas_total; $i++): 
        $act = $i === $pagina ? ' active' : '';
        $catparam = $categoria_filtro ? "&cat=" . urlencode($categoria_filtro) : '';
    ?>
        <li class="page-item<?= $act ?>">
            <a class="page-link" href="?p=<?= $i ?><?= $catparam ?>"><?= $i ?></a>
        </li>
    <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php endif; // hay imágenes ?>

<?php endif; // vista principal ?>

</div> <!-- .container -->

<!-- FOOTER -->
<footer class="bg-dark text-white text-center py-3">
    <small>Gestor de Descargas v4 • Licencia MIT • 25 de marzo de 2026 • Grok 3 (xAI) coautor</small>
</footer>

<script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-Fy6S3B9q64WdZWQUiU+q4/2Lc9npb8tCaSX9FK7E8HnRr0Jz8D6OP9dO5Vg3Q9ct" crossorigin="anonymous"></script>

</body>
</html>
