<?php

namespace common\components;

/**
 * Per-request correlation ID.
 *
 * Every log line written by the targets that use {@see logPrefix()} carries the
 * same ID, so the signup, mail and app logs for one request can be joined with
 * a single grep. The ID is also shown to users in error messages ("reference")
 * so a support report can be traced straight to the log lines.
 */
final class RequestContext
{
    private static ?string $id = null;

    public static function id(): string
    {
        if (self::$id === null) {
            self::$id = bin2hex(random_bytes(6));
        }
        return self::$id;
    }

    /**
     * Prefix for yii\log\FileTarget::$prefix.
     */
    public static function logPrefix(): string
    {
        return '[req:' . self::id() . '][pid:' . getmypid() . ']';
    }

    /**
     * j***@example.com — enough to identify a report without writing full addresses to logs.
     */
    public static function maskEmail(?string $email): string
    {
        if ($email === null || $email === '' || strpos($email, '@') === false) {
            return '(none)';
        }
        [$local, $domain] = explode('@', $email, 2);
        return mb_substr($local, 0, 1) . '***@' . $domain;
    }
}
