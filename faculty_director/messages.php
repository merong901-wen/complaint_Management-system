<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['faculty_director']);
header('Location: chat.php');
exit();
