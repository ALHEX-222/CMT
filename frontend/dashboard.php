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

$scriptPy = __DIR__ . '/../backend/dashboard.py';
$cmd = 'python ' . escapeshellarg($scriptPy);
if ($fecha_desde) $cmd .= ' --fecha_desde ' . escapeshellarg($fecha_desde);
if ($fecha_hasta) $cmd .= ' --fecha_hasta ' . escapeshellarg($fecha_hasta);
if ($id_cliente)  $cmd .= ' --id_cliente '  . escapeshellarg($id_cliente);
if ($estado_fil)  $cmd .= ' --estado '      . escapeshellarg($estado_fil);
$cmd .= ' 2>&1';

$output = shell_exec($cmd);
$data   = json_decode($output, true);

if (!$data || !($data['success'] ?? false)) {
    $error = $data['message'] ?? 'No se pudo cargar el dashboard.';
    $data  = [];
}

function jsJson($v) {
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

$kpis               = $data['kpis']               ?? [];
$tendencia_mensual  = $data['tendencia_mensual']   ?? [];
$top_clientes       = $data['top_clientes']        ?? [];
$distribucion_lineas= $data['distribucion_lineas'] ?? [];
$estado_ops         = $data['estado_ops']          ?? [];
$top_estilos        = $data['top_estilos']         ?? [];
$ops_recientes      = $data['ops_recientes']       ?? [];
$carga_lineas       = $data['carga_lineas']        ?? [];
$prendas_por_mes    = $data['prendas_por_mes']     ?? [];
$eficiencia         = $data['eficiencia']          ?? [];
$clientes_lista     = $data['clientes_lista']      ?? [];
$ef_por_linea       = $data['ef_por_linea']        ?? [];

$cliente_sel_nombre = '';
if ($id_cliente) {
    foreach ($clientes_lista as $cl) {
        if ($cl['id_cliente'] == $id_cliente) {
            $cliente_sel_nombre = $cl['nombre_cliente'];
            break;
        }
    }
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
            <h1 class="dash-title">
                <i class="bx bx-pulse"></i>
                PANEL DE CONTROL — CMT
            </h1>
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
            <button class="btn-icon" id="btnExport" title="Exportar Excel">
                <i class="bx bx-download"></i>
            </button>
            <button class="btn-icon" id="btnRefresh" title="Actualizar datos">
                <i class="bx bx-refresh"></i>
            </button>
        </div>
    </div>

    <div class="filter-panel" id="filterPanel" <?php if($hay_filtros) echo 'style="display:block;"'; ?>>
        <form method="GET" class="filter-form" id="filterForm">
            <div class="filter-group">
                <label class="filter-label"><i class="bx bx-calendar"></i> Desde</label>
                <input type="date" name="fecha_desde" class="filter-input"
                       value="<?php echo htmlspecialchars($fecha_desde); ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bx bx-calendar-check"></i> Hasta</label>
                <input type="date" name="fecha_hasta" class="filter-input"
                       value="<?php echo htmlspecialchars($fecha_hasta); ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bx bx-buildings"></i> Cliente</label>
                <select name="id_cliente" class="filter-input">
                    <option value="">Todos los clientes</option>
                    <?php foreach ($clientes_lista as $cl): ?>
                    <option value="<?php echo $cl['id_cliente']; ?>"
                        <?php if ($id_cliente == $cl['id_cliente']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($cl['nombre_cliente']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label"><i class="bx bx-tag"></i> Estado</label>
                <select name="estado_fil" class="filter-input">
                    <option value="">Todos</option>
                    <option value="Pendiente"  <?php if($estado_fil==='Pendiente')  echo 'selected'; ?>>Pendiente</option>
                    <option value="Completado" <?php if($estado_fil==='Completado') echo 'selected'; ?>>Completado</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-filter-apply">
                    <i class="bx bx-search"></i> Aplicar
                </button>
                <a href="?" class="btn-filter-clear">
                    <i class="bx bx-x"></i> Limpiar
                </a>
            </div>
        </form>

        <?php if ($hay_filtros): ?>
        <div class="filter-chips">
            <span class="chip-label">Filtros activos:</span>
            <?php if ($fecha_desde): ?>
                <span class="filter-chip">Desde: <?php echo $fecha_desde; ?></span>
            <?php endif; ?>
            <?php if ($fecha_hasta): ?>
                <span class="filter-chip">Hasta: <?php echo $fecha_hasta; ?></span>
            <?php endif; ?>
            <?php if ($cliente_sel_nombre): ?>
                <span class="filter-chip"><?php echo htmlspecialchars($cliente_sel_nombre); ?></span>
            <?php endif; ?>
            <?php if ($estado_fil): ?>
                <span class="filter-chip"><?php echo $estado_fil; ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($error)): ?>
    <div class="dash-error-banner">
        <i class="bx bx-error-circle"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <div class="kpi-row">

        <div class="kpi-tile kpi-blue">
            <div class="kpi-tile-icon"><i class="bx bx-cart-alt"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?php echo $kpis['total_ops'] ?? 0; ?>">0</div>
                <div class="kpi-tile-label">Órdenes Totales</div>
            </div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-cart-alt"></i></div>
        </div>

        <div class="kpi-tile kpi-amber">
            <div class="kpi-tile-icon"><i class="bx bx-time"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?php echo $kpis['ops_pendientes'] ?? 0; ?>">0</div>
                <div class="kpi-tile-label">En Proceso</div>
            </div>
            <div class="kpi-tile-sub">
                <?php $pct_pend = ($kpis['total_ops'] ?? 0) > 0 ? round(($kpis['ops_pendientes'] ?? 0) / $kpis['total_ops'] * 100) : 0; ?>
                <?php echo $pct_pend; ?>% del total
            </div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-time"></i></div>
        </div>

        <div class="kpi-tile kpi-green">
            <div class="kpi-tile-icon"><i class="bx bx-check-circle"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?php echo $kpis['ops_completadas'] ?? 0; ?>">0</div>
                <div class="kpi-tile-label">Completadas</div>
            </div>
            <div class="kpi-tile-sub">
                <?php $pct_comp = ($kpis['total_ops'] ?? 0) > 0 ? round(($kpis['ops_completadas'] ?? 0) / $kpis['total_ops'] * 100) : 0; ?>
                <?php echo $pct_comp; ?>% completado
            </div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-check-circle"></i></div>
        </div>

        <div class="kpi-tile kpi-indigo">
            <div class="kpi-tile-icon"><i class="bx bx-t-shirt"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?php echo intval($kpis['total_prendas'] ?? 0); ?>">0</div>
                <div class="kpi-tile-label">Total Prendas</div>
            </div>
            <div class="kpi-tile-sub">
                <?php echo number_format($kpis['prendas_en_proceso'] ?? 0); ?> en proceso
            </div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-t-shirt"></i></div>
        </div>

        <div class="kpi-tile kpi-teal">
            <div class="kpi-tile-icon"><i class="bx bx-buildings"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?php echo $kpis['total_clientes'] ?? 0; ?>">0</div>
                <div class="kpi-tile-label">Clientes</div>
            </div>
            <div class="kpi-tile-sub"><?php echo $kpis['total_oc'] ?? 0; ?> órdenes de corte</div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-buildings"></i></div>
        </div>

        <div class="kpi-tile kpi-rose">
            <div class="kpi-tile-icon"><i class="bx bx-git-branch"></i></div>
            <div class="kpi-tile-body">
                <div class="kpi-tile-val" data-count="<?php echo $kpis['lineas_activas'] ?? 0; ?>">0</div>
                <div class="kpi-tile-label">Líneas Activas</div>
            </div>
            <div class="kpi-tile-sub">de <?php echo $kpis['total_lineas'] ?? 0; ?> en total</div>
            <div class="kpi-tile-bg-icon"><i class="bx bx-git-branch"></i></div>
        </div>

    </div>

    <div class="charts-row-main">

        <div class="chart-panel chart-wide">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="bx bx-git-branch"></i>
                    CARGA POR LÍNEA DE PRODUCCIÓN
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
            <div class="chart-wrap" style="min-height:300px;">
                <canvas id="chartLineas"></canvas>
            </div>
            <div class="chart-footer">
                <div class="legend-item">
                    <span class="legend-dot" style="background:#f43f5e;"></span> Alta carga (&gt;70%)
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background:#f59e0b;"></span> Media (40–70%)
                </div>
                <div class="legend-item">
                    <span class="legend-dot" style="background:#14b8a6;"></span> Baja (&lt;40%)
                </div>
                <div class="legend-item" style="margin-left:auto; color:#92400e; font-weight:600;">
                    <span style="font-size:14px;">⚠️</span> Producción baja — requiere atención
                </div>
            </div>
        </div>

        <div class="charts-right-col">

            <div class="chart-panel chart-narrow">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="bx bx-pie-chart-alt-2"></i>
                        ESTADO DE OPs
                    </div>
                </div>
                <div class="chart-wrap chart-donut-wrap" style="min-height:180px;">
                    <canvas id="chartDonut"></canvas>
                    <div class="donut-center" id="donutCenter">
                        <div class="donut-total"><?php echo $kpis['total_ops'] ?? 0; ?></div>
                        <div class="donut-label">OPs</div>
                    </div>
                </div>
                <div class="donut-legend" id="donutLegend"></div>
            </div>

            <div class="chart-panel chart-narrow gauge-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="bx bx-tachometer"></i>
                        EFICIENCIA GLOBAL
                    </div>
                </div>
                <div class="gauge-wrap">
                    <div class="gauge-canvas-container">
                        <canvas id="chartGauge"></canvas>
                        <div class="gauge-overlay">
                            <div class="gauge-pct" id="gaugePct">
                                <?php echo number_format($eficiencia['promedio'] ?? 0, 1); ?>%
                            </div>
                            <div class="gauge-label">Cumplimiento</div>
                        </div>
                    </div>
                    <div class="gauge-scale">
                        <span>0%</span>
                        <span>50%</span>
                        <span>100%</span>
                    </div>
                    <div class="gauge-stats">
                        <div class="gs-item">
                            <span class="gs-val"><?php echo number_format($eficiencia['minimo'] ?? 0, 1); ?>%</span>
                            <span class="gs-lbl">Mín.</span>
                        </div>
                        <div class="gs-sep"></div>
                        <div class="gs-item">
                            <span class="gs-val"><?php echo number_format($eficiencia['sobre_meta'] ?? 0); ?></span>
                            <span class="gs-lbl">Sobre meta</span>
                        </div>
                        <div class="gs-sep"></div>
                        <div class="gs-item">
                            <span class="gs-val"><?php echo number_format($eficiencia['maximo'] ?? 0, 1); ?>%</span>
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
                <div class="panel-title">
                    <i class="bx bx-buildings"></i>
                    TOP CLIENTES POR VOLUMEN
                </div>
            </div>
            <div class="chart-wrap" style="min-height:240px;">
                <canvas id="chartClientes"></canvas>
            </div>
        </div>

        <div class="chart-panel">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="bx bx-bar-chart-alt-2"></i>
                    TENDENCIA MENSUAL
                    <span class="panel-badge">12 m</span>
                </div>
                <div class="panel-controls">
                    <button class="ctrl-btn active" data-chart="tendencia" data-mode="ordenes">OP</button>
                    <button class="ctrl-btn" data-chart="tendencia" data-mode="prendas">Prendas</button>
                </div>
            </div>
            <div class="chart-wrap" style="min-height:240px;">
                <canvas id="chartTendencia"></canvas>
            </div>
        </div>

        <div class="chart-panel">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="bx bx-tag"></i>
                    TOP ESTILOS
                </div>
            </div>
            <div class="chart-wrap" style="min-height:240px;">
                <canvas id="chartEstilos"></canvas>
            </div>
        </div>

    </div>

    <div class="charts-row-bottom">

        <div class="chart-panel panel-lineas-status">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="bx bx-grid-alt"></i>
                    ESTADO EN TIEMPO REAL DE LÍNEAS
                </div>
                <div style="display:flex; align-items:center; gap:6px; font-size:11px; color:#64748b;">
                    <i class="bx bx-circle" style="color:#22c55e; font-size:8px;"></i> Live
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
                <div class="hm-cell <?php echo $estadoL; ?> <?php echo $cargaCls; ?>"
                     title="Línea <?php echo $l['num_linea']; ?> — <?php echo number_format($l['carga_actual']); ?> prendas · Ef. <?php echo $ef_linea; ?>%">
                    <div class="hm-num"><?php echo $l['num_linea']; ?></div>
                    <?php if ($ocupada): ?>
                    <div class="hm-prendas"><?php echo number_format($l['carga_actual']); ?></div>
                    <div class="hm-ocs"><?php echo $l['ocs_activas']; ?> OC</div>
                    <?php if ($ef_linea > 0): ?>
                    <div class="hm-ef <?php echo $ef_linea >= 85 ? 'ef-ok' : ($ef_linea >= 60 ? 'ef-warn' : 'ef-low'); ?>">
                        <?php echo $ef_linea; ?>%
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
                <div class="panel-title">
                    <i class="bx bx-list-ul"></i>
                    ÓRDENES DE PEDIDO RECIENTES
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span class="panel-badge">últimas 10</span>
                    <button class="btn-icon-sm" id="btnExportTable" title="Exportar esta tabla">
                        <i class="bx bx-export"></i>
                    </button>
                </div>
            </div>
            <div class="ops-table-wrap">
                <table class="ops-mini-table" id="opsTable">
                    <thead>
                        <tr>
                            <th>OP</th>
                            <th>Cliente</th>
                            <th>Estilo</th>
                            <th>Prendas</th>
                            <th>Ef.%</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ops_recientes as $op): ?>
                        <tr>
                            <td><span class="op-id-badge">#<?php echo $op['id_op']; ?></span></td>
                            <td class="td-cliente"><?php echo htmlspecialchars($op['nombre_cliente']); ?></td>
                            <td><span class="badge-estilo-sm"><?php echo htmlspecialchars($op['estilo']); ?></span></td>
                            <td class="td-right"><?php echo number_format($op['cantidad_prendas']); ?></td>
                            <td class="td-right">
                                <?php
                                $tasa = $op['tasa_cumplimiento'];
                                $cls  = $tasa >= 85 ? 'tasa-ok' : ($tasa >= 60 ? 'tasa-warn' : 'tasa-low');
                                echo '<span class="tasa-badge '.$cls.'">'.number_format($tasa,1).'%</span>';
                                ?>
                            </td>
                            <td class="td-fecha"><?php echo $op['fecha_ingreso']; ?></td>
                            <td>
                                <span class="status-pill <?php echo strtolower($op['estado']); ?>">
                                    <?php echo $op['estado']; ?>
                                </span>
                            </td>
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
    tendencia:   <?php echo jsJson($tendencia_mensual); ?>,
    topClientes: <?php echo jsJson($top_clientes); ?>,
    lineas:      <?php echo jsJson($distribucion_lineas); ?>,
    estadoOps:   <?php echo jsJson($estado_ops); ?>,
    topEstilos:  <?php echo jsJson($top_estilos); ?>,
    cargaLineas: <?php echo jsJson($carga_lineas); ?>,
    efLineas:    <?php echo jsJson($ef_por_linea); ?>,
    eficiencia:  <?php echo jsJson($eficiencia); ?>,
    kpis:        <?php echo jsJson($kpis); ?>
};
</script>
<script src="js/dashboard.js"></script>
</body>
</html>