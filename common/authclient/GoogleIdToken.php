<?php

namespace common\authclient;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Yii;

/**
 * Checks a Google ID token, as Google One Tap hands it to the page: signed by Google (RS256, with
 * Google's published keys), issued by Google, for our client ID, and not expired.
 *
 * @see https://developers.google.com/identity/gsi/web/guides/verify-google-id-token
 */
class GoogleIdToken
{
    const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];
    const CACHE_KEY = 'google-id-token-certs';

    /** @var array|null Google's key set (JWKS) to use instead of fetching it (tests) */
    public static $keySet;

    /**
     * @param string $token the ID token (a JWT)
     * @param string $clientId our Google client ID
     * @return array|null the token's claims (sub, email, email_verified, name, ...), or null when
     * the token is not valid
     */
    public static function verify($token, $clientId)
    {
        if (!is_string($token) || $token === '' || substr_count($token, '.') !== 2 || !$clientId) {
            return null;
        }
        try {
            $keys = JWK::parseKeySet(self::keySet(), 'RS256');
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($token, $keys);
        } catch (\Throwable $e) {
            Yii::warning('Google ID token refused: ' . $e->getMessage(), __METHOD__);
            return null;
        }
        if (!in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            Yii::warning('Google ID token from another issuer', __METHOD__);
            return null;
        }
        $audience = (array) ($claims['aud'] ?? []);
        if (!in_array($clientId, $audience, true)) {
            Yii::warning('Google ID token for another client', __METHOD__);
            return null;
        }

        return $claims;
    }

    /**
     * Google's current keys, cached as long as Google says (Cache-Control max-age), at most a day.
     */
    private static function keySet()
    {
        if (self::$keySet !== null) {
            return self::$keySet;
        }
        $cache = Yii::$app->cache;
        $cached = $cache ? $cache->get(self::CACHE_KEY) : false;
        if (is_array($cached)) {
            return $cached;
        }

        $ch = curl_init(self::CERTS_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error || $status !== 200) {
            throw new \RuntimeException("Google's keys could not be fetched (HTTP $status) $error");
        }
        $headers = substr((string) $response, 0, $headerSize);
        $keySet = json_decode(substr((string) $response, $headerSize), true);
        if (!is_array($keySet) || empty($keySet['keys'])) {
            throw new \RuntimeException("Google's keys could not be read");
        }
        $ttl = preg_match('/max-age=(\d+)/i', $headers, $m) ? min((int) $m[1], 86400) : 3600;
        if ($cache) {
            $cache->set(self::CACHE_KEY, $keySet, max(60, $ttl));
        }

        return $keySet;
    }
}
