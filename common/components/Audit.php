<?php

namespace common\components;

use common\models\AuditLog;
use Yii;
use yii\web\UserEvent;

/**
 * Writes the audit log (common\models\AuditLog).
 *
 * Model changes are recorded by common\behaviors\AuditBehavior; everything else (sign-ins, bulk
 * saves, role changes) calls log() directly.
 *
 * The actor is the signed-in user (staff or patient). Pages used without signing in say who is
 * acting with actAs(): a booking link, or a patient's self-service link. Console runs are "system".
 *
 * Writing the log never breaks the action being logged: a failure is reported to the app log.
 */
class Audit
{
    /** @var array|null set by actAs(): [the application it was set in, [type, user id, name]] */
    private static $actor;

    /**
     * Records the requests that follow as done by someone who is not signed in.
     *
     * @param string $type AuditLog::ACTOR_BOOKING_LINK or AuditLog::ACTOR_SELF_SERVICE
     * @param int|null $userId the patient, when known
     * @param string|null $name
     */
    public static function actAs($type, $userId = null, $name = null)
    {
        // Tied to this application (one request), so it never carries over to another request
        self::$actor = [Yii::$app, [$type, $userId, $name]];
    }

    /** Back to working out the actor from the signed-in user. */
    public static function reset()
    {
        self::$actor = null;
    }

    /**
     * @param string $action AuditLog::ACTION_*
     * @param string|null $entityType e.g. 'appointment'
     * @param int|string|null $entityId
     * @param array $changes field => [old, new]
     * @param string|null $note
     * @param array|null $actor [type, user id, name] to use instead of the current one
     * @param int|null $facilityId the facility the entry is about, when it is about one (F8 phase 2)
     * @return bool whether the entry was written
     */
    public static function log($action, $entityType = null, $entityId = null, array $changes = [], $note = null, ?array $actor = null, $facilityId = null)
    {
        try {
            [$type, $actorId, $name] = $actor ?? self::currentActor();
            $entry = new AuditLog([
                'created_at' => time(),
                'actor_id' => $actorId,
                'actor_type' => $type,
                'actor_name' => $name !== null ? mb_substr((string) $name, 0, 150) : null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId !== null ? (string) $entityId : null,
                'facility_id' => $facilityId !== null && $facilityId !== '' ? (int) $facilityId : null,
                'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'note' => $note !== null ? mb_substr((string) $note, 0, 255) : null,
                'ip' => self::ip(),
            ]);
            return $entry->save(false);
        } catch (\Throwable $e) {
            Yii::error('Could not write the audit log: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * The actor of the current request: [type, user id, name].
     */
    public static function currentActor()
    {
        if (self::$actor !== null && self::$actor[0] === Yii::$app) {
            return self::$actor[1];
        }

        $user = Yii::$app->get('user', false);
        if ($user === null) {
            return [AuditLog::ACTOR_SYSTEM, null, null];
        }
        $identity = $user->getIdentity(false);
        if ($identity === null) {
            return [AuditLog::ACTOR_GUEST, null, null];
        }

        return self::actorFor($identity);
    }

    /**
     * [type, user id, name] for a user account.
     */
    public static function actorFor($identity)
    {
        $type = method_exists($identity, 'isPatientAccount') && $identity->isPatientAccount()
            ? AuditLog::ACTOR_PATIENT
            : AuditLog::ACTOR_STAFF;

        return [$type, (int) $identity->id, $identity->full_name ?: $identity->username];
    }

    /**
     * user component 'on afterLogin'. Automatic sign-ins from the remember-me cookie are not logged.
     */
    public static function onLogin(UserEvent $event)
    {
        if ($event->cookieBased || $event->identity === null) {
            return;
        }
        $method = $event->identity->auth_provider ?? null;
        self::log(AuditLog::ACTION_LOGIN, 'user', $event->identity->id, [], $method ? "via $method" : null, self::actorFor($event->identity));
    }

    /**
     * user component 'on afterLogout'.
     */
    public static function onLogout(UserEvent $event)
    {
        if ($event->identity !== null) {
            self::log(AuditLog::ACTION_LOGOUT, 'user', $event->identity->id, [], null, self::actorFor($event->identity));
        }
    }

    /**
     * A sign-in with a wrong password or unknown username.
     *
     * @param string $username what was typed
     * @param int|null $userId the account, when the username matched one
     */
    public static function loginFailed($username, $userId = null)
    {
        self::log(AuditLog::ACTION_LOGIN_FAILED, $userId !== null ? 'user' : null, $userId, [],
            'username: ' . $username, [AuditLog::ACTOR_GUEST, null, null]);
    }

    private static function ip()
    {
        $request = Yii::$app->get('request', false);
        return $request instanceof \yii\web\Request ? $request->getUserIP() : null;
    }
}
