<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login/login.php");
    exit();
}

$fecha_desde = trim($_GET['fecha_desde'] ?? '');
$fecha_hasta = trim($_GET['fecha_hasta'] ?? '');
$id_cliente  = trim($_GET['id_cliente']  ?? '');
$estado_fil  = trim($_GET['estado_fil']  ?? '');

$fecha_desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde) ? $fecha_desde : '';
$fecha_hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta) ? $fecha_hasta : '';
$id_cliente  = ctype_digit($id_cliente) ? $id_cliente : '';
$estado_fil  = in_array($estado_fil, ['Pendiente','Completado','']) ? $estado_fil : '';

$hay_filtros = ($fecha_desde || $fecha_hasta || $id_cliente || $estado_fil);

$error = '';

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=cmt_costura;charset=utf8mb4",
        'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    $error = 'Error de conexión a la base de datos: ' . $e->getMessage();
    $pdo   = null;
}

$kpis                = [];
$tendencia_mensual   = [];
$top_clientes        = [];
$distribucion_lineas = [];
$estado_ops          = [];
$top_estilos         = [];
$ops_recientes       = [];
$carga_lineas        = [];
$prendas_por_mes     = [];
$eficiencia          = [];
$clientes_lista      = [];
$ef_por_linea        = [];

if ($pdo) {
    $wp = []; $wc = [];
    if ($fecha_desde) { $wc[] = 'op.fecha_ingreso >= :fd'; $wp[':fd'] = $fecha_desde; }
    if ($fecha_hasta) { $wc[] = 'op.fecha_ingreso <= :fh'; $wp[':fh'] = $fecha_hasta; }
    if ($id_cliente)  { $wc[] = 'op.id_cliente = :ic';     $wp[':ic'] = (int)$id_cliente; }
    if ($estado_fil)  { $wc[] = 'op.estado = :est';        $wp[':est'] = $estado_fil; }
    $where = $wc ? 'WHERE ' . implode(' AND ', $wc) : '';
    $and   = $wc ? 'AND '   . implode(' AND ', $wc) : '';

    $s = $pdo->prepare("SELECT id_cliente, nombre_cliente FROM cliente ORDER BY nombre_cliente");
    $s->execute(); $clientes_lista = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT COUNT(*) AS total_ops,
               SUM(CASE WHEN estado='Pendiente'  THEN 1 ELSE 0 END) AS ops_pendientes,
               SUM(CASE WHEN estado='Completado' THEN 1 ELSE 0 END) AS ops_completadas,
               COALESCE(SUM(cantidad_prendas),0) AS total_prendas,
               COALESCE(AVG(tasa_cumplimiento),0) AS tasa_promedio
        FROM orden_pedido op $where
    ");
    $s->execute($wp); $kpi_ops = $s->fetch();

    $s = $pdo->query("SELECT COUNT(*) AS total FROM cliente"); $kpi_cli = $s->fetch();
    $s = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN estado='Activa' THEN 1 ELSE 0 END) AS activas FROM linea"); $kpi_lin = $s->fetch();

    $s = $pdo->prepare("SELECT COUNT(*) AS total FROM orden_corte oc INNER JOIN orden_pedido op ON oc.id_op=op.id_op $where");
    $s->execute($wp); $kpi_oc = $s->fetch();

    $s = $pdo->prepare("
        SELECT COALESCE(SUM(oc.cantidad),0) AS prendas_proceso
        FROM orden_corte oc INNER JOIN orden_pedido op ON oc.id_op=op.id_op
        WHERE op.estado='Pendiente' AND oc.id_linea IS NOT NULL $and
    ");
    $s->execute($wp); $pp = $s->fetch();

    $kpis = [
        'total_ops'          => (int)($kpi_ops['total_ops']      ?? 0),
        'ops_pendientes'     => (int)($kpi_ops['ops_pendientes']  ?? 0),
        'ops_completadas'    => (int)($kpi_ops['ops_completadas'] ?? 0),
        'total_prendas'      => (float)($kpi_ops['total_prendas'] ?? 0),
        'total_clientes'     => (int)($kpi_cli['total']           ?? 0),
        'total_lineas'       => (int)($kpi_lin['total']           ?? 0),
        'lineas_activas'     => (int)($kpi_lin['activas']         ?? 0),
        'total_oc'           => (int)($kpi_oc['total']            ?? 0),
        'prendas_en_proceso' => (float)($pp['prendas_proceso']    ?? 0),
        'tasa_promedio'      => round((float)($kpi_ops['tasa_promedio'] ?? 0), 2),
    ];

    $s = $pdo->prepare("
        SELECT COALESCE(AVG(tasa_cumplimiento),0) AS promedio,
               COALESCE(MIN(tasa_cumplimiento),0) AS minimo,
               COALESCE(MAX(tasa_cumplimiento),0) AS maximo,
               SUM(CASE WHEN tasa_cumplimiento>=85 THEN 1 ELSE 0 END) AS sobre_meta,
               COUNT(*) AS total_con_tasa
        FROM orden_pedido op $where
    ");
    $s->execute($wp); $ef = $s->fetch();
    $eficiencia = [
        'promedio'      => round((float)($ef['promedio']   ?? 0), 2),
        'minimo'        => round((float)($ef['minimo']     ?? 0), 2),
        'maximo'        => round((float)($ef['maximo']     ?? 0), 2),
        'sobre_meta'    => (int)($ef['sobre_meta']         ?? 0),
        'total_con_tasa'=> (int)($ef['total_con_tasa']     ?? 0),
    ];

    $wp12 = $wp;
    $s = $pdo->prepare("
        SELECT DATE_FORMAT(op.fecha_ingreso,'%Y-%m') AS mes,
               DATE_FORMAT(op.fecha_ingreso,'%b %Y') AS mes_label,
               COUNT(*) AS total,
               SUM(CASE WHEN op.estado='Pendiente'  THEN 1 ELSE 0 END) AS pendientes,
               SUM(CASE WHEN op.estado='Completado' THEN 1 ELSE 0 END) AS completadas,
               COALESCE(SUM(op.cantidad_prendas),0) AS prendas
        FROM orden_pedido op
        WHERE op.fecha_ingreso >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $and
        GROUP BY DATE_FORMAT(op.fecha_ingreso,'%Y-%m')
        ORDER BY mes ASC
    ");
    $s->execute($wp12); $tendencia_mensual = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT c.nombre_cliente, c.id_cliente,
               COUNT(op.id_op) AS total_ops,
               COALESCE(SUM(op.cantidad_prendas),0) AS total_prendas,
               SUM(CASE WHEN op.estado='Pendiente' THEN 1 ELSE 0 END) AS pendientes
        FROM cliente c
        LEFT JOIN orden_pedido op ON op.id_cliente=c.id_cliente $and
        GROUP BY c.id_cliente
        ORDER BY total_prendas DESC LIMIT 8
    ");
    $s->execute($wp); $top_clientes = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT l.num_linea, l.estado,
               COUNT(oc.id_oc) AS total_oc,
               COALESCE(SUM(oc.cantidad),0) AS total_prendas,
               COUNT(DISTINCT oc.id_op) AS ops_distintas
        FROM linea l
        LEFT JOIN orden_corte oc ON oc.id_linea=l.id_linea
        LEFT JOIN orden_pedido op ON op.id_op=oc.id_op $and
        GROUP BY l.id_linea ORDER BY l.num_linea ASC
    ");
    $s->execute($wp); $distribucion_lineas = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT estado, COUNT(*) AS cantidad,
               COALESCE(SUM(cantidad_prendas),0) AS prendas
        FROM orden_pedido op $where GROUP BY estado
    ");
    $s->execute($wp); $estado_ops = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT estilo, COUNT(*) AS total_ops,
               COALESCE(SUM(cantidad_prendas),0) AS total_prendas
        FROM orden_pedido op $where
        GROUP BY estilo ORDER BY total_prendas DESC LIMIT 8
    ");
    $s->execute($wp); $top_estilos = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT op.id_op, op.estilo, op.descripcion, op.cantidad_prendas,
               op.fecha_ingreso, op.estado, op.tasa_cumplimiento,
               c.nombre_cliente, COUNT(oc.id_oc) AS total_oc
        FROM orden_pedido op
        INNER JOIN cliente c ON op.id_cliente=c.id_cliente
        LEFT  JOIN orden_corte oc ON oc.id_op=op.id_op
        $where GROUP BY op.id_op ORDER BY op.fecha_ingreso DESC LIMIT 10
    ");
    $s->execute($wp); $ops_recientes = $s->fetchAll();

    $s = $pdo->query("
        SELECT l.num_linea, l.id_linea, l.estado AS estado_linea, l.num_operarios,
               COUNT(DISTINCT oc.id_oc) AS ocs_activas,
               COALESCE(SUM(oc.cantidad),0) AS carga_actual,
               COUNT(DISTINCT oc.id_op) AS ops_activas
        FROM linea l
        LEFT JOIN orden_corte oc ON oc.id_linea=l.id_linea
        LEFT JOIN orden_pedido op ON op.id_op=oc.id_op AND op.estado='Pendiente'
        GROUP BY l.id_linea ORDER BY l.num_linea ASC
    ");
    $carga_lineas = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT DATE_FORMAT(op.fecha_ingreso,'%b') AS mes_corto,
               DATE_FORMAT(op.fecha_ingreso,'%Y-%m') AS mes_ord,
               COALESCE(SUM(op.cantidad_prendas),0) AS prendas
        FROM orden_pedido op
        WHERE op.fecha_ingreso >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) $and
        GROUP BY DATE_FORMAT(op.fecha_ingreso,'%Y-%m') ORDER BY mes_ord ASC
    ");
    $s->execute($wp); $prendas_por_mes = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT l.num_linea,
               COALESCE(AVG(op.tasa_cumplimiento),0) AS ef_promedio,
               COUNT(DISTINCT op.id_op) AS ops_count
        FROM linea l
        LEFT JOIN orden_corte oc ON oc.id_linea=l.id_linea
        LEFT JOIN orden_pedido op ON op.id_op=oc.id_op $and
        GROUP BY l.id_linea ORDER BY l.num_linea ASC
    ");
    $s->execute($wp); $ef_por_linea = $s->fetchAll();
}

$cliente_sel_nombre = '';
if ($id_cliente) {
    foreach ($clientes_lista as $cl) {
        if ($cl['id_cliente'] == $id_cliente) { $cliente_sel_nombre = $cl['nombre_cliente']; break; }
    }
}

function jsJson($v) {
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - CMT</title>
    <link rel="stylesheet" href="css/dashboard.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
</head>
<body>
<div class="dash-root">

    <div class="dash-topbar">
        <div class="dash-topbar-left">
            <h1 class="dash-title"><i class="bx bx-pulse"></i> PANEL DE CONTROL — CMT</h1>
            <span class="dash-subtitle">Producción textil en tiempo real</span>
        </div>
        <div class="dash-topbar-right">
            <div class="dash-last-update">
                <i class="bx bx-time-five"></i>
                Actualizado: <span id="lastUpdate"></span>
            </div>
            <button class="btn-icon" id="btnFilter" title="Filtros" <?php if($hay_filtros) echo 'data-active="true"'; ?>>
                <i class="bx bx-filter-alt"></i>
                <?php if($hay_filtros): ?><span class="btn-badge"></span><?php endif; ?>
            </button>
            <button class="btn-icon" id="btnExport" title="Exportar Excel"><i class="bx bx-download"></i></button>
            <button class="btn-icon" id="btnRefresh" title="Actualizar datos"><i class="bx bx-refresh"></i></button>
        </div>
    </div>

    <div class="filter-panel" id="filterPanel" <?php if($hay_filtros) echo 'style="display:block;"'; ?>>
        <form method="GET" class="filter-form" id="filterForm">
            <div class="filter-group">
                <label class="filter-label"><i class="bx bx-calendar"></i> Desde</label>
                <input type="date" name="fecha_desde" class="filter-input" value="<?= htmlspecialchars($fecha_desde) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bx bx-calendar-check"></i> Hasta</label>
                <input type="date" name="fecha_hasta" class="filter-input" value="<?= htmlspecialchars($fecha_hasta) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bx bx-buildings"></i> Cliente</label>
                <select name="id_cliente" class="filter-input">
                    <option value="">Todos los clientes</option>
                    <?php foreach ($clientes_lista as $cl): ?>
                    <option value="<?= $cl['id_cliente'] ?>" <?= $id_cliente==$cl['id_cliente']?'selected':'' ?>>
                        <?= htmlspecialchars($cl['nombre_cliente']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bx bx-tag"></i> Estado</label>
                <select name="estado_fil" class="filter-input">
                    <option value="">Todos</option>
                    <option value="Pendiente"  <?= $estado_fil==='Pendiente' ?'selected':'' ?>>Pendiente</option>
                    <option value="Completado" <?= $estado_fil==='Completado'?'selected':'' ?>>Completado</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-filter-apply"><i class="bx bx-search"></i> Aplicar</button>
                <a href="?" class="btn-filter-clear"><i class="bx bx-x"></i> Limpiar</a>
            </div>
        </form>
        <?php if ($hay_filtros): ?>
        <div class="filter-chips">
            <span class="chip-label">Filtros activos:</span>
            <?php if ($fecha_desde): ?><span class="filter-chip">Desde: <?= $fecha_desde ?></span><?php endif; ?>
            <?php if ($fecha_hasta): ?><span class="filter-chip">Hasta: <?= $fecha_hasta ?></span><?php endif; ?>
            <?php if ($cliente_sel_nombre): ?><span class="filter-chip"><?= htmlspecialchars($cliente_sel_nombre) ?></span><?php endif; ?>
            <?php if ($estado_fil): ?><span class="filter-chip"><?= $estado_fil ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
    <div class="dash-error-banner"><i class="bx bx-error-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="kpi-row">
        <div class="kpi-tile kpi-blue">
            <div class="kpi-tile-icon"><i class="bx bx-cart-alt"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?= $kpis['total_ops'] ?? 0 ?>">0</div>
                <div class="kpi-tile-label">Órdenes Totales</div>
            </div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-cart-alt"></i></div>
        </div>
        <div class="kpi-tile kpi-amber">
            <div class="kpi-tile-icon"><i class="bx bx-time"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?= $kpis['ops_pendientes'] ?? 0 ?>">0</div>
                <div class="kpi-tile-label">En Proceso</div>
            </div>
            <div class="kpi-tile-sub">
                <?php $pp = ($kpis['total_ops']??0)>0 ? round(($kpis['ops_pendientes']??0)/($kpis['total_ops'])*100) : 0; ?>
                <?= $pp ?>% del total
            </div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-time"></i></div>
        </div>
        <div class="kpi-tile kpi-green">
            <div class="kpi-tile-icon"><i class="bx bx-check-circle"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?= $kpis['ops_completadas'] ?? 0 ?>">0</div>
                <div class="kpi-tile-label">Completadas</div>
            </div>
            <div class="kpi-tile-sub">
                <?php $pc = ($kpis['total_ops']??0)>0 ? round(($kpis['ops_completadas']??0)/($kpis['total_ops'])*100) : 0; ?>
                <?= $pc ?>% completado
            </div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-check-circle"></i></div>
        </div>
        <div class="kpi-tile kpi-indigo">
            <div class="kpi-tile-icon"><i class="bx bx-t-shirt"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?= intval($kpis['total_prendas'] ?? 0) ?>">0</div>
                <div class="kpi-tile-label">Total Prendas</div>
            </div>
            <div class="kpi-tile-sub"><?= number_format($kpis['prendas_en_proceso'] ?? 0) ?> en proceso</div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-t-shirt"></i></div>
        </div>
        <div class="kpi-tile kpi-teal">
            <div class="kpi-tile-icon"><i class="bx bx-buildings"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?= $kpis['total_clientes'] ?? 0 ?>">0</div>
                <div class="kpi-tile-label">Clientes</div>
            </div>
            <div class="kpi-tile-sub"><?= $kpis['total_oc'] ?? 0 ?> órdenes de corte</div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-buildings"></i></div>
        </div>
        <div class="kpi-tile kpi-rose">
            <div class="kpi-tile-icon"><i class="bx bx-git-branch"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?= $kpis['lineas_activas'] ?? 0 ?>">0</div>
                <div class="kpi-tile-label">Líneas Activas</div>
            </div>
            <div class="kpi-tile-sub">de <?= $kpis['total_lineas'] ?? 0 ?> en total</div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-git-branch"></i></div>
        </div>
    </div>

    <div class="charts-row-main">
        <div class="chart-panel chart-wide">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="bx bx-git-branch"></i> CARGA POR LÍNEA DE PRODUCCIÓN
                    <span class="panel-badge" id="alertBadge" style="display:none; background:#fee2e2; color:#b91c1c; border-color:#fca5a5;">
                        <i class="bx bx-error-alt"></i> <span id="alertCount">0</span> alertas
                    </span>
                </div>
                <div class="panel-controls">
                    <button class="ctrl-btn active" data-chart="lineas" data-mode="actual">Carga Actual</button>
                    <button class="ctrl-btn" data-chart="lineas" data-mode="historico">Histórico</button>
                    <button class="ctrl-btn" data-chart="lineas" data-mode="oc">Por OC</button>
                </div>
            </div>
            <div class="chart-wrap" style="min-height:300px;"><canvas id="chartLineas"></canvas></div>
            <div class="chart-footer">
                <div class="legend-item"><span class="legend-dot" style="background:#f43f5e;"></span> Alta carga (&gt;70%)</div>
                <div class="legend-item"><span class="legend-dot" style="background:#f59e0b;"></span> Media (40–70%)</div>
                <div class="legend-item"><span class="legend-dot" style="background:#14b8a6;"></span> Baja (&lt;40%)</div>
                <div class="legend-item" style="margin-left:auto; color:#92400e; font-weight:600;"><span style="font-size:14px;">⚠️</span> Producción baja — requiere atención</div>
            </div>
        </div>
        <div class="charts-right-col">
            <div class="chart-panel chart-narrow">
                <div class="panel-header">
                    <div class="panel-title"><i class="bx bx-pie-chart-alt-2"></i> ESTADO DE OPs</div>
                </div>
                <div class="chart-wrap chart-donut-wrap" style="min-height:180px;">
                    <canvas id="chartDonut"></canvas>
                    <div class="donut-center" id="donutCenter">
                        <div class="donut-total"><?= $kpis['total_ops'] ?? 0 ?></div>
                        <div class="donut-label">OPs</div>
                    </div>
                </div>
                <div class="donut-legend" id="donutLegend"></div>
            </div>
            <div class="chart-panel chart-narrow gauge-panel">
                <div class="panel-header">
                    <div class="panel-title"><i class="bx bx-tachometer"></i> EFICIENCIA GLOBAL</div>
                </div>
                <div class="gauge-wrap">
                    <div class="gauge-canvas-container">
                        <canvas id="chartGauge"></canvas>
                        <div class="gauge-overlay">
                            <div class="gauge-pct" id="gaugePct"><?= number_format($eficiencia['promedio'] ?? 0, 1) ?>%</div>
                            <div class="gauge-label">Cumplimiento</div>
                        </div>
                    </div>
                    <div class="gauge-scale"><span>0%</span><span>50%</span><span>100%</span></div>
                    <div class="gauge-stats">
                        <div class="gs-item">
                            <span class="gs-val"><?= number_format($eficiencia['minimo'] ?? 0, 1) ?>%</span>
                            <span class="gs-lbl">Mín.</span>
                        </div>
                        <div class="gs-sep"></div>
                        <div class="gs-item">
                            <span class="gs-val"><?= number_format($eficiencia['sobre_meta'] ?? 0) ?></span>
                            <span class="gs-lbl">Sobre meta</span>
                        </div>
                        <div class="gs-sep"></div>
                        <div class="gs-item">
                            <span class="gs-val"><?= number_format($eficiencia['maximo'] ?? 0, 1) ?>%</span>
                            <span class="gs-lbl">Máx.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="charts-row-secondary">
        <div class="chart-panel">
            <div class="panel-header">
                <div class="panel-title"><i class="bx bx-buildings"></i> TOP CLIENTES POR VOLUMEN</div>
            </div>
            <div class="chart-wrap" style="min-height:240px;"><canvas id="chartClientes"></canvas></div>
        </div>
        <div class="chart-panel">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="bx bx-bar-chart-alt-2"></i> TENDENCIA MENSUAL
                    <span class="panel-badge">12 m</span>
                </div>
                <div class="panel-controls">
                    <button class="ctrl-btn active" data-chart="tendencia" data-mode="ordenes">OP</button>
                    <button class="ctrl-btn" data-chart="tendencia" data-mode="prendas">Prendas</button>
                </div>
            </div>
            <div class="chart-wrap" style="min-height:240px;"><canvas id="chartTendencia"></canvas></div>
        </div>
        <div class="chart-panel">
            <div class="panel-header">
                <div class="panel-title"><i class="bx bx-tag"></i> TOP ESTILOS</div>
            </div>
            <div class="chart-wrap" style="min-height:240px;"><canvas id="chartEstilos"></canvas></div>
        </div>
    </div>

    <div class="charts-row-bottom">
        <div class="chart-panel panel-lineas-status">
            <div class="panel-header">
                <div class="panel-title"><i class="bx bx-grid-alt"></i> ESTADO EN TIEMPO REAL DE LÍNEAS</div>
                <div style="display:flex;align-items:center;gap:6px;font-size:11px;color:#64748b;">
                    <i class="bx bx-circle" style="color:#22c55e;font-size:8px;"></i> Live
                </div>
            </div>
            <div class="lineas-heatmap" id="lineasHeatmap">
                <?php foreach ($carga_lineas as $l):
                    $ocupada  = $l['carga_actual'] > 0;
                    $estadoL  = $l['estado_linea'] === 'Activa' ? 'activa' : 'inactiva';
                    $cargaCls = $ocupada ? 'ocupada' : 'libre';
                    $ef_linea = 0;
                    foreach ($ef_por_linea as $efl) {
                        if ($efl['num_linea'] == $l['num_linea']) { $ef_linea = $efl['ef_promedio']; break; }
                    }
                ?>
                <div class="hm-cell <?= $estadoL ?> <?= $cargaCls ?>"
                     title="Línea <?= $l['num_linea'] ?> — <?= number_format($l['carga_actual']) ?> prendas · Ef. <?= $ef_linea ?>%">
                    <div class="hm-num"><?= $l['num_linea'] ?></div>
                    <?php if ($ocupada): ?>
                    <div class="hm-prendas"><?= number_format($l['carga_actual']) ?></div>
                    <div class="hm-ocs"><?= $l['ocs_activas'] ?> OC</div>
                    <?php if ($ef_linea > 0): ?>
                    <div class="hm-ef <?= $ef_linea >= 85 ? 'ef-ok' : ($ef_linea >= 60 ? 'ef-warn' : 'ef-low') ?>">
                        <?= $ef_linea ?>%
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="hm-libre"><i class="bx bx-check"></i></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="chart-panel panel-ops-table">
            <div class="panel-header">
                <div class="panel-title"><i class="bx bx-list-ul"></i> ÓRDENES DE PEDIDO RECIENTES</div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="panel-badge">últimas 10</span>
                    <button class="btn-icon-sm" id="btnExportTable" title="Exportar esta tabla"><i class="bx bx-export"></i></button>
                </div>
            </div>
            <div class="ops-table-wrap">
                <table class="ops-mini-table" id="opsTable">
                    <thead>
                        <tr><th>OP</th><th>Cliente</th><th>Estilo</th><th>Prendas</th><th>Ef.%</th><th>Fecha</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ops_recientes as $op): ?>
                        <tr>
                            <td><span class="op-id-badge">#<?= $op['id_op'] ?></span></td>
                            <td class="td-cliente"><?= htmlspecialchars($op['nombre_cliente']) ?></td>
                            <td><span class="badge-estilo-sm"><?= htmlspecialchars($op['estilo']) ?></span></td>
                            <td class="td-right"><?= number_format($op['cantidad_prendas']) ?></td>
                            <td class="td-right">
                                <?php
                                $tasa = $op['tasa_cumplimiento'];
                                $cls  = $tasa >= 85 ? 'tasa-ok' : ($tasa >= 60 ? 'tasa-warn' : 'tasa-low');
                                echo '<span class="tasa-badge '.$cls.'">'.number_format($tasa,1).'%</span>';
                                ?>
                            </td>
                            <td class="td-fecha"><?= $op['fecha_ingreso'] ?></td>
                            <td><span class="status-pill <?= strtolower($op['estado']) ?>"><?= $op['estado'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
window.DASH_DATA = {
    tendencia:   <?= jsJson($tendencia_mensual) ?>,
    topClientes: <?= jsJson($top_clientes) ?>,
    lineas:      <?= jsJson($distribucion_lineas) ?>,
    estadoOps:   <?= jsJson($estado_ops) ?>,
    topEstilos:  <?= jsJson($top_estilos) ?>,
    cargaLineas: <?= jsJson($carga_lineas) ?>,
    efLineas:    <?= jsJson($ef_por_linea) ?>,
    eficiencia:  <?= jsJson($eficiencia) ?>,
    kpis:        <?= jsJson($kpis) ?>
};
</script>
<script src="js/dashboard.js"></script>
</body>
</html>