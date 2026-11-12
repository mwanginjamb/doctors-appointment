<?php

namespace common\authclient;

/**
 * What a sign-in provider tells us about the user, in one shape: id, email, name and whether the
 * provider has verified the email. An email counts as verified only when the provider says so,
 * because an existing account is linked by its email (see User::createOrUpdateFromOauth()).
 */
class OauthProfile
{
    /** @var string|null the provider's stable id for the user */
    public $id;
    /** @var string|null */
    public $email;
    /** @var string|null */
    public $name;
    /** @var bool */
    public $emailVerified = false;

    /**
     * @param string $client the auth client id (google, linkedin)
     * @param array $attributes the client's user attributes
     * @return static
     */
    public static function fromAttributes($client, array $attributes)
    {
        $profile = new static();
        switch ($client) {
            case 'google':
                // https://www.googleapis.com/oauth2/v1/userinfo
                $profile->id = $attributes['id'] ?? null;
                $profile->email = $attributes['email'] ?? null;
                $profile->name = $attributes['name'] ?? null;
                $profile->emailVerified = self::isTrue($attributes['verified_email'] ?? $attributes['email_verified'] ?? false);
                break;
            case 'linkedin':
                // OpenID Connect userinfo (see LinkedIn)
                $profile->id = $attributes['sub'] ?? null;
                $profile->email = $attributes['email'] ?? null;
                $profile->name = $attributes['name']
                    ?? (trim(($attributes['given_name'] ?? '') . ' ' . ($attributes['family_name'] ?? '')) ?: null);
                $profile->emailVerified = self::isTrue($attributes['email_verified'] ?? false);
                break;
        }
        if ($profile->id !== null) {
            $profile->id = (string) $profile->id;
        }
        if ($profile->email !== null) {
            $profile->email = trim((string) $profile->email) ?: null;
        }

        return $profile;
    }

    /**
     * Whether there is enough to sign in with: an id and an email.
     */
    public function isUsable()
    {
        return $this->id !== null && $this->id !== '' && $this->email !== null;
    }

    private static function isTrue($value)
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
