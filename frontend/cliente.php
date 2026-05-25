<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login/login.php");
    exit();
}

require_once "../database/config.php";

if (isset($_GET['action']) && $_GET['action'] === 'get_cliente') {
    header('Content-Type: application/json');
    $id = intval($_GET['id_cliente'] ?? 0);
    if ($id <= 0) { echo json_encode(["success" => false, "message" => "ID inválido."]); exit(); }

    /** @var mysqli $conn */
    $stmt = mysqli_prepare($conn,
        "SELECT id_cliente, nombre_cliente, ruc, telefono, correo, direccion FROM cliente WHERE id_cliente = ?"
    );
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $cliente = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$cliente) { echo json_encode(["success" => false, "message" => "Cliente no encontrado."]); exit(); }

    $stmt2 = mysqli_prepare($conn,
        "SELECT id_op, estilo, descripcion, cantidad_prendas, fecha_ingreso, estado,
                (SELECT COUNT(*) FROM orden_corte oc WHERE oc.id_op = op.id_op) AS total_oc
         FROM orden_pedido op
         WHERE op.id_cliente = ?
         ORDER BY CASE WHEN op.estado = 'Pendiente' THEN 0 ELSE 1 END, op.fecha_ingreso DESC"
    );
    mysqli_stmt_bind_param($stmt2, "i", $id);
    mysqli_stmt_execute($stmt2);
    $res2   = mysqli_stmt_get_result($stmt2);
    $pedidos = [];
    while ($row = mysqli_fetch_assoc($res2)) $pedidos[] = $row;

    $total_ops       = count($pedidos);
    $ops_pendientes  = count(array_filter($pedidos, fn($p) => $p['estado'] === 'Pendiente'));
    $ops_completadas = count(array_filter($pedidos, fn($p) => $p['estado'] === 'Completado'));
    $total_prendas   = array_sum(array_column($pedidos, 'cantidad_prendas'));

    echo json_encode([
        "success"        => true,
        "cliente"        => $cliente,
        "pedidos"        => $pedidos,
        "total_ops"      => $total_ops,
        "ops_pendientes" => $ops_pendientes,
        "ops_completadas"=> $ops_completadas,
        "total_prendas"  => $total_prendas
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crear_cliente') {
    header('Content-Type: application/json');
    $nombre    = trim($_POST['nombre_cliente'] ?? '');
    $ruc       = trim($_POST['ruc']            ?? '');
    $telefono  = trim($_POST['telefono']       ?? '');
    $correo    = trim($_POST['correo']         ?? '');
    $direccion = trim($_POST['direccion']      ?? '');

    if (!$nombre || !$ruc) {
        echo json_encode(["success" => false, "message" => "Nombre y RUC son obligatorios."]);
        exit();
    }

    /** @var mysqli $conn */
    $chk = mysqli_prepare($conn, "SELECT id_cliente FROM cliente WHERE ruc = ?");
    mysqli_stmt_bind_param($chk, "s", $ruc);
    mysqli_stmt_execute($chk);
    mysqli_stmt_store_result($chk);
    if (mysqli_stmt_num_rows($chk) > 0) {
        echo json_encode(["success" => false, "message" => "Ya existe un cliente con ese RUC."]);
        exit();
    }

    $ins = mysqli_prepare($conn,
        "INSERT INTO cliente (nombre_cliente, ruc, telefono, correo, direccion) VALUES (?,?,?,?,?)"
    );
    mysqli_stmt_bind_param($ins, "sssss", $nombre, $ruc, $telefono, $correo, $direccion);
    if (mysqli_stmt_execute($ins)) {
        echo json_encode(["success" => true, "message" => "Cliente registrado correctamente.", "id" => mysqli_insert_id($conn)]);
    } else {
        echo json_encode(["success" => false, "message" => "Error al guardar: " . mysqli_error($conn)]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar_cliente') {
    header('Content-Type: application/json');
    $id        = intval($_POST['id_cliente']   ?? 0);
    $nombre    = trim($_POST['nombre_cliente'] ?? '');
    $ruc       = trim($_POST['ruc']            ?? '');
    $telefono  = trim($_POST['telefono']       ?? '');
    $correo    = trim($_POST['correo']         ?? '');
    $direccion = trim($_POST['direccion']      ?? '');

    if (!$id || !$nombre || !$ruc) {
        echo json_encode(["success" => false, "message" => "Datos incompletos."]);
        exit();
    }

    /** @var mysqli $conn */
    $upd = mysqli_prepare($conn,
        "UPDATE cliente SET nombre_cliente=?, ruc=?, telefono=?, correo=?, direccion=? WHERE id_cliente=?"
    );
    mysqli_stmt_bind_param($upd, "sssssi", $nombre, $ruc, $telefono, $correo, $direccion, $id);
    if (mysqli_stmt_execute($upd)) {
        echo json_encode(["success" => true, "message" => "Cliente actualizado correctamente."]);
    } else {
        echo json_encode(["success" => false, "message" => "Error: " . mysqli_error($conn)]);
    }
    exit();
}

/** @var mysqli $conn */
$sql = "SELECT c.id_cliente, c.nombre_cliente, c.ruc, c.telefono, c.correo, c.direccion,
        COUNT(op.id_op)                                                          AS total_ops,
        SUM(CASE WHEN op.estado = 'Pendiente'  THEN 1 ELSE 0 END)              AS ops_pendientes,
        SUM(CASE WHEN op.estado = 'Completado' THEN 1 ELSE 0 END)              AS ops_completadas,
        COALESCE(SUM(op.cantidad_prendas), 0)                                    AS total_prendas
        FROM cliente c
        LEFT JOIN orden_pedido op ON op.id_cliente = c.id_cliente
        GROUP BY c.id_cliente
        ORDER BY c.nombre_cliente ASC";
$resultado = mysqli_query($conn, $sql);
$clientes  = [];
while ($row = mysqli_fetch_assoc($resultado)) $clientes[] = $row;

function iniciales($nombre) {
    $partes = explode(' ', strtoupper(trim($nombre)));
    $ini    = '';
    foreach (array_slice($partes, 0, 2) as $p) $ini .= $p[0] ?? '';
    return $ini ?: '?';
}

$AVATAR_COLORS = [
    ['#1565c0','#e3f2fd'], ['#6a1b9a','#f3e5f5'], ['#00695c','#e0f2f1'],
    ['#e65100','#fff3e0'], ['#880e4f','#fce4ec'], ['#37474f','#eceff1'],
    ['#1b5e20','#e8f5e9'], ['#bf360c','#fbe9e7'], ['#0d47a1','#e8eaf6'],
    ['#4a148c','#f8f0ff'],
];
function avatarColor($id, $colors) {
    return $colors[$id % count($colors)];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Clientes - CMT</title>
    <link rel="stylesheet" href="css/cliente.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
</head>
<body>
<div class="cli-container">

    <div class="cli-header">
        <div class="cli-header-left">
            <h2><i class="bx bx-buildings"></i> GESTIÓN DE CLIENTES</h2>
            <p>Registro, seguimiento y control de órdenes por cliente</p>
        </div>
        <button class="btn-nuevo-cliente" id="btnNuevoCliente">
            <i class="bx bx-user-plus"></i> NUEVO CLIENTE
        </button>
    </div>

    <?php
        $total_cli   = count($clientes);
        $con_pedidos = count(array_filter($clientes, fn($c) => $c['total_ops'] > 0));
        $tot_ops     = array_sum(array_column($clientes, 'total_ops'));
        $tot_pend    = array_sum(array_column($clientes, 'ops_pendientes'));
    ?>
    <div class="cli-kpis">
        <div class="kpi-card">
            <div class="kpi-icon"><i class="bx bx-buildings"></i></div>
            <div>
                <span class="kpi-val"><?php echo $total_cli; ?></span>
                <span class="kpi-label">Clientes registrados</span>
            </div>
        </div>
        <div class="kpi-card kpi-blue">
            <div class="kpi-icon"><i class="bx bx-user-check"></i></div>
            <div>
                <span class="kpi-val"><?php echo $con_pedidos; ?></span>
                <span class="kpi-label">Con pedidos activos</span>
            </div>
        </div>
        <div class="kpi-card kpi-amber">
            <div class="kpi-icon"><i class="bx bx-time"></i></div>
            <div>
                <span class="kpi-val"><?php echo $tot_pend; ?></span>
                <span class="kpi-label">OPs en proceso</span>
            </div>
        </div>
        <div class="kpi-card kpi-green">
            <div class="kpi-icon"><i class="bx bx-cart-alt"></i></div>
            <div>
                <span class="kpi-val"><?php echo $tot_ops; ?></span>
                <span class="kpi-label">Órdenes totales</span>
            </div>
        </div>
    </div>

    <div class="cli-toolbar">
        <div class="cli-filters">
            <div class="filter-input-wrap">
                <i class="bx bx-search"></i>
                <input type="text" id="filtroNombre" placeholder="Buscar por nombre...">
            </div>
            <div class="filter-input-wrap">
                <i class="bx bx-id-card"></i>
                <input type="text" id="filtroRUC" placeholder="Buscar por RUC...">
            </div>
            <div class="filter-input-wrap">
                <i class="bx bx-filter-alt"></i>
                <select id="filtroEstado">
                    <option value="">Todos</option>
                    <option value="con_pedidos">Con pedidos</option>
                    <option value="sin_pedidos">Sin pedidos</option>
                    <option value="pendientes">Con OPs pendientes</option>
                </select>
            </div>
        </div>
        <div class="cli-view-controls">
            <span id="cliCount" class="cli-count"></span>
            <button class="view-btn active" id="btnGrid" title="Vista cuadrícula">
                <i class="bx bx-grid-alt"></i>
            </button>
            <button class="view-btn" id="btnList" title="Vista lista">
                <i class="bx bx-list-ul"></i>
            </button>
        </div>
    </div>

    <div class="cli-grid" id="cliGrid">
        <?php foreach ($clientes as $i => $c):
            $col   = avatarColor($c['id_cliente'], $AVATAR_COLORS);
            $ini   = iniciales($c['nombre_cliente']);
            $tiene = $c['total_ops'] > 0;
            $delay = ($i % 12) * 0.04;
        ?>
        <div class="cli-card"
             data-id="<?php echo $c['id_cliente']; ?>"
             data-nombre="<?php echo strtolower(htmlspecialchars($c['nombre_cliente'])); ?>"
             data-ruc="<?php echo $c['ruc']; ?>"
             data-tiene="<?php echo $tiene ? '1' : '0'; ?>"
             data-pendientes="<?php echo $c['ops_pendientes']; ?>"
             style="animation-delay: <?php echo $delay; ?>s">

            <div class="card-accent" style="background: <?php echo $col[0]; ?>"></div>

            <div class="card-top">
                <div class="cli-avatar" style="background: <?php echo $col[1]; ?>; color: <?php echo $col[0]; ?>;">
                    <?php echo $ini; ?>
                </div>
                <div class="card-name-wrap">
                    <h3 class="card-name"><?php echo htmlspecialchars($c['nombre_cliente']); ?></h3>
                    <span class="card-ruc"><i class="bx bx-id-card"></i> <?php echo $c['ruc']; ?></span>
                </div>
                <?php if ($c['ops_pendientes'] > 0): ?>
                <span class="card-badge-activo">
                    <i class="bx bx-loader-alt bx-spin"></i>
                    <?php echo $c['ops_pendientes']; ?> activ<?php echo $c['ops_pendientes'] > 1 ? 'as' : 'a'; ?>
                </span>
                <?php endif; ?>
            </div>

            <div class="card-contact">
                <?php if ($c['telefono']): ?>
                <div class="contact-row">
                    <i class="bx bx-phone"></i>
                    <span><?php echo htmlspecialchars($c['telefono']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($c['correo']): ?>
                <div class="contact-row">
                    <i class="bx bx-envelope"></i>
                    <span><?php echo htmlspecialchars($c['correo']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($c['direccion']): ?>
                <div class="contact-row">
                    <i class="bx bx-map"></i>
                    <span class="contact-dir"><?php echo htmlspecialchars($c['direccion']); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="card-stats">
                <div class="stat-item">
                    <span class="stat-val"><?php echo $c['total_ops']; ?></span>
                    <span class="stat-label">Órdenes</span>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <span class="stat-val pend"><?php echo $c['ops_pendientes']; ?></span>
                    <span class="stat-label">Pendientes</span>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <span class="stat-val comp"><?php echo $c['ops_completadas']; ?></span>
                    <span class="stat-label">Completadas</span>
                </div>
            </div>

            <div class="card-actions">
                <button class="btn-card-detalle" data-id="<?php echo $c['id_cliente']; ?>">
                    <i class="bx bx-show-alt"></i> VER DETALLE
                </button>
                <button class="btn-card-edit" data-id="<?php echo $c['id_cliente']; ?>"
                        data-nombre="<?php echo htmlspecialchars($c['nombre_cliente']); ?>"
                        data-ruc="<?php echo htmlspecialchars($c['ruc']); ?>"
                        data-telefono="<?php echo htmlspecialchars($c['telefono']); ?>"
                        data-correo="<?php echo htmlspecialchars($c['correo']); ?>"
                        data-direccion="<?php echo htmlspecialchars($c['direccion']); ?>">
                    <i class="bx bx-edit"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="cliEmpty" class="cli-empty" style="display:none;">
        <i class="bx bx-search-alt"></i>
        <p>No se encontraron clientes con esos filtros.</p>
    </div>

</div>

<div id="modalDetalle" class="modal-overlay" style="display:none;">
    <div class="modal-panel">

        <div class="modal-header" id="detalleHeader">
            <div class="modal-title-group">
                <div class="modal-avatar" id="detalleAvatar"></div>
                <div>
                    <h3 id="detalleName">Cliente</h3>
                    <p class="modal-subtitle" id="detalleRUC"></p>
                </div>
            </div>
            <div class="modal-header-right">
                <button class="btn-modal-edit" id="btnEditarDesdeDetalle">
                    <i class="bx bx-edit"></i> Editar
                </button>
                <button class="btn-cerrar-modal" id="btnCerrarDetalle">
                    <i class="bx bx-x"></i>
                </button>
            </div>
        </div>

        <div id="detalleContacto" class="detalle-contacto"></div>
        <div id="detalleKpis" class="detalle-kpis"></div>

        <div class="modal-tabs">
            <button class="tab-btn active" data-tab="pendientes">
                <i class="bx bx-time"></i> En Proceso
                <span class="tab-count" id="tabPend">0</span>
            </button>
            <button class="tab-btn" data-tab="completados">
                <i class="bx bx-check-circle"></i> Completados
                <span class="tab-count" id="tabComp">0</span>
            </button>
            <button class="tab-btn" data-tab="todos">
                <i class="bx bx-list-ul"></i> Todos
            </button>
        </div>

        <div id="detalleBody" class="modal-body">
        </div>
    </div>
</div>

<div id="modalForm" class="modal-overlay" style="display:none;">
    <div class="modal-panel modal-panel-form">

        <div class="modal-header" style="background: linear-gradient(135deg,#f0f7ff,#e8f4fd);">
            <div class="modal-title-group">
                <div class="modal-icon-form">
                    <i class="bx bx-user-plus"></i>
                </div>
                <div>
                    <h3 id="formTitle">Nuevo Cliente</h3>
                    <p class="modal-subtitle" id="formSubtitle">Completa todos los datos del cliente</p>
                </div>
            </div>
            <button class="btn-cerrar-modal" id="btnCerrarForm"><i class="bx bx-x"></i></button>
        </div>

        <div class="modal-body form-body">
            <input type="hidden" id="formId">

            <div class="form-grid">
                <div class="form-group full">
                    <label><i class="bx bx-buildings"></i> Nombre del cliente <span class="req">*</span></label>
                    <input type="text" id="fNombre" placeholder="Ej: VINEYARD VINES LLC">
                </div>
                <div class="form-group">
                    <label><i class="bx bx-id-card"></i> RUC <span class="req">*</span></label>
                    <input type="text" id="fRUC" placeholder="20 dígitos" maxlength="20">
                </div>
                <div class="form-group">
                    <label><i class="bx bx-phone"></i> Teléfono</label>
                    <input type="text" id="fTelefono" placeholder="Ej: +51 956 123 456">
                </div>
                <div class="form-group full">
                    <label><i class="bx bx-envelope"></i> Correo electrónico</label>
                    <input type="email" id="fCorreo" placeholder="contacto@empresa.com">
                </div>
                <div class="form-group full">
                    <label><i class="bx bx-map"></i> Dirección</label>
                    <input type="text" id="fDireccion" placeholder="Av. Principal 123, Ciudad">
                </div>
            </div>

            <div id="formAlert" class="form-alert" style="display:none;"></div>

            <button class="btn-form-submit" id="btnSubmitForm">
                <i class="bx bx-save"></i> GUARDAR CLIENTE
            </button>
        </div>
    </div>
</div>

<script src="js/cliente.js"></script>
</body>
</html>