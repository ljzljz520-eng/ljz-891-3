<?php
declare(strict_types=1);

/**
 * 命令行初始化：
 *   php bin/setup.php init                 建表 + 生成密钥
 *   php bin/setup.php admin <用户名> <密码> [显示名]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "本脚本仅可在命令行运行\n");
    exit(1);
}

require __DIR__ . '/../bootstrap.php';

$cmd = $argv[1] ?? '';

if ($cmd === 'init') {
    DB::migrate();
    Sec::key();
    @chmod(Cfg::get('db.path'), 0640);
    echo "初始化完成\n";
    echo "数据库: " . Cfg::get('db.path') . "\n";
    echo "密钥文件: " . Cfg::get('key_file') . " （请勿提交到 git、请勿对外暴露）\n";
    exit(0);
}

if ($cmd === 'admin') {
    $username = $argv[2] ?? '';
    $password = $argv[3] ?? '';
    $displayName = $argv[4] ?? $username;

    if (!preg_match('/^[A-Za-z0-9_\-.]{3,64}$/', $username)) {
        fwrite(STDERR, "用户名需为 3-64 位字母/数字/_-.\n");
        exit(1);
    }
    if (strlen($password) < 10 || strlen($password) > 200) {
        fwrite(STDERR, "密码长度至少 10 位（建议大小写字母+数字+符号）\n");
        exit(1);
    }

    DB::migrate();
    $exists = DB::one('SELECT id FROM admins WHERE username = :u', ['u' => $username]);
    if ($exists !== null) {
        DB::run('UPDATE admins SET password_hash = :h, display_name = :d WHERE username = :u',
            ['h' => password_hash($password, PASSWORD_DEFAULT), 'd' => $displayName, 'u' => $username]);
        echo "已更新管理员: $username\n";
    } else {
        DB::insert(
            'INSERT INTO admins (username, password_hash, display_name) VALUES (:u, :h, :d)',
            ['u' => $username, 'h' => password_hash($password, PASSWORD_DEFAULT), 'd' => $displayName]
        );
        echo "已创建管理员: $username\n";
    }
    exit(0);
}

fwrite(STDERR, "用法:\n  php bin/setup.php init\n  php bin/setup.php admin <用户名> <密码> [显示名]\n");
exit(1);
