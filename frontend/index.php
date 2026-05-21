<?php
session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

if (!isset($_SESSION['usuario'])) {
    header("Location: login/login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control - CMT</title>
    <link rel="stylesheet" href="css/index.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
</head>
<body>

    <nav class="sidebar">
        <div class="logo-details">
            <i class="bx bx-unite icon"></i>
            <div class="logo_name">CMT</div>
        </div>
        <ul class="nav-list">
            <li>
                <a href="#" class="nav-link active" data-target="dashboard">
                    <i class="bx bx-grid-alt"></i>
                    <span class="links_name">DASHBOARD</span>
                </a>
            </li>
            <li>
                <a href="#" class="nav-link" data-target="clientes">
                    <i class="bx bx-user"></i>
                    <span class="links_name">CLIENTES</span>
                </a>
            </li>
            <li>
                <a href="#" class="nav-link" data-target="pedidos">
                    <i class="bx bx-cart"></i>
                    <span class="links_name">ORDEN PEDIDOS</span>
                </a>
            </li>
            <li>
                <a href="#" class="nav-link" data-target="corte">
                    <i class="bx bx-cut"></i>
                    <span class="links_name">ORDEN CORTE</span>
                </a>
            </li>
            <li>
                <a href="#" class="nav-link" data-target="lineas">
                    <i class="bx bx-layer"></i>
                    <span class="links_name">LÍNEA</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="main-container">
        
        <header class="appbar">
            <div class="appbar-title">
                <h1>GESTION DE LA TEXTIL CMT DEL SUR</h1>
            </div>
            <div class="user-menu-container" id="userMenuBtn">
                <div class="user-box">
                    <i class="bx bx-user-circle user-icon"></i>
                    <span class="user-name">
                        <?php echo htmlspecialchars($_SESSION['usuario'] . " " . $_SESSION['apellido']); ?>
                    </span>
                    <i class="bx bx-chevron-down arrow-icon"></i>
                </div>
                <div class="dropdown-menu" id="dropdownMenu">
                    <div class="dropdown-role">Rol: <?php echo htmlspecialchars($_SESSION['rol']); ?></div>
                    <hr>
                    <button id="openModalBtn" class="dropdown-item">
                        <i class="bx bx-log-out"></i> Cerrar Sesión
                    </button>
                </div>
            </div>
        </header>

        <main class="content">
            <div class="container-fluid">
                
                <div id="content-dashboard" class="content-section active">
                    <h2>Bienvenido al Dashboard</h2>
                    <p>Aquí verás el resumen general del sistema textil CMT.</p>
                </div>

                <div id="content-clientes" class="content-section">
                    <h2>Bienvenido a Clientes</h2>
                    <p>Módulo de gestión y registro de clientes de la empresa.</p>
                </div>

                <div id="content-pedidos" class="content-section">
    <iframe src="ordenPedido.php" style="width: 100%; height: calc(100vh - 120px); border: none; overflow-y: auto;" id="iframe-pedidos"></iframe>
</div>

                <div id="content-corte" class="content-section">
                    <h2>Bienvenido a Orden Corte</h2>
                    <p>Control y distribución de las órdenes de corte textil.</p>
                </div>

                <div id="content-lineas" class="content-section">
                    <h2>Bienvenido a Líneas</h2>
                    <p>Módulo de gestión y supervisión de las líneas de producción.</p>
                </div>

            </div>
        </main>

        <div class="floating-alert" id="alertBtn">
            <i class="bx bx-bell icon-alert"></i>
            <span>Alertas</span>
        </div>
    </div>

    <div id="logoutModal" class="modal-overlay">
        <div class="modal-content">
            <i class="bx bx-error-circle modal-icon"></i>
            <h3>¿Cerrar Sesión?</h3>
            <p>¿Estás seguro de que deseas salir del sistema CMT?</p>
            <div class="modal-buttons">
                <button id="cancelBtn" class="btn-cancel">Cancelar</button>
                <button id="confirmBtn" class="btn-confirm">Sí, salir</button>
            </div>
        </div>
    </div>

    <script src="js/index.js"></script>
</body>
</html>