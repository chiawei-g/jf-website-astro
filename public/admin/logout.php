<?php
declare(strict_types=1);

define('JFSD_ADMIN', true);
require_once __DIR__ . '/auth.php';

admin_logout();
header('Location: /admin/login.php');
exit;
