<?php
declare(strict_types=1);

/** CSV 流式导出（带 BOM，Excel 友好） */
final class Csv
{
    public static function send(string $filename, array $headers, iterable $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, array_map(
                static fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v,
                $row
            ));
        }
        fclose($out);
        exit;
    }
}
