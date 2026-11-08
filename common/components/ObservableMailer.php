<?php

namespace common\components;

use Yii;
use RuntimeException;
use yii\symfonymailer\Mailer;
use yii\symfonymailer\Message;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Symfony mailer that records *why* a send failed instead of swallowing it.
 *
 * The stock yii\symfonymailer\Mailer catches every exception in sendMessage(),
 * logs only its message and returns false, so callers cannot tell an SMTP
 * problem from anything else. This subclass:
 *
 *  - classifies the failure (connection / tls / auth / sender_rejected /
 *    recipient_rejected / unknown) into {@see $lastError}, which callers can read;
 *  - logs every send attempt (success and failure, with duration) to the
 *    "mail" category, including the SMTP transcript on failure with the
 *    credentials redacted.
 *
 * NOTE: encryptor/signer (withEncryptor()/withSigner()) are not supported here,
 * as the parent keeps them private. The app does not use them.
 */
class ObservableMailer extends Mailer
{
    public const LOG_CATEGORY = 'mail';

    /** Max bytes of SMTP transcript kept in logs. */
    public int $maxDebugBytes = 4096;

    /**
     * Details of the most recent failed send, or null if the last send succeeded.
     * Keys: type, exception, message, code, smtp_debug.
     */
    public ?array $lastError = null;

    protected function sendMessage($message): bool
    {
        if (!($message instanceof Message)) {
            throw new RuntimeException(sprintf('The message must be an instance of "%s".', Message::class));
        }

        $this->lastError = null;
        $started = microtime(true);
        $context = [
            'to' => array_map([RequestContext::class, 'maskEmail'], array_keys((array) $message->getTo())),
            'from' => array_keys((array) $message->getFrom()),
            'subject' => $message->getSubject(),
        ];

        try {
            $this->getSymfonyMailer()->send($message->getSymfonyEmail());
        } catch (\Throwable $e) {
            $this->lastError = static::describe($e, $this->maxDebugBytes);
            Yii::error(array_merge(['event' => 'mail.send_failed'], $context, [
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $this->lastError,
            ]), self::LOG_CATEGORY);
            return false;
        }

        Yii::info(array_merge(['event' => 'mail.sent'], $context, [
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]), self::LOG_CATEGORY);
        return true;
    }

    /**
     * Turns an exception from the transport into a classified, credential-free array.
     */
    public static function describe(\Throwable $e, int $maxDebugBytes = 4096): array
    {
        $debug = '';
        if ($e instanceof TransportExceptionInterface && method_exists($e, 'getDebug')) {
            $debug = static::redactTranscript((string) $e->getDebug());
            if (strlen($debug) > $maxDebugBytes) {
                $debug = '…' . substr($debug, -$maxDebugBytes);
            }
        }

        // Classify on the exception message first; fall back to the server's last
        // replies only. The full transcript always contains "STARTTLS" on port 587,
        // which would make every failure look like a TLS problem.
        $type = static::classify($e->getMessage(), $e);
        if ($type === 'unknown' && $debug !== '') {
            $serverLines = preg_grep('/^(\[[^\]]+\] )?< /', preg_split('/\R/', $debug));
            $type = static::classify(implode("\n", array_slice($serverLines, -3)), $e);
        }

        return [
            'type' => $type,
            'exception' => get_class($e),
            'message' => static::redactSecrets($e->getMessage()),
            'code' => $e->getCode(),
            'smtp_debug' => $debug,
        ];
    }

    /**
     * Rough root-cause bucket. Anything other than "unknown"/"not_transport"
     * points at SMTP/config, not application logic.
     */
    public static function classify(string $text, ?\Throwable $e = null): string
    {
        if ($e !== null && !($e instanceof TransportExceptionInterface)) {
            return 'not_transport';
        }
        $t = strtolower($text);
        $rules = [
            'auth' => ['535', '5.7.139', '5.7.57', '5.7.3 ', 'authentication', 'authenticate', 'auth login', 'auth plain', 'basic auth', 'invalid credentials', 'username and password'],
            'tls' => ['starttls', 'ssl operation failed', 'wrong version number', 'certificate', 'crypto', 'handshake', 'tls'],
            'sender_rejected' => ['sendasdenied', '5.7.60', 'mail from', 'sender address rejected', 'not allowed to send'],
            'recipient_rejected' => ['rcpt to', '5.1.1', '5.1.0', 'recipient', 'mailbox unavailable', 'user unknown'],
            'connection' => ['could not be established', 'connection refused', 'timed out', 'timeout', 'getaddrinfo', 'name or service not known', 'network is unreachable', 'connection reset', 'unable to connect'],
            'config' => ['dsn', 'scheme', 'unsupported'],
        ];
        foreach ($rules as $type => $needles) {
            foreach ($needles as $needle) {
                if (strpos($t, $needle) !== false) {
                    return $type;
                }
            }
        }
        return 'unknown';
    }

    /**
     * Symfony's transcript includes the base64-encoded username/password sent
     * during AUTH LOGIN / AUTH PLAIN. Drop every client line that isn't a plain
     * SMTP verb, and the argument of AUTH commands.
     */
    public static function redactTranscript(string $debug): string
    {
        $verbs = 'EHLO|HELO|MAIL FROM|RCPT TO|DATA|QUIT|RSET|NOOP|STARTTLS|\.';
        $lines = preg_split('/\R/', $debug);
        foreach ($lines as &$line) {
            if (!preg_match('/^(\[[^\]]+\] )?> (.*)$/', $line, $m)) {
                continue;
            }
            $cmd = $m[2];
            if (preg_match('/^AUTH\s+(\S+)/i', $cmd, $a)) {
                $line = $m[1] . '> AUTH ' . $a[1] . ' [redacted]';
            } elseif (!preg_match('/^(' . $verbs . ')(\s|:|$)/i', $cmd)) {
                $line = $m[1] . '> [redacted]';
            }
        }
        return static::redactSecrets(implode("\n", $lines));
    }

    /**
     * Removes credentials that may appear inside a DSN in an exception message.
     */
    public static function redactSecrets(string $text): string
    {
        return preg_replace('#([a-z]+://)[^\s/]*@#i', '$1[redacted]@', $text);
    }
}
