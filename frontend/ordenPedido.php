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

require_once "../database/config.php";

// SQL con conteo de sub-divisiones (OC) por cada OP
$sql = "SELECT op.id_op, op.cantidad_prendas, op.fecha_ingreso, op.estado, op.descripcion, op.estilo, c.nombre_cliente,
        (SELECT COUNT(*) FROM ORDEN_CORTE oc WHERE oc.id_op = op.id_op) as total_divisiones
        FROM ORDEN_PEDIDO op
        INNER JOIN CLIENTE c ON op.id_cliente = c.id_cliente
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
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="js/ordenPedido.js"></script>
</body>
</html>