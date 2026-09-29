<?php
declare(strict_types=1);

/** 校验与格式化工具 */
final class V
{
    /**
     * 中国大陆手机号规范化：
     * 去掉空格/连字等分隔符，接受 1[3-9]xxxxxxxxx 或带 86 前缀
     * 返回 11 位号码；不合法返回 null
     */
    public static function normalizePhone(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $s = preg_replace('/[\s\-()]/u', '', (string)$value);
        if (preg_match('/^(?:\+?86)?(1[3-9]\d{9})$/', $s, $m)) {
            return $m[1];
        }
        return null;
    }

    /** 手机号脱敏：138****1234 */
    public static function maskPhone(string $phone): string
    {
        return strlen($phone) === 11
            ? substr($phone, 0, 3) . '****' . substr($phone, 7)
            : $phone;
    }

    /** 订单号规范化：大写字母+数字，8-24 位 */
    public static function normalizeOrderNo(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $s = strtoupper(trim($value));
        $s = preg_replace('/[\s\-]/', '', $s);
        return preg_match('/^[A-Z0-9]{8,24}$/', $s) ? $s : null;
    }

    /**
     * 解析人民币金额字符串/数字为「分」整数。
     * 支持 1000 / "1000" / "1000.00" / "1,000.5"
     */
    public static function toCents(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value * 100 : null;
        }
        if (is_float($value) || (is_string($value) && trim($value) !== '')) {
            $s = str_replace(',', '', trim((string)$value));
            if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $s)) {
                return null;
            }
            return (int)round(((float)$s) * 100);
        }
        return null;
    }

    /** 分 -> 元，保留两位（响应里以字符串输出，避免 JS 精度误读） */
    public static function yuan(?int $cents): ?string
    {
        return $cents === null ? null : number_format($cents / 100, 2, '.', '');
    }

    /** 校验/规整日期时间；接受 Y-m-d H:i 或 Y-m-d H:i:s，返回标准格式 */
    public static function dateTime(mixed $value, bool $allowEmpty = false): ?string
    {
        if ($value === null || $value === '') {
            return $allowEmpty ? null : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $s = trim(str_replace('T', ' ', $value));
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ ]\d{2}:\d{2}(:\d{2})?$/', $s)) {
            if (strlen($s) === 16) {
                $s .= ':00';
            }
            [$date, $time] = explode(' ', $s);
            [$y, $m, $d] = array_map('intval', explode('-', $date));
            [$hh, $mm, $ss] = array_map('intval', explode(':', $time));
            if (checkdate($m, $d, $y) && $hh <= 23 && $mm <= 59 && $ss <= 59) {
                return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, $d, $hh, $mm, $ss);
            }
        }
        return null;
    }

    /** 长度受限的纯文本，去掉控制字符 */
    public static function text(mixed $value, int $max, int $min = 0, bool $optional = false): ?string
    {
        if ($value === null || $value === '') {
            return $optional ? '' : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $s = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value));
        $len = mb_strlen($s);
        return ($len >= $min && $len <= $max) ? $s : null;
    }

    /** 转义 LIKE 通配符 */
    public static function like(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }

    public static function int(mixed $value, int $min, int $max): ?int
    {
        $n = filter_var($value, FILTER_VALIDATE_INT);
        if ($n === false) {
            $n = is_numeric($value) ? (int)$value : false;
        }
        return ($n !== false && $n >= $min && $n <= $max) ? (int)$n : null;
    }
}
