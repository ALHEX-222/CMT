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

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iiii",
            $id_usuario_actual, $id_contacto,
            $id_contacto, $id_usuario_actual
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

            $nombre_base     = pathinfo($archivo['name'], PATHINFO_FILENAME);
            $nombre_base     = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombre_base);
            $nombre_guardado = $nombre_base . '_' . uniqid() . '.' . $extension;
            $ruta_destino    = CARPETA_UPLOADS . $nombre_guardado;

            if (!move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
                echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.']);
                exit();
            }
        }

        $sql  = "INSERT INTO mensajes (id_emisor, id_receptor, titulo, mensaje, archivo, fecha_y_hora)
                 VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iisss",
            $id_usuario_actual, $id_receptor,
            $titulo, $texto, $nombre_guardado
        );
        $guardado_ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if (!$guardado_ok) {
            echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el mensaje en la base de datos.']);
            exit();
        }

        echo json_encode(['ok' => true]);
        exit();
    }

    if ($accion === 'eliminar_mensaje') {
        $id_mensaje = (int) ($_POST['id_mensaje'] ?? 0);

        if ($id_mensaje <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID de mensaje inválido.']);
            exit();
        }

        $sql  = "SELECT archivo FROM mensajes WHERE id_mensaje = ? AND id_emisor = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $id_mensaje, $id_usuario_actual);
        mysqli_stmt_execute($stmt);
        $res  = mysqli_stmt_get_result($stmt);
        $fila = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$fila) {
            echo json_encode(['ok' => false, 'error' => 'No tienes permiso para eliminar este mensaje.']);
            exit();
        }

        if (!empty($fila['archivo'])) {
            $ruta = CARPETA_UPLOADS . $fila['archivo'];
            if (file_exists($ruta)) {
                @unlink($ruta);
            }
        }

        $sql  = "DELETE FROM mensajes WHERE id_mensaje = ? AND id_emisor = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $id_mensaje, $id_usuario_actual);
        $ok   = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'No se pudo eliminar el mensaje.']);
        exit();
    }

    if ($accion === 'editar_mensaje') {
        $id_mensaje = (int) ($_POST['id_mensaje'] ?? 0);
        $titulo     = trim($_POST['titulo']  ?? '');
        $texto      = trim($_POST['mensaje'] ?? '');

        if ($id_mensaje <= 0) {
            echo json_encode(['ok' => false, 'error' => 'ID de mensaje inválido.']);
            exit();
        }
        if ($texto === '') {
            echo json_encode(['ok' => false, 'error' => 'El mensaje no puede estar vacío.']);
            exit();
        }

        $sql  = "UPDATE mensajes SET titulo = ?, mensaje = ? WHERE id_mensaje = ? AND id_emisor = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssii", $titulo, $texto, $id_mensaje, $id_usuario_actual);
        $ok   = mysqli_stmt_execute($stmt);
        $rows = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if (!$ok || $rows === 0) {
            echo json_encode(['ok' => false, 'error' => 'No se pudo editar el mensaje.']);
            exit();
        }

        echo json_encode(['ok' => true]);
        exit();
    }

    echo json_encode(['ok' => false, 'error' => 'Acción no reconocida.']);
    exit();
}

$sql_usuarios = "SELECT id_usuario, nombre, apellido, rol, correo, numero, direccion
                  FROM usuario
                  WHERE id_usuario != ?
                  ORDER BY nombre ASC";
$stmt = mysqli_prepare($conn, $sql_usuarios);
mysqli_stmt_bind_param($stmt, "i", $id_usuario_actual);
mysqli_stmt_execute($stmt);
$resultado_usuarios = mysqli_stmt_get_result($stmt);
$usuarios           = mysqli_fetch_all($resultado_usuarios, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensajes</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/mensaje.css">
</head>
<body>
<div class="mensajes-app">

    <aside class="lista-contactos">
        <div class="lista-contactos-header">
            <svg class="icono-encabezado" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
            <h2>Mensajes</h2>
        </div>

        <div class="contactos-tabs">
            <button class="contacto-tab activo">Mis chats
                <span style="background:var(--azul);color:#fff;border-radius:999px;padding:1px 6px;font-size:10px;margin-left:4px"><?= count($usuarios) ?></span>
            </button>
        </div>

        <div class="contactos-scroll">
            <?php foreach ($usuarios as $u): ?>
                <?php
                    $iniciales   = mb_strtoupper(mb_substr($u['nombre'], 0, 1) . mb_substr($u['apellido'], 0, 1));
                    $tono_avatar = $u['id_usuario'] % 5;
                ?>
                <div class="contacto"
                     data-id="<?= (int) $u['id_usuario'] ?>"
                     data-nombre="<?= htmlspecialchars($u['nombre'] . ' ' . $u['apellido']) ?>"
                     data-rol="<?= htmlspecialchars($u['rol']) ?>"
                     data-email="<?= htmlspecialchars($u['correo'] ?? '') ?>"
                     data-phone="<?= htmlspecialchars($u['numero'] ?? '') ?>"
                     data-city="<?= htmlspecialchars($u['direccion'] ?? '') ?>">
                    <div class="avatar avatar-tono-<?= $tono_avatar ?>"><?= htmlspecialchars($iniciales) ?></div>
                    <div class="contacto-info">
                        <span class="contacto-nombre"><?= htmlspecialchars($u['nombre'] . ' ' . $u['apellido']) ?></span>
                        <span class="contacto-rol"><?= htmlspecialchars($u['rol']) ?></span>
                    </div>
                    <span class="contacto-online"></span>
                </div>
            <?php endforeach; ?>

            <?php if (empty($usuarios)): ?>
                <p class="sin-contactos">No hay otros usuarios registrados.</p>
            <?php endif; ?>
        </div>
    </aside>

    <main class="panel-chat">
        <div class="chat-vacio" id="chat-vacio">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
            <p>Selecciona un usuario para ver o enviar mensajes</p>
        </div>

        <div class="chat-activo" id="chat-activo">

            <div class="chat-header">
                <div class="avatar" id="chat-avatar"></div>
                <div class="chat-header-info">
                    <h3 id="chat-nombre"></h3>
                    <div class="chat-header-status">● En línea</div>
                </div>
                <div class="chat-header-actions">
                    <button class="btn-header" title="Más opciones">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                    </button>
                </div>
            </div>

            <div class="chat-mensajes" id="chat-mensajes"></div>

            <form id="form-mensaje" class="chat-form" autocomplete="off">
                <input type="hidden" name="id_receptor" id="input-id-receptor" value="">
                <input type="hidden" name="id_mensaje_edit" id="input-id-mensaje-edit" value="">

                <input type="text" name="titulo" id="input-titulo" class="input-titulo" placeholder="Título (opcional)" maxlength="150">

                <div class="chat-form-fila">
                    <textarea name="mensaje" id="input-mensaje" placeholder="Escribe un mensaje..." rows="2"></textarea>
                    <label class="btn-icono" id="btn-adjuntar" title="Adjuntar Excel">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                        Excel
                        <input type="file" name="archivo" id="input-archivo" accept=".xls,.xlsx">
                    </label>
                    <button type="submit" class="btn-enviar" id="btn-enviar">
                        <span id="btn-enviar-texto">Enviar</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </div>

                <div class="archivo-fila oculto" id="archivo-fila">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span class="archivo-nombre" id="archivo-seleccionado"></span>
                    <button type="button" class="btn-cancelar-archivo" id="btn-cancelar-archivo" title="Quitar archivo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <div class="editar-banner" id="editar-banner">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <span>Editando mensaje</span>
                    <button type="button" id="btn-cancelar-edicion" class="btn-cancelar-edicion">Cancelar</button>
                </div>

                <div class="form-aviso form-error" id="form-error"></div>
                <div class="form-aviso form-exito" id="form-exito"></div>
            </form>
        </div>
    </main>

    <aside class="panel-detalles" id="panel-detalles">
        <div class="detalles-header">Detalles</div>

        <div class="detalles-seccion">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                <div class="avatar" id="detalle-avatar" style="width:44px;height:44px;font-size:15px;"></div>
                <div>
                    <div style="font-size:13px;font-weight:600;color:var(--texto);" id="detalle-nombre"></div>
                    <span class="rol-badge-detalle" id="detalle-rol"></span>
                </div>
            </div>
            <div class="detalles-label">Info general</div>
            <div class="detalle-fila" id="detalle-email-fila">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span id="detalle-email"></span>
            </div>
            <div class="detalle-fila" id="detalle-phone-fila">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.6 3.4 2 2 0 0 1 3.57 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.89a16 16 0 0 0 6.06 6.06l1.06-.97a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                <span id="detalle-phone"></span>
            </div>
            <div class="detalle-fila" id="detalle-city-fila">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <span id="detalle-city"></span>
            </div>
        </div>

        <div class="detalles-seccion">
            <div class="detalles-label">Acciones</div>
            <button class="btn-accion" onclick="document.getElementById('input-archivo').click()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Adjuntar Excel
            </button>
        </div>
    </aside>

</div>

<div class="modal-overlay oculto" id="modal-eliminar">
    <div class="modal-caja">
        <div class="modal-icono">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        </div>
        <p class="modal-texto">¿Eliminar este mensaje?<br><small>Esta acción no se puede deshacer.</small></p>
        <div class="modal-botones">
            <button class="modal-btn modal-btn-cancel" id="modal-cancelar">Cancelar</button>
            <button class="modal-btn modal-btn-confirm" id="modal-confirmar">Eliminar</button>
        </div>
    </div>
</div>

<script src="js/mensaje.js"></script>
</body>
</html>