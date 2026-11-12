<?php

namespace common\authclient;

use yii\authclient\OAuth2;

/**
 * "Sign In with LinkedIn using OpenID Connect", the only LinkedIn sign-in available to new apps
 * since 2023 (the r_liteprofile / r_emailaddress scopes that yii\authclient\clients\LinkedIn asks
 * for were retired).
 *
 * The LinkedIn app needs the "Sign In with LinkedIn using OpenID Connect" product, and the
 * redirect URL https://<host>/site/auth?authclient=linkedin.
 *
 * User attributes come from the userinfo endpoint: sub (the member's stable id), name,
 * given_name, family_name, picture, email, email_verified, locale.
 *
 * @see https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2
 */
class LinkedIn extends OAuth2
{
    public $authUrl = 'https://www.linkedin.com/oauth/v2/authorization';
    public $tokenUrl = 'https://www.linkedin.com/oauth/v2/accessToken';
    public $apiBaseUrl = 'https://api.linkedin.com/v2';
    /** userinfo wants the token in the Authorization header */
    public $accessTokenLocation = self::ACCESS_TOKEN_LOCATION_HEADER;

    public function init()
    {
        parent::init();
        if ($this->scope === null) {
            $this->scope = 'openid profile email';
        }
    }

    protected function initUserAttributes()
    {
        return $this->api('userinfo', 'GET');
    }

    protected function defaultName()
    {
        return 'linkedin';
    }

    protected function defaultTitle()
    {
        return 'LinkedIn';
    }
}
