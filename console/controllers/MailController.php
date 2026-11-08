<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use common\components\ObservableMailer;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

/**
 * SMTP diagnostics: tells an SMTP/config problem apart from an application bug.
 *
 *   php yii mail/check              Config summary, DNS, TCP, TLS and SMTP AUTH (sends nothing)
 *   php yii mail/send you@site.tld  Sends a real test email through the app's mailer
 *
 * Credentials are never printed.
 */
class MailController extends Controller
{
    /** @var int seconds to wait for the TCP connection */
    public $timeout = 10;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['timeout']);
    }

    /**
     * Checks the mail configuration and connects/authenticates without sending.
     */
    public function actionCheck(): int
    {
        $mailer = Yii::$app->mailer;
        $host = (string) env('SMTP_HOST', '');
        $port = (int) env('SMTP_PORT', 587);
        $user = (string) env('SMTP_USERNAME', '');
        $pass = (string) env('SMTP_PASSWORD', '');

        $this->section('Configuration');
        $this->row('mailer class', get_class($mailer), $mailer instanceof ObservableMailer);
        $this->row('useFileTransport', $mailer->useFileTransport ? 'true (emails are written to files, NOT sent)' : 'false', !$mailer->useFileTransport);
        $this->row('SMTP_HOST', $host ?: '(empty)', $host !== '');
        $this->row('SMTP_PORT', (string) $port, $port > 0);
        $this->row('SMTP_ENCRYPTION', (string) env('SMTP_ENCRYPTION', '(unset)'), true);
        $this->row('SMTP_USERNAME', $user ?: '(empty)', $user !== '');
        $this->row('SMTP_PASSWORD', $pass === '' ? '(empty)' : 'set (' . strlen($pass) . ' chars'
            . (preg_match('#[@:/?\#%\s]#', $pass) ? ', has URL-reserved chars - encoded in DSN' : '') . ')', $pass !== '');
        $from = Yii::$app->params['supportEmail'] ?? null;
        $this->row('params[supportEmail] (From)', (string) ($from ?: '(empty)'), (bool) $from);
        if ($from && strcasecmp((string) $from, $user) !== 0) {
            $this->warn('From differs from SMTP_USERNAME; Office 365 rejects this unless the mailbox has Send As rights (5.7.60 SendAsDenied).');
        }

        try {
            $transport = $mailer->getTransport();
        } catch (\Throwable $e) {
            $this->fail('Transport could not be built from config: ' . ObservableMailer::redactSecrets($e->getMessage()), 'config');
            return ExitCode::CONFIG;
        }
        $this->row('transport', ObservableMailer::redactSecrets((string) $transport), true);

        $this->section('Network');
        $ips = gethostbynamel($host) ?: [];
        if (!$ips) {
            $this->fail("DNS lookup for '$host' failed.", 'connection');
            return ExitCode::UNAVAILABLE;
        }
        $this->row('DNS', implode(', ', $ips), true);

        $errno = 0;
        $errstr = '';
        $started = microtime(true);
        $socket = @fsockopen($host, $port, $errno, $errstr, $this->timeout);
        if (!$socket) {
            $this->fail("TCP connect to $host:$port failed: $errstr ($errno). Check firewall / outbound port $port on this VM.", 'connection');
            return ExitCode::UNAVAILABLE;
        }
        fclose($socket);
        $this->row('TCP connect', sprintf('%s:%d ok (%d ms)', $host, $port, (microtime(true) - $started) * 1000), true);

        $this->section('SMTP handshake (EHLO, TLS, AUTH)');
        if (!$transport instanceof SmtpTransport) {
            $this->warn('Transport is ' . get_class($transport) . ', not SMTP; skipping handshake.');
            return ExitCode::OK;
        }
        try {
            $transport->start();
            $transport->stop();
        } catch (\Throwable $e) {
            $err = ObservableMailer::describe($e);
            $this->fail($err['message'], $err['type']);
            $this->stdout("\nSMTP transcript (credentials redacted):\n" . $err['smtp_debug'] . "\n");
            $this->hint($err['type']);
            return ExitCode::UNAVAILABLE;
        }
        $this->row('handshake', 'connected, TLS negotiated and authenticated', true);
        $this->stdout("\nSMTP is reachable and accepts the credentials. If signup still fails, check runtime/logs/signup.log.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Sends a test email through the same mailer the app uses.
     * @param string $to recipient address
     */
    public function actionSend(string $to): int
    {
        $mailer = Yii::$app->mailer;
        $ok = $mailer->compose()
            ->setFrom([Yii::$app->params['supportEmail'] => Yii::$app->name . ' robot'])
            ->setTo($to)
            ->setSubject('SMTP test from ' . Yii::$app->name)
            ->setTextBody('This is a test email sent by `php yii mail/send` at ' . date('c') . '.')
            ->send();

        if ($ok) {
            $this->stdout("Sent to $to. If it does not arrive, check spam and the provider's message trace.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $err = $mailer instanceof ObservableMailer ? $mailer->lastError : null;
        if ($err === null) {
            $this->fail('send() returned false; no details (mailer is not ObservableMailer). See runtime/logs/app.log.', 'unknown');
            return ExitCode::UNAVAILABLE;
        }
        $this->fail($err['message'], $err['type']);
        if ($err['smtp_debug'] !== '') {
            $this->stdout("\nSMTP transcript (credentials redacted):\n" . $err['smtp_debug'] . "\n");
        }
        $this->hint($err['type']);
        return ExitCode::UNAVAILABLE;
    }

    private function hint(string $type): void
    {
        $hints = [
            'auth' => "Credentials rejected. For smtp.office365.com check: the password; that SMTP AUTH is enabled for the mailbox\n"
                . "(Set-CASMailbox <user> -SmtpClientAuthenticationDisabled \$false); and that the tenant still allows Basic auth for\n"
                . "SMTP AUTH. Microsoft is retiring it in favour of OAuth; error 5.7.139 means it is disabled for this tenant or mailbox.",
            'tls' => 'TLS failed. Port 587 needs smtp:// (STARTTLS); port 465 needs smtps://. Check SMTP_PORT / SMTP_ENCRYPTION and the CA bundle.',
            'connection' => 'Cannot reach the SMTP server. Check SMTP_HOST, SMTP_PORT and the outbound firewall (many clouds block 25/465/587).',
            'sender_rejected' => 'The server refused the From address. Make params[supportEmail] the authenticated mailbox, or grant Send As.',
            'recipient_rejected' => 'The recipient address was refused by the server.',
            'config' => 'The mailer transport config is invalid; see common/config/main.php and any common/config/main-local.php override.',
        ];
        if (isset($hints[$type])) {
            $this->stdout("\nHint: " . $hints[$type] . "\n", Console::FG_YELLOW);
        }
    }

    private function section(string $title): void
    {
        $this->stdout("\n== $title ==\n", Console::BOLD);
    }

    private function row(string $label, string $value, bool $ok): void
    {
        $this->stdout(str_pad($label, 30) . ' ');
        $this->stdout($ok ? '[ok]   ' : '[FAIL] ', $ok ? Console::FG_GREEN : Console::FG_RED);
        $this->stdout($value . "\n");
    }

    private function fail(string $message, string $type): void
    {
        $this->stdout("\n[FAIL] ($type) $message\n", Console::FG_RED);
    }

    private function warn(string $message): void
    {
        $this->stdout("[warn] $message\n", Console::FG_YELLOW);
    }
}
