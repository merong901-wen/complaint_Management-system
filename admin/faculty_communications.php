<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['admin']);
header('Location: chat.php');
exit();
