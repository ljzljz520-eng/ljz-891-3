<?php
declare(strict_types=1);

/** 加密 / 哈希 / 随机值 */
final class Sec
{
    private static ?string $key = null;

    /** 读取（必要时生成）应用密钥 */
    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $file = Cfg::get('key_file');
        if (is_file($file)) {
            $key = trim((string)file_get_contents($file));
            if (strlen($key) >= 32) {
                return self::$key = $key;
            }
        }
        $key = bin2hex(random_bytes(32)); // 64 字符
        if (@file_put_contents($file, $key, LOCK_EX) === false) {
            throw new RuntimeException('无法写入应用密钥文件: ' . $file);
        }
        @chmod($file, 0600);
        return self::$key = $key;
    }

    /** 固定 32 字节派生密钥（HMAC / AES 共用不同用途标签） */
    private static function derived(string $label): string
    {
        return hash_hkdf('sha256', @hex2bin(self::key()) ?: self::key(), 32, $label);
    }

    /** 手机号 HMAC（校验身份用，不可逆） */
    public static function phoneHash(string $phoneE164): string
    {
        return hash_hmac('sha256', $phoneE164, self::derived('phone-hmac-v1'));
    }

    /** 手机号 AES-256-GCM 可逆加密（仅后台可解密），格式 base64(iv|tag|ct) */
    public static function encryptPhone(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            self::derived('phone-aes-v1'),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($ct === false) {
            throw new RuntimeException('手机号加密失败');
        }
        return base64_encode($iv . $tag . $ct);
    }

    public static function decryptPhone(string $blob): ?string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $plain = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            self::derived('phone-aes-v1'),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        return $plain === false ? null : $plain;
    }

    public static function token64(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function sha256(string $s): string
    {
        return hash('sha256', $s);
    }

    public static function hashIp(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, self::derived('ip-hmac-v1')), 0, 32);
    }

    /** 恒定时间字符串比较 */
    public static function hashEquals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 去掉易混字符

    /**
     * 非顺序订单号：日期基 + 随机段。
     * 形如 RC260929 K7Q... 共 16 位，含随机 8 位（~32^8 ≈ 1.1 万亿组合）
     */
    public static function newOrderNo(): string
    {
        $prefix = 'RC' . date('ymd');
        $rand = '';
        for ($i = 0; $i < 8; $i++) {
            $rand .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $prefix . $rand;
    }
}
