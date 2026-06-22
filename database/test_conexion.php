<?php
$conn = mysqli_connect("acela.proxy.rlwy.net", "root", "wcRXcndsDPkkypOpwilNAkhysEgwfwzs", "railway", 27703);
if (!$conn) {
    echo "Fallo de conexión: " . mysqli_connect_error();
} else {
    echo "¡Conexión exitosa!";
}
?>