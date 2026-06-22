<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: login/login.php");
    exit();
}

require_once "../database/config.php";

if (!isset($conn)) {
    if (isset($conexion)) {
        $conn = $conexion;
    } elseif (isset($mysqli)) {
        $conn = $mysqli;
    } elseif (defined('DB_SERVER') && defined('DB_USERNAME') && defined('DB_PASSWORD') && defined('DB_NAME')) {
        $conn = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    } elseif (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS') && defined('DB_NAME')) {
        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    }
}

if (!isset($conn) || !$conn) {
    die('Error de conexión a la base de datos.');
}

$id_usuario_actual = (int) $_SESSION['id_usuario'];

define('CARPETA_UPLOADS', __DIR__ . '/../uploads/');
define('EXTENSIONES_PERMITIDAS', ['xls', 'xlsx']);

if (isset($_REQUEST['accion'])) {
    header('Content-Type: application/json; charset=utf-8');
    $accion = $_REQUEST['accion'];

    if ($accion === 'listar_mensajes') {
        $id_contacto = (int) ($_GET['id_contacto'] ?? 0);

        $sql = "SELECT m.id_mensaje, m.id_emisor, m.id_receptor, m.titulo,
                       m.mensaje, m.archivo, m.fecha_y_hora,
                       u.nombre, u.apellido
                FROM mensajes m
                INNER JOIN usuario u ON u.id_usuario = m.id_emisor
                WHERE (m.id_emisor = ? AND m.id_receptor = ?)
                   OR (m.id_emisor = ? AND m.id_receptor = ?)
                ORDER BY m.fecha_y_hora ASC, m.id_mensaje ASC";

        /** @var mysqli $conn */
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            "iiii",
            $id_usuario_actual,
            $id_contacto,
            $id_contacto,
            $id_usuario_actual
        );
        mysqli_stmt_execute($stmt);
        $resultado = mysqli_stmt_get_result($stmt);

        $mensajes = [];
        while ($fila = mysqli_fetch_assoc($resultado)) {
            $mensajes[] = [
                'id_mensaje'   => (int) $fila['id_mensaje'],
                'es_propio'    => ((int) $fila['id_emisor'] === $id_usuario_actual),
                'autor'        => $fila['nombre'] . ' ' . $fila['apellido'],
                'titulo'       => $fila['titulo'],
                'mensaje'      => $fila['mensaje'],
                'archivo'      => $fila['archivo'],
                'fecha_y_hora' => $fila['fecha_y_hora'],
            ];
        }
        mysqli_stmt_close($stmt);

        echo json_encode(['ok' => true, 'mensajes' => $mensajes]);
        exit();
    }

    if ($accion === 'enviar_mensaje') {
        $id_receptor = (int) ($_POST['id_receptor'] ?? 0);
        $titulo      = trim($_POST['titulo'] ?? '');
        $texto       = trim($_POST['mensaje'] ?? '');
        $nombre_guardado = null;

        if ($id_receptor <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Selecciona un destinatario válido.']);
            exit();
        }
        if ($texto === '' && empty($_FILES['archivo']['name'])) {
            echo json_encode(['ok' => false, 'error' => 'Escribe un mensaje o adjunta un archivo.']);
            exit();
        }

        if (!empty($_FILES['archivo']['name'])) {
            $archivo = $_FILES['archivo'];

            if ($archivo['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['ok' => false, 'error' => 'Ocurrió un error al subir el archivo.']);
                exit();
            }

            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, EXTENSIONES_PERMITIDAS, true)) {
                echo json_encode(['ok' => false, 'error' => 'Solo se permiten archivos Excel (.xls o .xlsx).']);
                exit();
            }

            if (!is_dir(CARPETA_UPLOADS)) {
                mkdir(CARPETA_UPLOADS, 0775, true);
            }

            $nombre_base = pathinfo($archivo['name'], PATHINFO_FILENAME);
            $nombre_base = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombre_base);
            $nombre_guardado = $nombre_base . '_' . uniqid() . '.' . $extension;

            $ruta_destino = CARPETA_UPLOADS . $nombre_guardado;

            if (!move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
                echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.']);
                exit();
            }
        }

        $sql = "INSERT INTO mensajes (id_emisor, id_receptor, titulo, mensaje, archivo, fecha_y_hora)
                VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            "iisss",
            $id_usuario_actual,
            $id_receptor,
            $titulo,
            $texto,
            $nombre_guardado
        );

        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el mensaje en la base de datos.']);
        }
        mysqli_stmt_close($stmt);
        exit();
    }

    echo json_encode(['ok' => false, 'error' => 'Acción no reconocida.']);
    exit();
}

$sql_usuarios = "SELECT id_usuario, nombre, apellido, rol
                  FROM usuario
                  WHERE id_usuario != ?
                  ORDER BY nombre ASC";
$stmt = mysqli_prepare($conn, $sql_usuarios);
mysqli_stmt_bind_param($stmt, "i", $id_usuario_actual);
mysqli_stmt_execute($stmt);
$resultado_usuarios = mysqli_stmt_get_result($stmt);
$usuarios = mysqli_fetch_all($resultado_usuarios, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensajes</title>
    <link rel="stylesheet" href="css/mensaje.css">
</head>
<body>
<div class="mensajes-app">

    <aside class="lista-contactos">
        <div class="lista-contactos-header">
            <h2>Mensajes</h2>
        </div>
        <div class="contactos-scroll">
            <?php foreach ($usuarios as $u): ?>
                <?php
                    $iniciales = mb_strtoupper(mb_substr($u['nombre'], 0, 1) . mb_substr($u['apellido'], 0, 1));
                ?>
                <div class="contacto"
                     data-id="<?= (int) $u['id_usuario'] ?>"
                     data-nombre="<?= htmlspecialchars($u['nombre'] . ' ' . $u['apellido']) ?>"
                     data-rol="<?= htmlspecialchars($u['rol']) ?>">
                    <div class="avatar"><?= htmlspecialchars($iniciales) ?></div>
                    <div class="contacto-info">
                        <span class="contacto-nombre"><?= htmlspecialchars($u['nombre'] . ' ' . $u['apellido']) ?></span>
                        <span class="contacto-rol"><?= htmlspecialchars($u['rol']) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($usuarios)): ?>
                <p class="sin-contactos">No hay otros usuarios registrados.</p>
            <?php endif; ?>
        </div>
    </aside>

    <main class="panel-chat">
        <div class="chat-vacio" id="chat-vacio">
            <p>Selecciona un usuario de la lista para ver o enviar mensajes.</p>
        </div>

        <div class="chat-activo" id="chat-activo" style="display:none;">
            <div class="chat-header">
                <div class="avatar" id="chat-avatar"></div>
                <div>
                    <h3 id="chat-nombre"></h3>
                    <span id="chat-rol"></span>
                </div>
            </div>

            <div class="chat-mensajes" id="chat-mensajes"></div>

            <form id="form-mensaje" class="chat-form" autocomplete="off">
                <input type="hidden" name="id_receptor" id="input-id-receptor" value="">
                <input type="text" name="titulo" id="input-titulo" placeholder="Título (opcional)" maxlength="150">
                <div class="chat-form-fila">
                    <textarea name="mensaje" id="input-mensaje" placeholder="Escribe un mensaje..." rows="2"></textarea>
                    <label class="btn-adjuntar" title="Adjuntar Excel">
                        📎
                        <input type="file" name="archivo" id="input-archivo" accept=".xls,.xlsx">
                    </label>
                    <button type="submit" class="btn-enviar">Enviar</button>
                </div>
                <span class="archivo-seleccionado" id="archivo-seleccionado"></span>
                <span class="form-error" id="form-error"></span>
            </form>
        </div>
    </main>
</div>

<script src="js/mensaje.js"></script>
</body>
</html>