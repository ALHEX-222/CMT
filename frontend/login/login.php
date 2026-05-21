<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (isset($_SESSION['usuario'])) {
    header("Location: ../index.php");
    exit();
}

$error = "";

require_once "../../database/config.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $correo = trim($_POST['user'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($correo) && !empty($password)) {
        
        $password_md5 = md5($password);

        $sql = "SELECT id_usuario, nombre, apellido, rol FROM usuario WHERE correo = ? AND password = ?";
        
        /** @var mysqli $conn */
        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ss", $correo, $password_md5);
            mysqli_stmt_execute($stmt);
            $resultado = mysqli_stmt_get_result($stmt);

            if ($row = mysqli_fetch_assoc($resultado)) {
                
                $_SESSION['id_usuario'] = $row['id_usuario'];
                $_SESSION['usuario']    = $row['nombre'];
                $_SESSION['apellido']   = $row['apellido'];
                $_SESSION['rol']        = $row['rol'];

                header("Location: ../index.php");
                exit();

            } else {
                $error = "El correo o la contraseña son incorrectos.";
            }

            mysqli_stmt_close($stmt);
        } else {
            $error = "Error al procesar la consulta en el servidor.";
        }
    } else {
        $error = "Por favor, completa todos los campos.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión</title>
    <link rel="stylesheet" href="../css/login.css">
</head>
<body>

    <div class="login-card">

        <div class="logo-container">
            <img src="../img/logo.png" alt="Logo CMT">
        </div>

        <h2>Bienvenido</h2>
        <p class="subtitle">
            Ingresa tus credenciales para continuar
        </p>

        <?php if (!empty($error)): ?>
            <div class="error-msg" style="color: #e74c3c; background: #fce4e4; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; font-size: 14px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST" id="loginForm">

            <div class="input-group">
                <label for="user">Correo Electrónico</label>
                <input
                    type="email"
                    id="user"
                    name="user"
                    placeholder="Ej. alex.luque@cmt.com"
                    required
                >
            </div>

            <div class="input-group">
                <label for="password">Contraseña</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="••••••••"
                    required
                >
            </div>

            <button type="submit" class="btn-login">
                Iniciar Sesión
            </button>

        </form>

    </div>

    <script src="../js/login.js"></script>

</body>
</html>