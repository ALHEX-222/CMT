<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login/login.php");
    exit();
}

if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname=cmt_costura;charset=utf8mb4",
            'root', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error DB: ' . $e->getMessage()]);
        exit();
    }

    $fd = isset($_GET['fecha_desde']) ? trim($_GET['fecha_desde']) : '';
    $fh = isset($_GET['fecha_hasta']) ? trim($_GET['fecha_hasta']) : '';
    $ic = isset($_GET['id_cliente'])  ? trim($_GET['id_cliente'])  : '';

    $wc = []; $wp = [];
    if ($fd !== '') { $wc[] = "op.fecha_ingreso >= :fd"; $wp[':fd'] = $fd; }
    if ($fh !== '') { $wc[] = "op.fecha_ingreso <= :fh"; $wp[':fh'] = $fh; }
    if ($ic !== '') { $wc[] = "op.id_cliente = :ic";     $wp[':ic'] = (int)$ic; }
    $where = $wc ? 'WHERE ' . implode(' AND ', $wc) : '';
    $and   = $wc ? 'AND '   . implode(' AND ', $wc) : '';

    $s = $pdo->prepare("
        SELECT COUNT(*) as total_ops,
               COALESCE(SUM(cantidad_prendas),0) as total_prendas,
               COALESCE(AVG(tasa_cumplimiento),85.2) as tasa_promedio,
               SUM(CASE WHEN estado='Pendiente'  THEN 1 ELSE 0 END) as ops_pendientes,
               SUM(CASE WHEN estado='Completado' THEN 1 ELSE 0 END) as ops_completadas
        FROM orden_pedido op $where
    ");
    $s->execute($wp);
    $hist = $s->fetch();
    if ((int)($hist['total_ops']??0) === 0) {
        $wpSinFecha = $ic !== '' ? [':ic' => (int)$ic] : [];
        $whereSinFecha = $ic !== '' ? "WHERE op.id_cliente = :ic" : "";
        $s2 = $pdo->prepare("
            SELECT COUNT(*) as total_ops,
                   COALESCE(SUM(cantidad_prendas),0) as total_prendas,
                   COALESCE(AVG(tasa_cumplimiento),85.2) as tasa_promedio,
                   SUM(CASE WHEN estado='Pendiente'  THEN 1 ELSE 0 END) as ops_pendientes,
                   SUM(CASE WHEN estado='Completado' THEN 1 ELSE 0 END) as ops_completadas
            FROM orden_pedido op $whereSinFecha
        ");
        $s2->execute($wpSinFecha);
        $hist2 = $s2->fetch();
        if ((int)($hist2['total_ops']??0) > 0) $hist = $hist2;
    }

    $histWp = [];
    $histAnd = '';
    if ($ic !== '') { $histAnd = "AND id_cliente = :ic"; $histWp[':ic'] = (int)$ic; }

    $s = $pdo->prepare("
        SELECT DATE_FORMAT(fecha_ingreso,'%Y-%m') as mes,
               DATE_FORMAT(fecha_ingreso,'%b %Y') as mes_label,
               SUM(cantidad_prendas) as prendas,
               COUNT(*) as ops,
               COALESCE(AVG(tasa_cumplimiento),85) as ef_prom
        FROM orden_pedido
        WHERE fecha_ingreso >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        $histAnd
        GROUP BY mes ORDER BY mes ASC
    ");
    $s->execute($histWp);
    $historico = $s->fetchAll();
    if (!count($historico)) {
        $s2 = $pdo->prepare("
            SELECT DATE_FORMAT(fecha_ingreso,'%Y-%m') as mes,
                   DATE_FORMAT(fecha_ingreso,'%b %Y') as mes_label,
                   SUM(cantidad_prendas) as prendas,
                   COUNT(*) as ops,
                   COALESCE(AVG(tasa_cumplimiento),85) as ef_prom
            FROM orden_pedido
            WHERE fecha_ingreso >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
            $histAnd
            GROUP BY mes ORDER BY mes ASC
        ");
        $s2->execute($histWp);
        $historico_amp = $s2->fetchAll();
        if ($historico_amp) $historico = $historico_amp;
    }

    $crecimiento = 1.08;
    $crec_ef     = 1.0;
    if (count($historico) >= 2) {
        $tasas = []; $tasas_ef = [];
        for ($i = 1; $i < count($historico); $i++) {
            if ($historico[$i-1]['prendas'] > 0)
                $tasas[] = $historico[$i]['prendas'] / $historico[$i-1]['prendas'];
            if ($historico[$i-1]['ef_prom'] > 0)
                $tasas_ef[] = $historico[$i]['ef_prom'] / $historico[$i-1]['ef_prom'];
        }
        if ($tasas)    $crecimiento = array_sum($tasas)    / count($tasas);
        if ($tasas_ef) $crec_ef     = array_sum($tasas_ef) / count($tasas_ef);
    }
    $crecimiento = max(0.5, min($crecimiento, 3.0));
    $crec_ef     = max(0.85, min($crec_ef, 1.15));

    $prendas_mes = count($historico) ? (float)end($historico)['prendas'] : max(1,(float)($hist['total_prendas']??0)/6);
    $ops_mes     = count($historico) ? (float)end($historico)['ops']     : max(1,(float)($hist['total_ops']??0)/6);
    $ef_ultimo   = count($historico) ? (float)end($historico)['ef_prom'] : (float)($hist['tasa_promedio']??85);

    if ($prendas_mes == 0 && (float)($hist['total_prendas']??0) > 0) {
        $meses_rango = 1;
        if ($fd && $fh) {
            $diff = (strtotime($fh) - strtotime($fd)) / (30 * 86400);
            $meses_rango = max(1, round($diff));
        }
        $prendas_mes = (float)$hist['total_prendas'] / $meses_rango;
        $ops_mes     = (float)$hist['total_ops']     / $meses_rango;
    }

    $proyeccion = [];
    for ($i = 1; $i <= 6; $i++) {
        $ef_proy = min(100, $ef_ultimo * pow($crec_ef, $i));
        $proyeccion[] = [
            'mes'       => date('Y-m', strtotime("+$i months")),
            'mes_label' => date('M Y', strtotime("+$i months")),
            'prendas'   => round($prendas_mes * pow($crecimiento, $i)),
            'ops'       => max(1, round($ops_mes * pow($crecimiento, $i))),
            'optimista' => round($prendas_mes * pow($crecimiento * 1.18, $i)),
            'pesimista' => round($prendas_mes * pow($crecimiento * 0.95, $i)),
            'ef_proy'   => round($ef_proy, 1),
            'tipo'      => 'proyeccion',
        ];
    }

    $ef_hist_short = array_slice($historico, -6);
    $ef_proyectada = [];
    foreach ($ef_hist_short as $r) {
        $ef_proyectada[] = [
            'mes'      => $r['mes'],
            'mes_label'=> $r['mes_label'],
            'ef'       => round((float)$r['ef_prom'], 1),
            'tipo'     => 'historico',
        ];
    }
    foreach ($proyeccion as $p) {
        $ef_proyectada[] = [
            'mes'       => $p['mes'],
            'mes_label' => $p['mes_label'],
            'ef'        => $p['ef_proy'],
            'tipo'      => 'proyeccion',
        ];
    }

    $clienteWhere = $ic !== '' ? "WHERE c.id_cliente = :ic" : "";
    $clienteWp    = $ic !== '' ? [':ic' => (int)$ic] : [];

    $s = $pdo->prepare("
        SELECT c.id_cliente, c.nombre_cliente,
               COUNT(op.id_op) as ops_hist,
               COALESCE(SUM(op.cantidad_prendas),0) as prendas_hist,
               COALESCE(AVG(op.tasa_cumplimiento),85) as ef_prom,
               COUNT(DISTINCT DATE_FORMAT(op.fecha_ingreso,'%Y-%m')) as meses_activos
        FROM cliente c
        LEFT JOIN orden_pedido op ON op.id_cliente=c.id_cliente
            AND op.fecha_ingreso >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        $clienteWhere
        GROUP BY c.id_cliente
        HAVING prendas_hist > 0
        ORDER BY prendas_hist DESC LIMIT 6
    ");
    $s->execute($clienteWp);
    $clientes_raw = $s->fetchAll();

    $demanda_clientes = array_map(function($c) use ($crecimiento) {
        $meses = max(1, (int)$c['meses_activos']);
        $prom_mensual = (float)$c['prendas_hist'] / $meses;
        return [
            'nombre_cliente'    => $c['nombre_cliente'],
            'prendas_historico' => (int)$c['prendas_hist'],
            'prendas_proy_3m'   => round($prom_mensual * 3 * $crecimiento),
            'ops_proy'          => round((int)$c['ops_hist'] / $meses * 3 * $crecimiento),
            'ef_prom'           => round((float)$c['ef_prom'], 1),
            'riesgo'            => (float)$c['ef_prom'] < 70 ? 'alto' : ((float)$c['ef_prom'] < 85 ? 'medio' : 'bajo'),
        ];
    }, $clientes_raw);

    $s = $pdo->query("
        SELECT l.num_linea, l.estado, l.num_operarios,
               COALESCE(SUM(oc.cantidad),0) as carga_actual,
               COUNT(DISTINCT oc.id_oc) as ocs_activas,
               COALESCE(AVG(op.tasa_cumplimiento),85) as ef_linea
        FROM linea l
        LEFT JOIN orden_corte oc ON oc.id_linea=l.id_linea
        LEFT JOIN orden_pedido op ON op.id_op=oc.id_op AND op.estado='Pendiente'
        GROUP BY l.id_linea ORDER BY l.num_linea
    ");
    $lineas_raw = $s->fetchAll();

    $carga_proyectada_lineas = array_map(function($l) use ($crecimiento) {
        $carga_act = (int)$l['carga_actual'];
        $ef        = (float)$l['ef_linea'];
        $carga_proy = round($carga_act * $crecimiento);
        $riesgo_score = 0;
        if ($ef < 70)           $riesgo_score += 2;
        elseif ($ef < 85)       $riesgo_score += 1;
        if ($carga_proy > 8000) $riesgo_score += 2;
        elseif ($carga_proy > 4000) $riesgo_score += 1;
        $nivel_riesgo = $riesgo_score >= 3 ? 'crítico' : ($riesgo_score >= 2 ? 'alto' : ($riesgo_score >= 1 ? 'medio' : 'bajo'));
        return [
            'num_linea'    => $l['num_linea'],
            'carga_actual' => $carga_act,
            'carga_proy'   => $carga_proy,
            'ef_linea'     => round($ef, 1),
            'nivel_riesgo' => $nivel_riesgo,
            'ocs_activas'  => (int)$l['ocs_activas'],
        ];
    }, $lineas_raw);

    $estiloClienteAnd = $ic !== '' ? "AND op.id_cliente = :ic_est" : "";
    $estiloWp         = $ic !== '' ? [':ic_est' => (int)$ic] : [];

    $s = $pdo->prepare("
        SELECT estilo,
               SUM(CASE WHEN fecha_ingreso >= DATE_SUB(CURDATE(),INTERVAL 3 MONTH)
                   THEN cantidad_prendas ELSE 0 END) as reciente,
               SUM(CASE WHEN fecha_ingreso <  DATE_SUB(CURDATE(),INTERVAL 3 MONTH)
                         AND fecha_ingreso >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
                   THEN cantidad_prendas ELSE 0 END) as anterior,
               COUNT(*) as ops_total,
               SUM(cantidad_prendas) as total_hist
        FROM orden_pedido op
        WHERE fecha_ingreso >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        $estiloClienteAnd
        GROUP BY estilo
        HAVING total_hist > 0
        ORDER BY total_hist DESC LIMIT 8
    ");
    $s->execute($estiloWp);
    $estilos_raw = $s->fetchAll();

    $estilos_proyectados = array_map(function($e) use ($crecimiento) {
        $rec  = (float)$e['reciente'];
        $ant  = (float)$e['anterior'];
        $tendencia_ratio = $ant > 0 ? $rec / $ant : 1.0;
        $tendencia_ratio = max(0.5, min($tendencia_ratio, 3.0));
        if ($rec == 0 && (float)$e['total_hist'] > 0) $rec = (float)$e['total_hist'] / 2;
        $proy_3m = round($rec * $tendencia_ratio * $crecimiento);
        $var_pct = $ant > 0 ? round(($rec - $ant) / $ant * 100, 1) : 0;
        return [
            'estilo'      => $e['estilo'],
            'ops'         => (int)$e['ops_total'],
            'total_hist'  => (int)$e['total_hist'],
            'reciente'    => (int)$rec,
            'proy_3m'     => $proy_3m,
            'variacion'   => $var_pct,
            'tendencia'   => $var_pct > 5 ? 'sube' : ($var_pct < -5 ? 'baja' : 'estable'),
        ];
    }, $estilos_raw);
    usort($estilos_proyectados, fn($a,$b) => $b['proy_3m'] - $a['proy_3m']);

    $alertas = [];
    foreach ($carga_proyectada_lineas as $l) {
        if ($l['nivel_riesgo'] === 'crítico')
            $alertas[] = ['tipo'=>'critico','icono'=>'bx-error','msg'=>"Línea {$l['num_linea']}: riesgo crítico — eficiencia {$l['ef_linea']}% con carga proyectada de ".number_format($l['carga_proy'])." prendas"];
        elseif ($l['nivel_riesgo'] === 'alto')
            $alertas[] = ['tipo'=>'alto','icono'=>'bx-error-circle','msg'=>"Línea {$l['num_linea']}: carga proyectada alta (".number_format($l['carga_proy'])." prendas), considerar redistribución"];
    }
    foreach ($estilos_proyectados as $e) {
        if ($e['tendencia'] === 'sube' && $e['proy_3m'] > 5000)
            $alertas[] = ['tipo'=>'info','icono'=>'bx-trending-up','msg'=>"Estilo {$e['estilo']}: demanda proyectada +".abs($e['variacion'])."% — preparar capacidad (".number_format($e['proy_3m'])." prendas estimadas)"];
    }
    if ((float)($hist['tasa_promedio']??85) < 75)
        $alertas[] = ['tipo'=>'alto','icono'=>'bx-time-five','msg'=>"Tasa de cumplimiento por debajo del 75% — alto riesgo de incumplimiento en próximos pedidos"];

    $prendas_3 = array_sum(array_column(array_slice($proyeccion,0,3),'prendas'));
    $prendas_6 = array_sum(array_column($proyeccion,'prendas'));
    $ops_3     = array_sum(array_column(array_slice($proyeccion,0,3),'ops'));

    $ef_base      = round((float)($hist['tasa_promedio']??85), 1);
    $lineas_riesgo = count(array_filter($carga_proyectada_lineas, fn($l) => in_array($l['nivel_riesgo'],['crítico','alto'])));
    $riesgo_global = $ef_base < 70 ? 'alto' : ($ef_base < 85 ? 'moderado' : 'bajo');

    $tendencia_final = array_merge(
        array_map(fn($r) => [...$r, 'tipo'=>'historico','optimista'=>null,'pesimista'=>null], $historico),
        $proyeccion
    );

    $s = $pdo->query("SELECT id_cliente, nombre_cliente FROM cliente ORDER BY nombre_cliente");
    $clientes_select = $s->fetchAll();

    echo json_encode([
        'success' => true,
        'predicciones' => [
            'total_prendas_3meses'  => round($prendas_3),
            'total_prendas_6meses'  => round($prendas_6),
            'total_ops_3meses'      => (int)$ops_3,
            'tasa_cumplimiento'     => $ef_base,
            'ops_pendientes'        => (int)($hist['ops_pendientes']??0),
            'ops_completadas'       => (int)($hist['ops_completadas']??0),
            'crecimiento_mensual'   => round(($crecimiento - 1)*100, 1),
            'riesgo_global'         => $riesgo_global,
            'lineas_en_riesgo'      => $lineas_riesgo,
        ],
        'tendencia'              => $tendencia_final,
        'ef_proyectada'          => $ef_proyectada,
        'carga_proyectada_lineas'=> $carga_proyectada_lineas,
        'demanda_clientes'       => $demanda_clientes,
        'estilos_proyectados'    => $estilos_proyectados,
        'alertas'                => array_slice($alertas, 0, 5),
        'clientes'               => $clientes_select,
        'fecha'                  => date('d/m/Y H:i'),
        'generado_en'            => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Predicciones — CMT Del Sur</title>
    <link rel="stylesheet" href="css/prediccion.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
</head>
<body>
<div class="pred-wrap">

    <div class="pred-topbar">
        <div class="pred-title-block">
            <div class="pred-eyebrow">
                <span class="eyebrow-dot"></span>
                Motor de predicción — CMT del Sur
            </div>
            <h1 class="pred-title">Análisis <span class="accent">predictivo</span></h1>
            <p class="pred-subtitle">Proyecciones de demanda, riesgo y capacidad para los próximos 6 meses</p>
        </div>
        <div class="pred-actions-col">
            <div class="pred-controls">
                <div class="ctrl-group">
                    <label>Desde</label>
                    <input type="date" id="fecha_desde" value="<?= date('Y-m-01') ?>">
                </div>
                <div class="ctrl-group">
                    <label>Hasta</label>
                    <input type="date" id="fecha_hasta" value="<?= date('Y-m-t') ?>">
                </div>
                <div class="ctrl-group">
                    <label>Cliente</label>
                    <select id="id_cliente"><option value="">Todos los clientes</option></select>
                </div>
                <button class="btn-run" onclick="cargarPredicciones()">
                    <i class="bx bx-refresh"></i> Ejecutar
                </button>
            </div>
            <div class="top-btn-row">
                <button class="btn-export" onclick="exportarExcel()">
                    <i class="bx bx-download"></i> Exportar Excel
                </button>
                <div class="auto-badge" id="autoBadge">
                    <span class="auto-dot"></span>
                    Auto-refresh: <span id="countdown">5:00</span>
                </div>
            </div>
        </div>
    </div>

    <div class="kpi-row">
        <div class="kpi-card kpi-primary">
            <div class="kpi-icon-wrap ic-cyan"><i class="bx bx-rocket"></i></div>
            <div class="kpi-body">
                <span class="kpi-label">Producción proyectada · 3 meses</span>
                <span class="kpi-value" id="pred_prendas">—</span>
                <span class="kpi-sub">prendas estimadas</span>
            </div>
            <div class="kpi-delta" id="kpi_crecimiento">—</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon-wrap ic-vio"><i class="bx bx-calendar-plus"></i></div>
            <div class="kpi-body">
                <span class="kpi-label">Producción proyectada · 6 meses</span>
                <span class="kpi-value" id="pred_prendas6">—</span>
                <span class="kpi-sub">prendas estimadas</span>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-icon-wrap ic-green"><i class="bx bx-layer-plus"></i></div>
            <div class="kpi-body">
                <span class="kpi-label">Órdenes esperadas</span>
                <span class="kpi-value" id="pred_ops">—</span>
                <span class="kpi-sub">próximos 3 meses</span>
            </div>
        </div>
        <div class="kpi-card" id="kpi_riesgo_card">
            <div class="kpi-icon-wrap ic-amber"><i class="bx bx-shield-quarter"></i></div>
            <div class="kpi-body">
                <span class="kpi-label">Riesgo de incumplimiento</span>
                <span class="kpi-value" id="pred_riesgo">—</span>
                <span class="kpi-sub" id="pred_riesgo_sub">líneas en zona de riesgo</span>
            </div>
        </div>
        <div class="kpi-card escenarios-card">
            <span class="kpi-label">Escenarios de demanda proyectada</span>
            <div class="esc-row">
                <div class="esc optimista">
                    <i class="bx bx-up-arrow-alt"></i>
                    <span class="esc-tag">Optimista</span>
                    <span class="esc-val">+18%</span>
                </div>
                <div class="esc base">
                    <i class="bx bx-minus"></i>
                    <span class="esc-tag">Base</span>
                    <span class="esc-val">+7%</span>
                </div>
                <div class="esc pesimista">
                    <i class="bx bx-down-arrow-alt"></i>
                    <span class="esc-tag">Pesimista</span>
                    <span class="esc-val">−5%</span>
                </div>
            </div>
        </div>
    </div>

    <div class="section-label">Alertas predictivas</div>
    <div id="alertasContainer" class="alertas-wrap"></div>

    <div class="section-label">Proyección de producción y eficiencia</div>
    <div class="charts-row-main">
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <h3>Proyección de producción — próximos 6 meses</h3>
                    <p class="chart-desc">Histórico real + curvas de proyección base, optimista y pesimista</p>
                </div>
                <div class="chart-header-right">
                    <div class="legend-pills">
                        <span class="pill"><span class="pill-dot" style="background:#2563eb"></span>Histórico</span>
                        <span class="pill"><span class="pill-dot" style="background:#9333ea"></span>Proyección base</span>
                        <span class="pill"><span class="pill-dot" style="background:#16a34a"></span>Optimista</span>
                        <span class="pill"><span class="pill-dot" style="background:#dc2626"></span>Pesimista</span>
                    </div>
                </div>
            </div>
            <canvas id="chartTendencia" height="90"></canvas>
        </div>
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <h3>Eficiencia proyectada</h3>
                    <p class="chart-desc">Tendencia de cumplimiento hacia el futuro</p>
                </div>
            </div>
            <canvas id="chartEfProyectada" height="200"></canvas>
        </div>
    </div>

    <div class="section-label">Riesgo y carga proyectada por línea</div>
    <div class="charts-row-secondary">
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <h3>Carga proyectada por línea</h3>
                    <p class="chart-desc">Estimado próximo mes según tendencia actual</p>
                </div>
            </div>
            <canvas id="chartCargaProy" height="200"></canvas>
        </div>
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <h3>Mapa de riesgo por línea</h3>
                    <p class="chart-desc">Cruce de eficiencia vs carga proyectada</p>
                </div>
            </div>
            <canvas id="chartRiesgoLineas" height="200"></canvas>
        </div>
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <h3>Demanda proyectada por cliente</h3>
                    <p class="chart-desc">Estimado próximos 3 meses por cliente</p>
                </div>
            </div>
            <canvas id="chartDemandaClientes" height="200"></canvas>
        </div>
    </div>

    <div class="section-label">Demanda proyectada por estilo</div>
    <div class="charts-row-bottom">
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <h3>Estilos con mayor demanda proyectada</h3>
                    <p class="chart-desc">Ranking por volumen estimado próximos 3 meses · ↑↓ variación vs período anterior</p>
                </div>
            </div>
            <div id="topEstilosPred" class="estilos-list"></div>
        </div>
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <h3>Distribución de demanda futura</h3>
                    <p class="chart-desc">Proporción estimada por estilo</p>
                </div>
            </div>
            <canvas id="chartEstilosPie" height="220"></canvas>
        </div>
    </div>

    <div class="section-label">Resumen ejecutivo</div>
    <div class="resumen-card" id="resumenCard">
        <div class="resumen-icon"><i class="bx bx-analyse"></i></div>
        <div class="resumen-body">
            <h3>Síntesis generada por el motor predictivo</h3>
            <p id="resumenTexto">Procesando datos de producción...</p>
        </div>
        <div class="resumen-stamp" id="resumenFecha"></div>
    </div>

    <div class="pred-footer">
        <span id="pred_footer"></span>
        <button class="btn-export-sm" onclick="exportarExcel()">
            <i class="bx bx-spreadsheet"></i> Exportar reporte completo
        </button>
    </div>

</div>

<div class="loader-overlay" id="loader">
    <div class="loader-ring"></div>
    <span class="loader-text">PROCESANDO PREDICCIONES...</span>
</div>

<script src="js/prediccion.js"></script>
</body>
</html>