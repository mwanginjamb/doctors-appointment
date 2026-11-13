<?php

namespace frontend\tests\unit;

use common\authclient\GoogleIdToken;
use common\authclient\OauthProfile;
use frontend\tests\GoogleTokenTrait;

/**
 * Google One Tap: which ID tokens are trusted.
 */
class GoogleOneTapTest extends \Codeception\Test\Unit
{
    use GoogleTokenTrait;

    protected function _before()
    {
        $this->trustTestGoogleKey();
    }

    protected function _after()
    {
        GoogleIdToken::$keySet = null;
    }

    public function testAValidTokenGivesTheGoogleAccount()
    {
        $claims = GoogleIdToken::verify($this->googleToken(), $this->googleClientId());
        $this->assertNotNull($claims);

        $profile = OauthProfile::fromAttributes('google', $claims);
        $this->assertSame('110248495921238986420', $profile->id, 'sub: the same id as the Google button gives');
        $this->assertSame('grace.onetap@gmail.com', $profile->email);
        $this->assertSame('Grace OneTap', $profile->name);
        $this->assertTrue($profile->emailVerified);

        $this->assertNotNull(GoogleIdToken::verify($this->googleToken(['iss' => 'accounts.google.com']), $this->googleClientId()));
    }

    public function testTokensNotMeantForUsAreRefused()
    {
        $this->assertNull(GoogleIdToken::verify($this->googleToken(['aud' => 'someone-else.apps.googleusercontent.com']), $this->googleClientId()));
        $this->assertNull(GoogleIdToken::verify($this->googleToken(['iss' => 'https://evil.example.com']), $this->googleClientId()));
        $this->assertNull(GoogleIdToken::verify($this->googleToken(['exp' => time() - 3600, 'iat' => time() - 7200]), $this->googleClientId()), 'expired');
        $this->assertNull(GoogleIdToken::verify($this->googleToken(), ''), 'no client configured');
    }

    public function testForgedOrBrokenTokensAreRefused()
    {
        $this->assertNull(GoogleIdToken::verify($this->googleToken([], $this->otherKey()), $this->googleClientId()), 'signed by another key');

        [$header, $payload, $signature] = explode('.', $this->googleToken());
        $tampered = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $tampered['email'] = 'admin@example.com';
        $tamperedPayload = rtrim(strtr(base64_encode(json_encode($tampered)), '+/', '-_'), '=');
        $this->assertNull(GoogleIdToken::verify("$header.$tamperedPayload.$signature", $this->googleClientId()), 'changed after signing');

        $unsigned = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=') . '.' . $payload . '.';
        $this->assertNull(GoogleIdToken::verify($unsigned, $this->googleClientId()), 'alg none');

        foreach ([null, '', 'not-a-token', 'a.b.c', ['x']] as $junk) {
            $this->assertNull(GoogleIdToken::verify($junk, $this->googleClientId()));
        }
    }
}
