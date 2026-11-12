<?php

namespace frontend\tests\unit;

use common\authclient\LinkedIn;
use common\authclient\OauthProfile;
use common\models\User;
use frontend\tests\BookingDataTrait;
use yii\authclient\OAuthToken;
use yii\httpclient\Client;

/**
 * Signing in with Google or LinkedIn: what each provider's answer means, LinkedIn's OpenID
 * Connect client, and when a provider login may take over an existing account.
 */
class SocialLoginTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    // ---- What the providers tell us ------------------------------------------------------------

    public function testGoogleProfile()
    {
        $profile = OauthProfile::fromAttributes('google', [
            'id' => 1234567890, 'email' => 'ann@gmail.com', 'verified_email' => true, 'name' => 'Ann Wanjiru',
        ]);
        $this->assertSame('1234567890', $profile->id);
        $this->assertSame('ann@gmail.com', $profile->email);
        $this->assertSame('Ann Wanjiru', $profile->name);
        $this->assertTrue($profile->emailVerified);
        $this->assertTrue($profile->isUsable());

        $unverified = OauthProfile::fromAttributes('google', ['id' => '1', 'email' => 'ann@gmail.com', 'verified_email' => false]);
        $this->assertFalse($unverified->emailVerified);
    }

    public function testLinkedInProfileFromOpenIdConnect()
    {
        $profile = OauthProfile::fromAttributes('linkedin', [
            'sub' => 'xYz123', 'name' => 'Brian Otieno', 'given_name' => 'Brian', 'family_name' => 'Otieno',
            'email' => 'brian@example.com', 'email_verified' => true,
        ]);
        $this->assertSame('xYz123', $profile->id);
        $this->assertSame('brian@example.com', $profile->email);
        $this->assertSame('Brian Otieno', $profile->name);
        $this->assertTrue($profile->emailVerified);

        $noName = OauthProfile::fromAttributes('linkedin', ['sub' => 'a', 'given_name' => 'Brian', 'family_name' => 'Otieno', 'email' => 'b@example.com']);
        $this->assertSame('Brian Otieno', $noName->name);
    }

    public function testAnEmailIsOnlyVerifiedWhenTheProviderSaysSo()
    {
        $this->assertFalse(OauthProfile::fromAttributes('linkedin', ['sub' => 'a', 'email' => 'b@example.com'])->emailVerified);
        $this->assertFalse(OauthProfile::fromAttributes('linkedin', ['sub' => 'a', 'email' => 'b@example.com', 'email_verified' => 'false'])->emailVerified);
        $this->assertFalse(OauthProfile::fromAttributes('google', ['id' => 'a', 'email' => 'b@example.com'])->emailVerified);
    }

    public function testAProfileWithoutIdOrEmailCannotSignIn()
    {
        $this->assertFalse(OauthProfile::fromAttributes('linkedin', ['sub' => 'a'])->isUsable());
        $this->assertFalse(OauthProfile::fromAttributes('linkedin', ['email' => 'b@example.com'])->isUsable());
        // The retired LinkedIn API's fields are not read
        $this->assertFalse(OauthProfile::fromAttributes('linkedin', ['id' => 'a', 'emailAddress' => 'b@example.com'])->isUsable());
        $this->assertFalse(OauthProfile::fromAttributes('microsoft', ['id' => 'a', 'mail' => 'b@example.com'])->isUsable());
    }

    // ---- LinkedIn's OpenID Connect client ------------------------------------------------------

    public function testLinkedInAsksForOpenIdConnectScopes()
    {
        $client = new LinkedIn(['clientId' => 'id', 'clientSecret' => 'secret']);
        $this->assertSame('openid profile email', $client->scope);
        $this->assertSame('linkedin', $client->getName());
        $this->assertSame('LinkedIn', $client->getTitle());
    }

    public function testLinkedInReadsUserinfoWithABearerToken()
    {
        $client = new class(['clientId' => 'id', 'clientSecret' => 'secret']) extends LinkedIn {
            public $called = [];

            public function api($apiSubUrl, $method = 'GET', $data = [], $headers = [])
            {
                $this->called[] = $apiSubUrl;

                return ['sub' => 'xYz123', 'email' => 'brian@example.com', 'email_verified' => true, 'name' => 'Brian Otieno'];
            }
        };
        $this->assertSame('xYz123', $client->getUserAttributes()['sub']);
        $this->assertSame(['userinfo'], $client->called);
        $this->assertSame('https://api.linkedin.com/v2', $client->apiBaseUrl);

        $request = (new Client())->createRequest();
        $client->applyAccessTokenToRequest($request, new OAuthToken(['token' => 'abc']));
        $this->assertSame('Bearer abc', $request->getHeaders()->get('Authorization'));
        $this->assertArrayNotHasKey('oauth2_access_token', (array) $request->getData());
    }

    // ---- Finding, linking or creating the account ----------------------------------------------

    public function testANewPersonBecomesAPatient()
    {
        $user = User::createOrUpdateFromOauth('linkedin', 'new-sub', 'new.person@example.com', 'New Person', true);
        $this->assertNotNull($user);
        $this->assertSame('client', $user->role);
        $this->assertSame(User::STATUS_ACTIVE, (int) $user->status);
        $this->assertSame('linkedin', $user->auth_provider);
        $this->assertSame('new-sub', $user->auth_client_id);

        // Next time, found by the provider's id
        $again = User::createOrUpdateFromOauth('linkedin', 'new-sub', 'new.person@example.com', 'New Person', true);
        $this->assertSame($user->id, $again->id);
    }

    public function testAnUnverifiedEmailNeverTakesOverAnExistingAccount()
    {
        $admin = $this->createUser('clinic.admin', 'admin');

        $this->assertNull(User::createOrUpdateFromOauth('linkedin', 'attacker', $admin->email, 'Someone', false));
        $admin->refresh();
        $this->assertNull($admin->auth_provider);
        $this->assertNull($admin->auth_client_id);
    }

    public function testAVerifiedEmailLinksTheExistingAccount()
    {
        $consultant = $this->createUser('dr.linked', 'consultant');

        $user = User::createOrUpdateFromOauth('google', 'g-1', $consultant->email, 'Dr Linked', true);
        $this->assertSame($consultant->id, $user->id);
        $this->assertSame('consultant', $user->role, 'linking keeps the account as it is');
        $this->assertSame('google', $user->auth_provider);
    }

    public function testADeletedAccountCannotSignIn()
    {
        $gone = $this->createUser('gone.patient', 'client');
        $gone->updateAttributes(['status' => User::STATUS_DELETED]);

        $this->assertNull(User::createOrUpdateFromOauth('google', 'g-2', $gone->email, 'Gone', true));
    }
}
