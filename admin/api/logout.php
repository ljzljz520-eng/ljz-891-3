<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
session_start_secure();
$admin = require_admin();
log_query(db(), '', 'ok', 'logout', 'user=' . $admin['username'], $admin['id']);
$_SESSION = [];
session_destroy();
json_out(['ok' => true]);
