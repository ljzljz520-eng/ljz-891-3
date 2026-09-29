<?php
/**
 * 命令行管理工具：
 *   php scripts/admin.php pass:set <用户名> <新密码>   创建管理员或重置密码
 *   php scripts/admin.php pass:reset-demo             将默认管理员重置为配置中的密码
 *   php scripts/admin.php user:list                   列出管理员
 *   php scripts/admin.php rekey                       重新生成 APP_KEY（注意：旧手机号哈希将失效）
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅可在命令行运行\n");
    exit(1);
}
require_once __DIR__ . '/../lib/bootstrap.php';

$pdo = DB::pdo();
$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'pass:set':
        $username = $argv[2] ?? '';
        $password = $argv[3] ?? '';
        if ($username === '' || strlen($username) > 64) {
            fwrite(STDERR, "用法: php scripts/admin.php pass:set <用户名> <新密码(至少8位)>\n");
            exit(1);
        }
        if (strlen($password) < 8) {
            fwrite(STDERR, "密码至少 8 位\n");
            exit(1);
        }
        $stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        $id = $stmt->fetchColumn();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($id) {
            $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
            echo "管理员 {$username} 密码已重置\n";
        } else {
            $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')->execute([$username, $hash]);
            echo "管理员 {$username} 已创建\n";
        }
        // 改密后令所有后台会话失效
        $pdo->exec('DELETE FROM admin_sessions');
        break;

    case 'pass:reset-demo':
        $hash = password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE admins SET password_hash = ? WHERE username = ?')
            ->execute([$hash, ADMIN_USERNAME]);
        echo "已将 " . ADMIN_USERNAME . " 密码重置为配置默认值（请尽快修改）\n";
        break;

    case 'user:list':
        foreach ($pdo->query('SELECT id, username, created_at FROM admins ORDER BY id') as $row) {
            echo "#{$row['id']}  {$row['username']}  创建于 {$row['created_at']}\n";
        }
        break;

    case 'rekey':
        $file = DATA_DIR . '/key.php';
        $key = bin2hex(random_bytes(32));
        file_put_contents($file, "<?php\nreturn " . var_export($key, true) . ";\n", LOCK_EX);
        @chmod($file, 0640);
        // 重新生成所有手机号哈希
        $n = 0;
        foreach ($pdo->query('SELECT id, phone FROM customers') as $row) {
            $pdo->prepare('UPDATE customers SET phone_hash = ? WHERE id = ?')
                ->execute([hash_hmac('sha256', $row['phone'], $key), $row['id']]);
            $n++;
        }
        echo "已轮换密钥，并重算 {$n} 个手机号哈希\n";
        break;

    default:
        fwrite(STDERR, "未知命令。可用: pass:set, pass:reset-demo, user:list, rekey\n");
        exit(1);
}
