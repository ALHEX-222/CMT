<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login/login.php");
    exit();
}

$pythonCmd = 'python';
$scriptPy  = str_replace('/', DIRECTORY_SEPARATOR, __DIR__ . '/../backend/alerta.py');

if (!file_exists($scriptPy)) {
    foreach ([
        __DIR__ . '/backend/alerta.py',
        __DIR__ . '/../alerta.py',
        dirname(__DIR__) . '/backend/alerta.py',
    ] as $alt) {
        if (file_exists($alt)) { $scriptPy = $alt; break; }
    }
}

function ejecutarPython($pythonCmd, $scriptPy, $accion, $extra = '') {
    $cmd = escapeshellarg($pythonCmd)
         . ' ' . escapeshellarg($scriptPy)
         . ' --accion ' . escapeshellarg($accion)
         . $extra . ' 2>&1';
    return shell_exec($cmd);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion    = $_POST['accion']    ?? '';
    $id_alerta = $_POST['id_alerta'] ?? '';

    if (!in_array($accion, ['marcar_leida','marcar_atendida','eliminar','monitor'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Acción no permitida.']);
        exit();
    }

    $extra = '';
    if ($id_alerta && ctype_digit($id_alerta))
        $extra .= ' --id_alerta ' . escapeshellarg($id_alerta);

    $output  = ejecutarPython($pythonCmd, $scriptPy, $accion, $extra);
    $decoded = json_decode($output, true);
    header('Content-Type: application/json');
    echo $decoded !== null ? $output : json_encode([
        'success' => false,
        'message' => 'Error ejecutando script Python.',
        'raw'     => substr($output ?? 'Sin salida', 0, 500)
    ]);
    exit();
}

if (isset($_GET['_count'])) {
    ejecutarPython($pythonCmd, $scriptPy, 'monitor');

    $output = ejecutarPython($pythonCmd, $scriptPy, 'listar');
    $data   = json_decode($output, true);
    header('Content-Type: application/json');
    if (!$data || !($data['success'] ?? false)) {
        echo json_encode(['success' => false, 'pendientes' => 0]);
    } else {
        $pendientes = array_reduce($data['alertas'] ?? [], function($c, $a) {
            return $c + ($a['estado'] === 'pendiente' ? 1 : 0);
        }, 0);
        echo json_encode(['success' => true, 'pendientes' => $pendientes]);
    }
    exit();
}

if (isset($_GET['_panel'])) {
    $output = ejecutarPython($pythonCmd, $scriptPy, 'listar', ' --estado pendiente');
    $data   = json_decode($output, true);
    header('Content-Type: application/json');
    if (!$data || !($data['success'] ?? false)) {
        echo json_encode(['success' => false, 'alertas' => []]);
    } else {
        $alertas = array_slice($data['alertas'] ?? [], 0, 10);
        echo json_encode(['success' => true, 'alertas' => $alertas]);
    }
    exit();
}

$filtro_estado = $_GET['estado'] ?? '';
$filtro_estado = in_array($filtro_estado, ['pendiente','leida','atendida','']) ? $filtro_estado : '';

$script_existe = file_exists($scriptPy);
$error   = '';
$alertas = [];

if (!$script_existe) {
    $error = 'Script no encontrado en: ' . htmlspecialchars($scriptPy)
           . ' — Verifica la ruta de alerta.py en el servidor.';
} else {
    ejecutarPython($pythonCmd, $scriptPy, 'monitor');

    $extra  = $filtro_estado ? ' --estado ' . escapeshellarg($filtro_estado) : '';
    $output = ejecutarPython($pythonCmd, $scriptPy, 'listar', $extra);
    $data   = json_decode($output, true);

    if ($data === null) {
        $raw = trim($output ?? '');
        $error = $raw === ''
            ? 'El script Python no produjo ninguna salida. Verifica que Python esté en el PATH y que mysql-connector-python esté instalado.'
            : 'Error en Python: ' . htmlspecialchars(substr($raw, 0, 400));
    } elseif (!($data['success'] ?? false)) {
        $error = $data['message'] ?? 'Error desconocido.';
    } else {
        $alertas = $data['alertas'] ?? [];
    }
}

$total_pendientes = $total_criticas = $total_advertencias = 0;
foreach ($alertas as $a) {
    if ($a['estado'] === 'pendiente')   $total_pendientes++;
    if ($a['tipo']   === 'critica')     $total_criticas++;
    if ($a['tipo']   === 'advertencia') $total_advertencias++;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alertas — CMT</title>
    <link rel="stylesheet" href="css/alerta.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
</head>
<body>
<div class="alert-root">

    <div class="alert-topbar">
        <div class="alert-topbar-left">
            <h1 class="alert-title">
                <i class="bx bx-bell<?php echo $total_criticas > 0 ? ' bx-tada' : ''; ?>"></i>
                CENTRO DE ALERTAS
            </h1>
            <span class="alert-subtitle">
                Monitor automático activo
                <span class="monitor-dot" title="Analiza la BD en cada visita y cada 90 segundos"></span>
            </span>
        </div>
        <div class="alert-topbar-right">
            <?php if ($total_criticas > 0): ?>
            <div class="stat-pill stat-critica">
                <i class="bx bx-error"></i>
                <span><?php echo $total_criticas; ?> crítica<?php echo $total_criticas > 1 ? 's' : ''; ?></span>
            </div>
            <?php endif; ?>
            <?php if ($total_advertencias > 0): ?>
            <div class="stat-pill stat-advertencia">
                <i class="bx bx-error-alt"></i>
                <span><?php echo $total_advertencias; ?> advertencia<?php echo $total_advertencias > 1 ? 's' : ''; ?></span>
            </div>
            <?php endif; ?>
            <div class="stat-pill stat-pendiente">
                <i class="bx bx-time-five"></i>
                <span><?php echo $total_pendientes; ?> pendiente<?php echo $total_pendientes != 1 ? 's' : ''; ?></span>
            </div>
            <button class="btn-icon-top" id="btnRefresh" title="Actualizar ahora">
                <i class="bx bx-refresh"></i>
            </button>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert-error-banner">
        <i class="bx bx-error-circle"></i>
        <div>
            <strong>Error:</strong> <?php echo $error; ?>
            <?php if (!$script_existe): ?>
            <br><small>Ruta buscada: <code><?php echo htmlspecialchars($scriptPy); ?></code></small>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="filter-bar">
        <a href="?estado=" class="filter-tab <?php echo $filtro_estado === '' ? 'active' : ''; ?>">
            <i class="bx bx-list-ul"></i> Todas
            <span class="tab-count"><?php echo count($alertas); ?></span>
        </a>
        <a href="?estado=pendiente" class="filter-tab tab-pendiente <?php echo $filtro_estado === 'pendiente' ? 'active' : ''; ?>">
            <i class="bx bx-time"></i> Pendientes
            <?php if ($total_pendientes > 0 && $filtro_estado !== 'pendiente'): ?>
            <span class="tab-badge"><?php echo $total_pendientes; ?></span>
            <?php endif; ?>
        </a>
        <a href="?estado=leida" class="filter-tab tab-leida <?php echo $filtro_estado === 'leida' ? 'active' : ''; ?>">
            <i class="bx bx-check"></i> Leídas
        </a>
        <a href="?estado=atendida" class="filter-tab tab-atendida <?php echo $filtro_estado === 'atendida' ? 'active' : ''; ?>">
            <i class="bx bx-check-double"></i> Atendidas
        </a>
    </div>

    <div class="auto-banner" id="autoBanner" style="display:none;">
        <i class="bx bx-bell-ring"></i>
        <span id="autoBannerMsg"></span>
        <button onclick="document.getElementById('autoBanner').style.display='none'">
            <i class="bx bx-x"></i>
        </button>
    </div>

    <div class="alert-grid" id="alertGrid">

        <?php if (empty($alertas) && !$error): ?>
        <div class="alert-empty">
            <i class="bx bx-shield-check"></i>
            <p>Todo en orden — sin alertas<?php echo $filtro_estado ? ' ' . $filtro_estado . 's' : ''; ?>.</p>
            <small>El monitor analiza la producción automáticamente en cada visita.</small>
        </div>
        <?php endif; ?>

        <?php foreach ($alertas as $idx => $a):
            $tipo_cls = strtolower($a['tipo']);
            $est_cls  = strtolower($a['estado']);
            $ico_tipo = $a['tipo'] === 'critica'      ? 'bx-error-circle'
                      : ($a['tipo'] === 'advertencia' ? 'bx-error'
                      :                                 'bx-info-circle');
            $ico_est  = $a['estado'] === 'atendida'   ? 'bx-check-double'
                      : ($a['estado'] === 'leida'     ? 'bx-check'
                      :                                 'bx-time');
        ?>
        <div class="alert-card tipo-<?php echo $tipo_cls; ?> estado-<?php echo $est_cls; ?>"
             id="card-<?php echo $a['id_alerta']; ?>"
             style="animation-delay:<?php echo min($idx * 0.04, 0.5); ?>s">

            <div class="card-stripe"></div>

            <div class="card-header">
                <div class="card-tipo">
                    <i class="bx <?php echo $ico_tipo; ?>"></i>
                    <span><?php echo ucfirst($a['tipo']); ?></span>
                </div>
                <span class="badge-estado <?php echo $est_cls; ?>">
                    <i class="bx <?php echo $ico_est; ?>"></i>
                    <?php echo ucfirst($a['estado']); ?>
                </span>
            </div>

            <div class="card-body">
                <p class="card-mensaje"><?php echo htmlspecialchars($a['mensaje']); ?></p>

                <?php if ($a['id_op']): ?>
                <div class="card-op">
                    <i class="bx bx-receipt"></i>
                    <span>
                        OP #<?php echo $a['id_op']; ?>
                        <?php if ($a['op_estilo']): ?>
                          — <strong><?php echo htmlspecialchars($a['op_estilo']); ?></strong>
                        <?php endif; ?>
                        <?php if ($a['cliente']): ?>
                          · <?php echo htmlspecialchars($a['cliente']); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>

                <div class="card-fecha">
                    <i class="bx bx-calendar-event"></i>
                    <?php echo $a['fecha'] ? date('d/m/Y H:i', strtotime($a['fecha'])) : '—'; ?>
                </div>
            </div>

            <div class="card-actions">
                <?php if ($a['estado'] === 'pendiente'): ?>
                <button class="btn-action btn-leer"
                        onclick="accionAlerta('marcar_leida',<?php echo $a['id_alerta']; ?>)">
                    <i class="bx bx-check"></i> Leída
                </button>
                <?php endif; ?>
                <?php if (in_array($a['estado'], ['pendiente','leida'])): ?>
                <button class="btn-action btn-atender"
                        onclick="accionAlerta('marcar_atendida',<?php echo $a['id_alerta']; ?>)">
                    <i class="bx bx-check-double"></i> Atendida
                </button>
                <?php endif; ?>
                <button class="btn-action btn-eliminar"
                        onclick="confirmarEliminar(<?php echo $a['id_alerta']; ?>)">
                    <i class="bx bx-trash"></i>
                </button>
            </div>

        </div>
        <?php endforeach; ?>

    </div>
</div>

<div class="modal-overlay" id="modalEliminar">
    <div class="modal-card modal-sm">
        <div class="modal-header">
            <h3><i class="bx bx-trash" style="color:#f43f5e"></i> Eliminar Alerta</h3>
            <button class="modal-close" onclick="cerrarModalEliminar()"><i class="bx bx-x"></i></button>
        </div>
        <div class="modal-body" style="text-align:center;padding:24px;">
            <p style="color:#475569;font-size:14px;">¿Eliminar esta alerta? Esta acción no se puede deshacer.</p>
        </div>
        <div class="modal-footer">
            <button class="btn-modal-cancel" onclick="cerrarModalEliminar()">Cancelar</button>
            <button class="btn-modal-delete" id="btnConfirmarEliminar">
                <i class="bx bx-trash"></i> Sí, eliminar
            </button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
window.ALERT_TOTAL_PEND = <?php echo $total_pendientes; ?>;
window.ALERT_TOTAL_CRIT = <?php echo $total_criticas; ?>;
</script>
<script src="js/alerta.js"></script>
</body>
</html>