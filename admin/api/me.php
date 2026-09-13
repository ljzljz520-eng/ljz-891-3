<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
$admin = require_admin();
json_out(['ok' => true, 'username' => $admin['username'], 'csrf' => csrf_token()]);
