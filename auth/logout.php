<?php
session_start();
$_SESSION = [];
session_destroy();
session_regenerate_id(true);
header('Location: login.php');
exit;
