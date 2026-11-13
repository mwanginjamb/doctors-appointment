<?php

namespace frontend\tests;

use common\authclient\GoogleIdToken;
use Firebase\JWT\JWT;

/**
 * Google ID tokens signed with a key made for these tests only (tests/_data/google-test-key.pem),
 * which GoogleIdToken trusts in place of Google's published keys.
 */
trait GoogleTokenTrait
{
    /** The Google client ID in common/config/test.php */
    protected function googleClientId()
    {
        return 'test-client.apps.googleusercontent.com';
    }

    protected function trustTestGoogleKey()
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->testKey()));
        GoogleIdToken::$keySet = ['keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => 'test-key',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ]]];
    }

    /**
     * A key Google's key set does not hold, to forge tokens with.
     */
    protected function otherKey()
    {
        return file_get_contents(dirname(__DIR__) . '/_data/google-other-key.pem');
    }

    /**
     * @param array $claims overrides of a valid token's claims
     * @param string|null $privateKey sign with another key
     */
    protected function googleToken(array $claims = [], $privateKey = null)
    {
        $now = time();
        $payload = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => $this->googleClientId(),
            'sub' => '110248495921238986420',
            'email' => 'grace.onetap@gmail.com',
            'email_verified' => true,
            'name' => 'Grace OneTap',
            'iat' => $now,
            'exp' => $now + 3600,
        ], $claims);

        return JWT::encode($payload, $privateKey ?? $this->testKey(), 'RS256', 'test-key');
    }

    private function testKey()
    {
        return file_get_contents(dirname(__DIR__) . '/_data/google-test-key.pem');
    }
}
