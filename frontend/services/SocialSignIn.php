<?php

namespace frontend\services;

use common\authclient\OauthProfile;
use common\models\User;
use Yii;

/**
 * The last step of signing in with Google or LinkedIn, whichever way the provider answered (the
 * redirect flow in SiteController::onAuthSuccess(), or Google One Tap): find, link or create the
 * account, and sign in.
 */
class SocialSignIn
{
    /**
     * @param string $provider the auth client id (google, linkedin)
     * @param string $title the provider's name for messages
     * @return string|null null when signed in, else the message for the user
     */
    public static function complete($provider, $title, OauthProfile $profile)
    {
        if (!$profile->isUsable()) {
            Yii::warning("Sign-in without email or id from $provider", __METHOD__);
            return "We could not get your email address from $title. Please sign up with email instead.";
        }

        $user = User::createOrUpdateFromOauth($provider, $profile->id, $profile->email, $profile->name, $profile->emailVerified);
        if ($user && (int) $user->status !== User::STATUS_ACTIVE) {
            return 'Your account is not active yet. Please verify your email address first.';
        }
        if (!$user || !Yii::$app->user->login($user)) {
            return "We could not sign you in with $title. If you already have an account, sign in with your password.";
        }

        return null;
    }
}
