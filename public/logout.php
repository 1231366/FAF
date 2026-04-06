<?php
session_start();
$_SESSION = array(); // Limpa as variáveis da memória
session_destroy();   // Destrói o ficheiro de sessão no servidor
header("Location: login.php");
exit();
?>