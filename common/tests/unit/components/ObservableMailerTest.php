<?php

namespace common\tests\unit\components;

use common\components\ObservableMailer;
use Symfony\Component\Mailer\Exception\TransportException;

class ObservableMailerTest extends \Codeception\Test\Unit
{
    public function testClassifiesCommonSmtpFailures()
    {
        $cases = [
            'auth' => '535 5.7.139 Authentication unsuccessful, basic authentication is disabled.',
            'connection' => 'Connection could not be established with host "ssl://smtp.office365.com:587": stream_socket_client(): Unable to connect (Connection timed out)',
            'tls' => 'Connection could not be established with host "ssl://smtp.office365.com:587": stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: wrong version number',
            'sender_rejected' => '554 5.2.0 STOREDRV.Submission.Exception:SendAsDeniedException.MapiExceptionSendAsDenied',
            'recipient_rejected' => 'Expected response code "250/251/252" but got code "550", with message "550 5.1.1 User unknown". (RCPT TO)',
        ];
        foreach ($cases as $expected => $message) {
            verify(ObservableMailer::classify($message, new TransportException($message)))->equals($expected);
        }
    }

    public function testNonTransportExceptionIsNotBlamedOnSmtp()
    {
        verify(ObservableMailer::classify('Undefined variable $user', new \ErrorException('x')))->equals('not_transport');
    }

    public function testRedactsCredentialsFromTranscript()
    {
        $transcript = implode("\n", [
            '[2026-11-08T10:00:00.000000+03:00] > EHLO app',
            '[2026-11-08T10:00:00.000000+03:00] < 250 OK',
            '[2026-11-08T10:00:00.000000+03:00] > AUTH LOGIN',
            '[2026-11-08T10:00:00.000000+03:00] < 334 VXNlcm5hbWU6',
            '[2026-11-08T10:00:00.000000+03:00] > ' . base64_encode('user@example.com'),
            '[2026-11-08T10:00:00.000000+03:00] > ' . base64_encode('s3cr3t@pw'),
            '[2026-11-08T10:00:00.000000+03:00] > AUTH PLAIN ' . base64_encode("\0user\0s3cr3t"),
            '[2026-11-08T10:00:00.000000+03:00] > MAIL FROM:<user@example.com>',
        ]);
        $out = ObservableMailer::redactTranscript($transcript);

        verify($out)->stringNotContainsString(base64_encode('s3cr3t@pw'));
        verify($out)->stringNotContainsString(base64_encode('user@example.com'));
        verify($out)->stringNotContainsString(base64_encode("\0user\0s3cr3t"));
        verify($out)->stringContainsString('> EHLO app');
        verify($out)->stringContainsString('> MAIL FROM:<user@example.com>');
        verify($out)->stringContainsString('> AUTH LOGIN [redacted]');
    }

    public function testRedactsDsnCredentialsInMessages()
    {
        $out = ObservableMailer::redactSecrets('Invalid DSN smtp://user%40x.com:p%40ss@smtp.office365.com:587');
        verify($out)->stringNotContainsString('p%40ss');
        verify($out)->stringContainsString('smtp://[redacted]@smtp.office365.com:587');
    }

    public function testEncodedDsnRoundTripsReservedCharacters()
    {
        $password = 'p@ss:w/rd#1?%';
        $dsn = sprintf('smtp://%s:%s@%s:%d', rawurlencode('essportal@example.com'), rawurlencode($password), 'smtp.office365.com', 587);
        $parsed = \Symfony\Component\Mailer\Transport\Dsn::fromString($dsn);

        verify($parsed->getUser())->equals('essportal@example.com');
        verify($parsed->getPassword())->equals($password);
        verify($parsed->getHost())->equals('smtp.office365.com');
        verify($parsed->getPort())->equals(587);
    }
}
