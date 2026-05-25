<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login/login.php");
    exit();
}

require_once "../database/config.php";

if (isset($_GET['action']) && $_GET['action'] === 'get_linea_detalle') {
    header('Content-Type: application/json');
    $id_linea = isset($_GET['id_linea']) ? intval($_GET['id_linea']) : 0;
    if ($id_linea <= 0) {
        echo json_encode(["success" => false, "message" => "ID inválido."]);
        exit();
    }

    /** @var mysqli $conn */

    $stmt = mysqli_prepare($conn,
        "SELECT id_linea, estado, num_operarios, num_linea FROM linea WHERE id_linea = ?"
    );
    mysqli_stmt_bind_param($stmt, "i", $id_linea);
    mysqli_stmt_execute($stmt);
    $linea = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$linea) {
        echo json_encode(["success" => false, "message" => "Línea no encontrada."]);
        exit();
    }

    $stmt2 = mysqli_prepare($conn,
        "SELECT oc.id_oc, oc.id_op, oc.cantidad, oc.fecha_corte, oc.observacion,
                op.descripcion, op.estilo, op.estado AS estado_op,
                c.nombre_cliente
         FROM orden_corte oc
         INNER JOIN orden_pedido op ON oc.id_op = op.id_op
         INNER JOIN cliente c ON op.id_cliente = c.id_cliente
         WHERE oc.id_linea = ?
         ORDER BY oc.fecha_corte ASC"
    );
    mysqli_stmt_bind_param($stmt2, "i", $id_linea);
    mysqli_stmt_execute($stmt2);
    $res2 = mysqli_stmt_get_result($stmt2);
    $ocs_activas = [];
    while ($row = mysqli_fetch_assoc($res2)) {
        $ocs_activas[] = $row;
    }

    $stmt3 = mysqli_prepare($conn,
        "SELECT COALESCE(SUM(oc.cantidad), 0) AS total_historico,
                COUNT(DISTINCT oc.id_op) AS total_ops
         FROM orden_corte oc
         WHERE oc.id_linea = ?"
    );
    mysqli_stmt_bind_param($stmt3, "i", $id_linea);
    mysqli_stmt_execute($stmt3);
    $hist = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt3));

    echo json_encode([
        "success"         => true,
        "linea"           => $linea,
        "ocs_activas"     => $ocs_activas,
        "total_historico" => $hist['total_historico'],
        "total_ops"       => $hist['total_ops']
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear_linea') {
    header('Content-Type: application/json');
    $num_operarios = isset($_POST['num_operarios']) ? intval($_POST['num_operarios']) : 2;
    /** @var mysqli $conn */
    $stmt = mysqli_prepare($conn,
        "INSERT INTO linea (estado, num_operarios, num_linea)
         SELECT 'Activa', ?, COALESCE(MAX(num_linea), 0) + 1 FROM linea"
    );
    mysqli_stmt_bind_param($stmt, "i", $num_operarios);
    if (mysqli_stmt_execute($stmt)) {
        $nuevo_id = mysqli_insert_id($conn);
        echo json_encode(["success" => true, "id_linea" => $nuevo_id, "message" => "Línea creada exitosamente."]);
    } else {
        echo json_encode(["success" => false, "message" => "Error al crear la línea: " . mysqli_error($conn)]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'editar_linea') {
    header('Content-Type: application/json');
    $id_linea      = intval($_POST['id_linea'] ?? 0);
    $estado        = $_POST['estado'] ?? 'Activa';
    $num_operarios = intval($_POST['num_operarios'] ?? 2);
    /** @var mysqli $conn */
    $stmt = mysqli_prepare($conn,
        "UPDATE linea SET estado = ?, num_operarios = ? WHERE id_linea = ?"
    );
    mysqli_stmt_bind_param($stmt, "sii", $estado, $num_operarios, $id_linea);
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(["success" => true, "message" => "Línea actualizada."]);
    } else {
        echo json_encode(["success" => false, "message" => "Error: " . mysqli_error($conn)]);
    }
    exit();
}

/** @var mysqli $conn */
$sql = "SELECT l.id_linea, l.estado, l.num_operarios, l.num_linea,
        COUNT(oc.id_oc) AS ocs_asignadas,
        COALESCE(SUM(oc.cantidad), 0) AS prendas_en_proceso
        FROM linea l
        LEFT JOIN orden_corte oc ON oc.id_linea = l.id_linea
        GROUP BY l.id_linea
        ORDER BY l.num_linea ASC";
$resultado = mysqli_query($conn, $sql);
$lineas = [];
while ($row = mysqli_fetch_assoc($resultado)) {
    $lineas[] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Líneas de Producción - CMT</title>
    <link rel="stylesheet" href="css/linea.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
</head>
<body>
<div class="linea-container">

    <div class="section-header">
        <div class="header-left">
            <h2><i class="bx bx-git-branch"></i> LÍNEAS DE PRODUCCIÓN</h2>
            <p>Gestión y monitoreo de las líneas de corte activas</p>
        </div>
        <button class="btn-nueva-linea" id="btnNuevaLinea">
            <i class="bx bx-plus-circle"></i> NUEVA LÍNEA
        </button>
    </div>

    <div class="kpi-bar">
        <?php
            $total       = count($lineas);
            $activas     = array_filter($lineas, fn($l) => $l['estado'] === 'Activa');
            $ocupadas    = array_filter($lineas, fn($l) => $l['ocs_asignadas'] > 0);
            $tot_prendas = array_sum(array_column($lineas, 'prendas_en_proceso'));
        ?>
        <div class="kpi-card">
            <i class="bx bx-layer"></i>
            <div>
                <span class="kpi-val"><?php echo $total; ?></span>
                <span class="kpi-label">Líneas totales</span>
            </div>
        </div>
        <div class="kpi-card kpi-green">
            <i class="bx bx-check-circle"></i>
            <div>
                <span class="kpi-val"><?php echo count($activas); ?></span>
                <span class="kpi-label">Activas</span>
            </div>
        </div>
        <div class="kpi-card kpi-amber">
            <i class="bx bx-time"></i>
            <div>
                <span class="kpi-val"><?php echo count($ocupadas); ?></span>
                <span class="kpi-label">En proceso</span>
            </div>
        </div>
        <div class="kpi-card kpi-blue">
            <i class="bx bx-t-shirt"></i>
            <div>
                <span class="kpi-val"><?php echo number_format($tot_prendas); ?></span>
                <span class="kpi-label">Prendas en proceso</span>
            </div>
        </div>
    </div>

    <div class="linea-toolbar">
        <div class="toolbar-filters">
            <button class="filter-btn active" data-filter="all">Todas</button>
            <button class="filter-btn" data-filter="Activa">Activas</button>
            <button class="filter-btn" data-filter="Inactiva">Inactivas</button>
            <button class="filter-btn" data-filter="ocupada">En proceso</button>
        </div>
        <div class="toolbar-search">
            <i class="bx bx-search"></i>
            <input type="text" id="searchLinea" placeholder="Buscar línea...">
        </div>
    </div>

    <div class="lineas-grid" id="lineasGrid">
        <?php foreach ($lineas as $l):
            $ocupada    = $l['ocs_asignadas'] > 0;
            $estado_css = strtolower($l['estado']);
            $pct        = $ocupada ? min(100, round($l['prendas_en_proceso'] / max($l['prendas_en_proceso'], 1) * 100)) : 0;
        ?>
        <div class="linea-card <?php echo $estado_css; ?> <?php echo $ocupada ? 'ocupada' : ''; ?>"
             data-id="<?php echo $l['id_linea']; ?>"
             data-estado="<?php echo $l['estado']; ?>"
             data-num="<?php echo $l['num_linea']; ?>"
             data-ocupada="<?php echo $ocupada ? '1' : '0'; ?>">

            <div class="card-status-bar <?php echo $estado_css; ?> <?php echo $ocupada ? 'en-proceso' : ''; ?>"></div>

            <div class="card-inner">
                <div class="card-num-wrap">
                    <span class="card-num"><?php echo $l['num_linea']; ?></span>
                    <div class="card-badges">
                        <span class="badge-estado <?php echo $estado_css; ?>">
                            <?php echo $l['estado']; ?>
                        </span>
                        <?php if ($ocupada): ?>
                        <span class="badge-proceso">
                            <i class="bx bx-loader-alt bx-spin"></i> En proceso
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-info-row">
                    <div class="card-info-item">
                        <i class="bx bx-user-voice"></i>
                        <span><?php echo $l['num_operarios']; ?> operarios</span>
                    </div>
                    <div class="card-info-item">
                        <i class="bx bx-cut"></i>
                        <span><?php echo $l['ocs_asignadas']; ?> OC asignadas</span>
                    </div>
                </div>

                <?php if ($ocupada): ?>
                <div class="card-prendas">
                    <div class="prendas-label">Prendas en proceso</div>
                    <div class="prendas-val"><?php echo number_format($l['prendas_en_proceso']); ?></div>
                </div>
                <?php else: ?>
                <div class="card-libre">
                    <i class="bx bx-check-shield"></i>
                    <span>Línea disponible</span>
                </div>
                <?php endif; ?>

                <button class="btn-ver-linea" data-id="<?php echo $l['id_linea']; ?>">
                    <i class="bx bx-show-alt"></i> VER DETALLE
                </button>
            </div>

            <div class="card-label-fondo">LÍNEA</div>
        </div>
        <?php endforeach; ?>

        <div class="linea-card card-agregar" id="cardAgregar">
            <div class="card-agregar-inner">
                <i class="bx bx-plus-circle"></i>
                <span>Añadir línea</span>
            </div>
        </div>
    </div>

    <div id="emptyState" class="empty-state" style="display:none;">
        <i class="bx bx-search-alt"></i>
        <p>No se encontraron líneas con ese filtro.</p>
    </div>
</div>

<div id="modalOverlay" class="modal-overlay" style="display:none;">
    <div class="modal-panel">
        <div class="modal-header">
            <div class="modal-title-group">
                <div class="modal-icon-wrap" id="modalIconWrap">
                    <span id="modalNumGrande"></span>
                </div>
                <div>
                    <h3 id="modalTitle">Línea de Producción</h3>
                    <p id="modalSubtitle" class="modal-subtitle"></p>
                </div>
            </div>
            <div class="modal-header-right">
                <button class="btn-editar-linea" id="btnEditarLinea">
                    <i class="bx bx-edit"></i> Editar
                </button>
                <button class="btn-cerrar-modal" id="btnCerrarModal">
                    <i class="bx bx-x"></i>
                </button>
            </div>
        </div>

        <div id="modalKpis" class="modal-kpis"></div>

        <div class="modal-tabs">
            <button class="tab-btn active" data-tab="activas">
                <i class="bx bx-loader-circle"></i> OC En Proceso
                <span class="tab-count" id="tabCountActivas">0</span>
            </button>
            <button class="tab-btn" data-tab="historial">
                <i class="bx bx-history"></i> Historial
            </button>
        </div>

        <div id="modalBody" class="modal-body">
        </div>
    </div>
</div>

<div id="modalFormOverlay" class="modal-overlay" style="display:none;">
    <div class="modal-panel modal-panel-sm">
        <div class="modal-header">
            <div class="modal-title-group">
                <div class="modal-icon-wrap" style="background: linear-gradient(135deg,#2196f3,#1565c0);">
                    <i class="bx bx-edit" style="font-size:20px; color:#fff;"></i>
                </div>
                <div>
                    <h3 id="formTitle">Nueva Línea</h3>
                    <p class="modal-subtitle" id="formSubtitle">Completa los datos para registrar</p>
                </div>
            </div>
            <button class="btn-cerrar-modal" id="btnCerrarForm"><i class="bx bx-x"></i></button>
        </div>

        <div class="modal-body modal-form-body">
            <input type="hidden" id="formIdLinea" value="">

            <div class="form-group">
                <label>Número de operarios</label>
                <div class="num-spinner">
                    <button type="button" class="spin-btn" id="spinMinus"><i class="bx bx-minus"></i></button>
                    <input type="number" id="formOperarios" value="2" min="1" max="50">
                    <button type="button" class="spin-btn" id="spinPlus"><i class="bx bx-plus"></i></button>
                </div>
            </div>

            <div class="form-group" id="grupoEstado" style="display:none;">
                <label>Estado</label>
                <div class="estado-selector">
                    <label class="estado-opt">
                        <input type="radio" name="formEstado" value="Activa" checked>
                        <span class="opt-label activa"><i class="bx bx-check-circle"></i> Activa</span>
                    </label>
                    <label class="estado-opt">
                        <input type="radio" name="formEstado" value="Inactiva">
                        <span class="opt-label inactiva"><i class="bx bx-x-circle"></i> Inactiva</span>
                    </label>
                </div>
            </div>

            <div id="formAlert" class="form-alert" style="display:none;"></div>

            <button class="btn-form-submit" id="btnFormSubmit">
                <i class="bx bx-save"></i> GUARDAR
            </button>
        </div>
    </div>
</div>

<script src="js/linea.js"></script>
</body>
</html>