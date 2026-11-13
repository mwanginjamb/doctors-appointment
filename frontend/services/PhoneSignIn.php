<?php

namespace frontend\services;

use common\components\Audit;
use common\components\PhoneMessenger;
use common\helpers\Phone;
use common\models\LoginCode;
use common\models\User;
use Yii;
use yii\web\UserEvent;

/**
 * Patients sign in with their phone number and a one-time code sent by WhatsApp (SMS when
 * WhatsApp cannot send).
 *
 * 1. requestCode(): a code is sent to the number (whether or not an account uses it, so the
 *    answer never tells who has an account).
 * 2. verifyCode(): the right code proves this browser holds the number, for PROVEN_FOR seconds.
 * 3. accountsFor(): the patient accounts on that number it may sign in to (family members share
 *    numbers, so there can be several); signIn() one of them, or createAccount() a new one.
 *
 * Only patient accounts sign in by phone; staff never do. An account is offered only when its
 * number has been proven before or was entered by the clinic (an account without email, as
 * registered at the front desk): a self-registered account carries whatever number its owner
 * typed, so holding that number proves nothing until its owner signs in once with their email and
 * password while the number is proven (see onAfterLogin()).
 */
class PhoneSignIn
{
    /** Seconds a proven number stays proven in the session */
    const PROVEN_FOR = 900;

    const SESSION_PENDING = 'phoneSignIn.pending';
    const SESSION_PROVEN = 'phoneSignIn.proven';

    /**
     * Whether phone sign-in is on and a channel can send codes.
     */
    public static function isAvailable()
    {
        return LoginCode::params()['enabled'] && self::messenger()->channels() !== [];
    }

    /**
     * Sends a code to the number.
     *
     * @param string $raw the number as typed
     * @param string|null $channel try this channel first (e.g. "send by SMS instead")
     * @return array ['status' => sent|invalid|limited|failed|unavailable, 'phone' => normalized|null,
     *               'channel' => the channel used|null]
     */
    public static function requestCode($raw, $channel = null, $ip = null)
    {
        $phone = Phone::normalize($raw);
        if ($phone === null) {
            return ['status' => 'invalid', 'phone' => null, 'channel' => null];
        }
        $channels = self::messenger()->channels();
        if (!LoginCode::params()['enabled'] || $channels === []) {
            return ['status' => 'unavailable', 'phone' => $phone, 'channel' => null];
        }
        if (LoginCode::limitReached($phone, $ip) !== null) {
            Yii::warning('Sign-in code limit reached for ' . Phone::mask($phone) . ' from ' . $ip, __METHOD__);
            return ['status' => 'limited', 'phone' => $phone, 'channel' => null];
        }
        if ($channel !== null && in_array($channel, $channels, true)) {
            $channels = array_values(array_unique(array_merge([$channel], $channels)));
        }

        foreach ($channels as $try) {
            [$code, $row] = LoginCode::issue($phone, $try, $ip);
            try {
                self::messenger()->send($try, $phone, self::message($code));
            } catch (\Exception $e) {
                Yii::warning("Sign-in code by $try to " . Phone::mask($phone) . ' failed: ' . $e->getMessage(), __METHOD__);
                // Not sent: does not count towards the limits, and cannot be used
                $row->delete();
                continue;
            }
            self::session()->set(self::SESSION_PENDING, ['phone' => $phone, 'sentAt' => time(), 'channel' => $try]);

            return ['status' => 'sent', 'phone' => $phone, 'channel' => $try];
        }
        Yii::error('No sign-in code could be sent to ' . Phone::mask($phone) . ' by ' . implode(' or ', $channels), __METHOD__);

        return ['status' => 'failed', 'phone' => $phone, 'channel' => null];
    }

    public static function message($code)
    {
        $minutes = LoginCode::params()['codeTtlMinutes'];

        return "$code is your sign-in code for " . Yii::$app->name . ". It expires in $minutes minutes. Do not share it with anyone.";
    }

    /**
     * The number a code was last sent to in this session: ['phone', 'sentAt', 'channel'], or null.
     */
    public static function pending()
    {
        $pending = self::session()->get(self::SESSION_PENDING);

        return is_array($pending) && isset($pending['phone']) ? $pending : null;
    }

    /**
     * @return string ok|wrong|expired|none (see LoginCode::check())
     */
    public static function verifyCode($phone, $code)
    {
        $result = LoginCode::check($phone, $code);
        if ($result === 'ok') {
            self::session()->set(self::SESSION_PROVEN, ['phone' => $phone, 'at' => time()]);
            self::session()->remove(self::SESSION_PENDING);
        } else {
            Audit::loginFailed('phone ' . Phone::mask($phone) . " (code $result)");
        }

        return $result;
    }

    /**
     * The number this session has proven it holds, if recently.
     */
    public static function provenPhone()
    {
        $proven = self::session()->get(self::SESSION_PROVEN);
        if (!is_array($proven) || empty($proven['phone']) || (int) ($proven['at'] ?? 0) < time() - self::PROVEN_FOR) {
            return null;
        }

        return $proven['phone'];
    }

    /**
     * The patient accounts on the number that holding it may sign in to.
     *
     * @return User[]
     */
    public static function accountsFor($phone)
    {
        return self::patientsOn($phone)
            ->andWhere(['or', ['not', ['phone_verified_at' => null]], ['email' => null], ['email' => '']])
            ->orderBy(['full_name' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
    }

    /**
     * How many other patient accounts use the number but were registered with an email and have
     * not proven it (they are linked by signing in with email once).
     */
    public static function unlinkedCount($phone)
    {
        return (int) self::patientsOn($phone)
            ->andWhere(['phone_verified_at' => null])
            ->andWhere(['not', ['email' => null]])
            ->andWhere(['<>', 'email', ''])
            ->count();
    }

    /**
     * Signs in to one of accountsFor() the proven number.
     *
     * @return bool false when the account is not one the number may sign in to
     */
    public static function signIn($phone, $userId, $remember = false)
    {
        foreach (self::accountsFor($phone) as $user) {
            if ((int) $user->id === (int) $userId) {
                $user->phone_verified_at = time();
                // The number is proven; an account waiting for its email to be verified may go ahead
                $user->status = User::STATUS_ACTIVE;
                $user->save(false, ['phone_verified_at', 'status', 'updated_at']);

                return self::login($user, $remember);
            }
        }

        return false;
    }

    /**
     * Creates a patient account for the proven number, and signs in to it. The consent page
     * follows (frontend\components\ConsentGate).
     *
     * @return User|null
     */
    public static function createAccount($phone, $fullName, $remember = false)
    {
        $fullName = trim(preg_replace('/\s+/', ' ', (string) $fullName));
        if ($fullName === '') {
            return null;
        }
        $user = new User();
        $user->full_name = mb_substr($fullName, 0, 255);
        $user->username = User::generateUniqueUsername($fullName);
        $user->email = null;
        $user->phone_number = '+' . $phone;
        $user->phone_verified_at = time();
        $user->role = User::ACCOUNT_PATIENT;
        $user->status = User::STATUS_ACTIVE;
        // They sign in by phone; the password is random and never disclosed
        $user->setPassword(Yii::$app->security->generateRandomString(32));
        $user->generateAuthKey();
        if (!$user->save(false)) {
            return null;
        }

        return self::login($user, $remember) ? $user : null;
    }

    /**
     * "Mary W.": enough for family members to tell their accounts apart, without showing the full
     * name to whoever holds the phone.
     */
    public static function shortName(User $user)
    {
        $parts = preg_split('/\s+/', trim((string) ($user->full_name ?: $user->username)));
        $first = array_shift($parts);
        $last = $parts ? mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : '';

        return trim($first . ' ' . $last);
    }

    /**
     * user component 'on afterLogin': a patient who signs in another way (password, Google,
     * LinkedIn) while this session has proven their number links the number to the account.
     */
    public static function onAfterLogin(UserEvent $event)
    {
        if ($event->cookieBased || $event->identity === null || !(Yii::$app instanceof \yii\web\Application)) {
            return;
        }
        $phone = self::provenPhone();
        $user = $event->identity;
        if ($phone !== null && $user->isPatientAccount() && $user->phone_key === $phone && $user->phone_verified_at === null) {
            $user->updateAttributes(['phone_verified_at' => time()]);
        }
        self::forget();
    }

    public static function forget()
    {
        self::session()->remove(self::SESSION_PENDING);
        self::session()->remove(self::SESSION_PROVEN);
    }

    private static function login(User $user, $remember)
    {
        $previous = Audit::$loginVia;
        Audit::$loginVia = 'phone';
        try {
            $ok = Yii::$app->user->login($user, $remember ? 3600 * 24 * 30 : 0);
        } finally {
            Audit::$loginVia = $previous;
        }
        if ($ok) {
            self::forget();
        }

        return $ok;
    }

    private static function patientsOn($phone)
    {
        return User::find()
            ->where(['phone_key' => $phone])
            ->andWhere(['or', ['role' => [User::ACCOUNT_PATIENT, '']], ['role' => null]])
            ->andWhere(['status' => [User::STATUS_ACTIVE, User::STATUS_INACTIVE]]);
    }

    /**
     * @return PhoneMessenger
     */
    private static function messenger()
    {
        return Yii::$app->get('phoneMessenger');
    }

    private static function session()
    {
        return Yii::$app->session;
    }
}
