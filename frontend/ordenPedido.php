<?php
session_start();
if (!isset($_SESSION['usuario'])) {
    header("Location: login/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    header('Content-Type: application/json');
    
    $file = $_FILES['excel_file'];
    $action = isset($_POST['action']) ? $_POST['action'] : 'load'; 
    $uploadDir = __DIR__ . '/../backend/temp/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $filePath = $uploadDir . time() . '_' . basename($file['name']);

    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        $scriptPython = __DIR__ . '/../backend/importar.py';
        $command = 'python ' . escapeshellarg($scriptPython) . ' ' . escapeshellarg($filePath) . ' ' . escapeshellarg($action);
        
        $output = shell_exec($command);
        
        if (file_exists($filePath)) {
            unlink($filePath); 
        }
        
        if ($output === null) {
            echo json_encode(["success" => false, "message" => "Error al ejecutar el script motor de Python."]);
        } else {
            echo $output;
        }
    } else {
        echo json_encode(["success" => false, "message" => "No se pudo alojar el archivo en el servidor."]);
    }
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'get_divisiones') {
    header('Content-Type: application/json');
    require_once "../database/config.php";

    $id_op = isset($_GET['id_op']) ? intval($_GET['id_op']) : 0;
    if ($id_op <= 0) {
        echo json_encode(["success" => false, "message" => "ID de OP inválido."]);
        exit();
    }

    /** @var mysqli $conn */
    $stmt = mysqli_prepare($conn,
        "SELECT id_oc, fecha_corte, observacion, cantidad, id_linea
         FROM orden_corte
         WHERE id_op = ?
         ORDER BY id_oc ASC"
    );

    if (!$stmt) {
        echo json_encode(["success" => false, "message" => "Error al preparar consulta: " . mysqli_error($conn)]);
        exit();
    }

    mysqli_stmt_bind_param($stmt, "i", $id_op);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $divisiones = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $divisiones[] = $row;
    }

    echo json_encode(["success" => true, "divisiones" => $divisiones, "id_op" => $id_op]);
    exit();
}

require_once "../database/config.php";

$sql = "SELECT op.id_op, op.cantidad_prendas, op.fecha_ingreso, op.estado, op.descripcion, op.estilo, c.nombre_cliente,
        (SELECT COUNT(*) FROM orden_corte oc WHERE oc.id_op = op.id_op) as total_divisiones
        FROM orden_pedido op
        INNER JOIN cliente c ON op.id_cliente = c.id_cliente
        ORDER BY CASE WHEN op.estado = 'Pendiente' THEN 0 ELSE 1 END, op.fecha_ingreso DESC";
/** @var mysqli $conn */
$resultado = mysqli_query($conn, $sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Orden Pedidos - CMT</title>
    <link rel="stylesheet" href="css/ordenPedido.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
</head>
<body>

    <div class="op-container">
        <div class="section-header">
            <h2><i class="bx bx-cart"></i> GESTION DE ORDENES DE PEDIDO</h2>
        </div>

        <div class="actions-wrapper">
            <div class="search-box">
                <div class="input-field">
                    <i class="bx bx-hash"></i>
                    <input type="text" id="searchOP" placeholder="Buscar por OP...">
                </div>
                <div class="input-field">
                    <i class="bx bx-user"></i>
                    <input type="text" id="searchCliente" placeholder="Buscar por Cliente...">
                </div>
                <div class="input-field">
                    <i class="bx bx-select-multiple"></i>
                    <select id="searchEstado">
                        <option value="">Todos los Estados</option>
                        <option value="Pendiente" selected>Pendiente</option>
                        <option value="Completado">Completado</option>
                    </select>
                </div>
            </div>

            <div class="excel-upload-zone">
                <label for="excelFile" class="upload-label" id="dropZone">
                    <i class="bx bx-file upload-icon"></i>
                    <div class="upload-text"><span class="bold">SUBIR ARCHIVO DE EXCEL</span></div>
                    <span id="fileName" class="file-name-txt">FORMATOS: .xlsx, .xlsm</span>
                </label>
                <input type="file" id="excelFile" accept=".xlsx, .xlsm" style="display: none;">
            </div>
        </div>

        <div id="statusAlert" class="alert-box" style="display: none;"></div>

        <div id="previewContainer" class="preview-container" style="display: none;">
            <div class="preview-header">
                <h3><i class="bx bx-show-alt"></i> VISTA PREVIA DE DATOS A IMPORTAR</h3>
                <div class="preview-actions">
                    <button id="btnCancelarCarga" class="btn-preview cancel"><i class="bx bx-x"></i> CANCELAR</button>
                    <button id="btnConfirmarCarga" class="btn-preview confirm"><i class="bx bx-cloud-upload"></i> CARGAR</button>
                </div>
            </div>
            <div class="table-responsive margin-top">
                <table class="table-cmt preview-table">
                    <thead>
                        <tr>
                            <th>OP</th>
                            <th>CLIENTE</th>
                            <th>ESTILO</th>
                            <th>DESCRIPCION</th>
                            <th>CANTIDAD TOTAL</th>
                            <th>DIVISIONES (OC)</th>
                            <th>FECHA</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyPreview"></tbody>
                </table>
            </div>
        </div>

        <div class="table-responsive main-table-wrapper">
            <table class="table-cmt">
                <thead>
                    <tr>
                        <th>ID OP</th>
                        <th>CLIENTE</th>
                        <th>ESTILO</th>
                        <th>DESCRIPCION PRENDA</th>
                        <th>CANTIDAD TOTAL</th>
                        <th>DIVISIONES (OC)</th>
                        <th>FECHA INGRESO</th>
                        <th>ESTADO</th>
                        <th>ACCIÓN</th>
                    </tr>
                </thead>
                <tbody id="tbodyOP">
                    <?php while ($row = mysqli_fetch_assoc($resultado)): ?>
                        <tr class="op-row" data-estado="<?php echo $row['estado']; ?>">
                            <td><strong class="text-primary"><?php echo $row['id_op']; ?></strong></td>
                            <td><?php echo htmlspecialchars($row['nombre_cliente']); ?></td>
                            <td><span class="badge-estilo"><?php echo htmlspecialchars($row['estilo']); ?></span></td>
                            <td><?php echo htmlspecialchars($row['descripcion']); ?></td>
                            <td><?php echo number_format($row['cantidad_prendas'], 2); ?></td>
                            <td>
                                <span class="badge-divisiones">
                                    <i class="bx bx-layer"></i> <?php echo $row['total_divisiones']; ?> cortes
                                </span>
                            </td>
                            <td><?php echo $row['fecha_ingreso']; ?></td>
                            <td>
                                <span class="status-badge <?php echo strtolower($row['estado']); ?>">
                                    <?php echo $row['estado']; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn-ver-divisiones" 
                                        data-id="<?php echo $row['id_op']; ?>"
                                        data-cliente="<?php echo htmlspecialchars($row['nombre_cliente']); ?>"
                                        data-estilo="<?php echo htmlspecialchars($row['estilo']); ?>"
                                        data-desc="<?php echo htmlspecialchars($row['descripcion']); ?>"
                                        data-total="<?php echo $row['cantidad_prendas']; ?>">
                                    <i class="bx bx-layer"></i> VER
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="modalOverlay" class="modal-overlay" style="display:none;">
        <div class="modal-panel">

            <div class="modal-header">
                <div class="modal-title-group">
                    <span class="modal-icon"><i class="bx bx-scissors"></i></span>
                    <div>
                        <h3 id="modalTitle">Divisiones de Corte</h3>
                        <p id="modalSubtitle" class="modal-subtitle"></p>
                    </div>
                </div>
                <div class="modal-header-actions">
                    <span id="modalDescBadge" class="modal-desc-badge"></span>
                    <button id="btnCerrarModal" class="btn-cerrar-modal">
                        <i class="bx bx-x"></i>
                    </button>
                </div>
            </div>

            <div id="modalResumen" class="modal-resumen"></div>

            <div id="modalDistribucion" class="modal-distribucion" style="display:none;">
                <div class="dist-title"><i class="bx bx-bar-chart-alt-2"></i> DISTRIBUCIÓN POR LÍNEA</div>
                <div id="distBars" class="dist-bars"></div>
            </div>

            <div class="modal-filtros">
                <div class="filtro-field">
                    <i class="bx bx-search"></i>
                    <input type="text" id="filtroOC" placeholder="Buscar por N° OC...">
                </div>
                <div class="filtro-field">
                    <i class="bx bx-git-branch"></i>
                    <select id="filtroLinea">
                        <option value="">Todas las Líneas</option>
                    </select>
                </div>
                <div class="filtro-field">
                    <i class="bx bx-sort-alt-2"></i>
                    <select id="filtroOrden">
                        <option value="oc_asc">OC: Menor → Mayor</option>
                        <option value="oc_desc">OC: Mayor → Menor</option>
                        <option value="cant_desc">Cantidad: Mayor → Menor</option>
                        <option value="cant_asc">Cantidad: Menor → Mayor</option>
                        <option value="fecha_asc">Fecha: Más antigua</option>
                        <option value="fecha_desc">Fecha: Más reciente</option>
                    </select>
                </div>
                <div id="filtroResultCount" class="filtro-result-count"></div>
            </div>

            <div id="modalBody" class="modal-body">
            </div>

        </div>
    </div>

    <script src="js/ordenPedido.js"></script>
</body>
</html>