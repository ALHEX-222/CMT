<?php
session_start();

// Si ya está logueado, puedes redirigirlo a donde quieras (ej. index.php)
if (isset($_SESSION['usuario'])) {
    // header("Location: ../../index.php"); 
}

$error = "";

// Validación simple al enviar el formulario
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = $_POST['user'];
    $password = $_POST['password'];

    // Credenciales de prueba
    if ($user === "admin" && $password === "12345") {
        $_SESSION['usuario'] = $user;
        echo "<script>alert('¡Ingreso exitoso!'); window.location.href='#';</script>";
    } else {
        $error = "Usuario o contraseña incorrectos.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión</title>
    <!-- Vinculamos el archivo CSS -->
    <link rel="stylesheet" href="../css/login.css">
</head>
<body>

    <div class="login-card">
        <h2>Bienvenido</h2>
        <p class="subtitle">Ingresa tus credenciales para continuar</p>

        <?php if (!empty($error)): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST" id="loginForm">
            <div class="input-group">
                <label for="user">Usuario</label>
                <input type="text" id="user" name="user" placeholder="Ej. admin" required>
            </div>

            <div class="input-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn-login">Iniciar Sesión</button>
        </form>
    </div>

    <!-- Vinculamos el archivo JS -->
    <script src="../js/login.js"></script>
</body>
</html>