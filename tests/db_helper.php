<?php
// 测试辅助：php tests/db_helper.php <exec|query> <sql> [params...]
$pdo = new PDO('sqlite:' . dirname(__DIR__) . '/data/app.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mode = $argv[1];
$sql = $argv[2];
$params = array_slice($argv, 3);
if ($mode === 'exec') {
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmtSql) {
        $pdo->exec($stmtSql);
    }
} elseif ($mode === 'query') {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo $stmt->fetchColumn();
} else {
    fwrite(STDERR, "unknown mode\n");
    exit(1);
}
