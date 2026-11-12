<?php

namespace common\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * One audit entry: who did what, to which record, and when. Written through common\components\Audit.
 *
 * Entries are only ever added: updating or deleting one through the model is refused. Old entries
 * are removed in bulk by `php yii audit/purge` once past the retention period.
 *
 * @property int $id
 * @property int $created_at
 * @property int|null $actor_id
 * @property string $actor_type
 * @property string|null $actor_name
 * @property string $action
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property string|null $changes JSON: field => [old, new]
 * @property string|null $note
 * @property string|null $ip
 * @property int|null $facility_id the facility the entry is about, when it is about one (F8 phase 2)
 */
class AuditLog extends ActiveRecord
{
    const ACTOR_STAFF = 'staff';
    const ACTOR_PATIENT = 'patient';
    const ACTOR_BOOKING_LINK = 'booking_link';
    const ACTOR_SELF_SERVICE = 'self_service';
    const ACTOR_SYSTEM = 'system';
    const ACTOR_GUEST = 'guest';

    const ACTION_CREATED = 'created';
    const ACTION_UPDATED = 'updated';
    const ACTION_DELETED = 'deleted';
    const ACTION_LOGIN = 'login';
    const ACTION_LOGIN_FAILED = 'login_failed';
    const ACTION_LOGOUT = 'logout';
    const ACTION_HOURS_CHANGED = 'hours_changed';
    const ACTION_ASSIGNMENTS_CHANGED = 'assignments_changed';
    const ACTION_ROLES_CHANGED = 'roles_changed';
    const ACTION_RBAC_CHANGED = 'rbac_changed';
    const ACTION_PURGED = 'purged';
    const ACTION_CONSENT_GRANTED = 'consent_granted';
    const ACTION_CONSENT_WITHDRAWN = 'consent_withdrawn';

    /** Written in place of a value that must not be kept (password hashes, health details). */
    const MASKED = '[changed]';

    public static function tableName()
    {
        return '{{%audit_log}}';
    }

    public function beforeSave($insert)
    {
        // Append-only
        return $insert && parent::beforeSave($insert);
    }

    public function beforeDelete()
    {
        return false;
    }

    /**
     * @return array field => [old, new]
     */
    public function getChangeList()
    {
        $changes = $this->changes ? json_decode($this->changes, true) : [];
        return is_array($changes) ? $changes : [];
    }

    public static function actorTypes()
    {
        return [
            self::ACTOR_STAFF => 'Staff',
            self::ACTOR_PATIENT => 'Patient',
            self::ACTOR_BOOKING_LINK => 'Booking link',
            self::ACTOR_SELF_SERVICE => 'Self-service link',
            self::ACTOR_SYSTEM => 'System',
            self::ACTOR_GUEST => 'Not signed in',
        ];
    }

    public static function actions()
    {
        return [
            self::ACTION_CREATED => 'Created',
            self::ACTION_UPDATED => 'Updated',
            self::ACTION_DELETED => 'Deleted',
            self::ACTION_LOGIN => 'Signed in',
            self::ACTION_LOGIN_FAILED => 'Failed sign-in',
            self::ACTION_LOGOUT => 'Signed out',
            self::ACTION_HOURS_CHANGED => 'Working hours changed',
            self::ACTION_ASSIGNMENTS_CHANGED => 'Front-desk assignments changed',
            self::ACTION_ROLES_CHANGED => 'Roles changed',
            self::ACTION_RBAC_CHANGED => 'Roles/permissions edited',
            self::ACTION_PURGED => 'Old entries purged',
            self::ACTION_CONSENT_GRANTED => 'Consent given',
            self::ACTION_CONSENT_WITHDRAWN => 'Consent withdrawn',
        ];
    }

    public static function entityTypes()
    {
        return [
            'appointment' => 'Appointment',
            'user' => 'Account',
            'consultant' => 'Consultant',
            'time_off' => 'Time off',
            'holiday' => 'Public holiday',
            'facility' => 'Facility',
            'engagement' => 'Facility engagement',
            'facility_request' => 'Facility request',
            'facility_closure' => 'Facility closed day',
            'facility_invitation' => 'Facility invitation',
            'booking_link' => 'Booking link',
            'rbac' => 'Role / permission',
            'audit_log' => 'Audit log',
            'closure_request' => 'Account closure request',
        ];
    }

    public function getActorTypeLabel()
    {
        return self::actorTypes()[$this->actor_type] ?? $this->actor_type;
    }

    public function getActionLabel()
    {
        return self::actions()[$this->action] ?? $this->action;
    }

    public function getEntityLabel()
    {
        if ($this->entity_type === null) {
            return '';
        }
        $type = self::entityTypes()[$this->entity_type] ?? $this->entity_type;
        return $this->entity_id !== null ? "$type #{$this->entity_id}" : $type;
    }

    /**
     * Entries about one record, newest first.
     *
     * @return static[]
     */
    public static function forEntity($entityType, $entityId)
    {
        return static::find()
            ->where(['entity_type' => $entityType, 'entity_id' => (string) $entityId])
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->all();
    }

    /**
     * Retention period in years (audit.retentionYears).
     */
    public static function retentionYears()
    {
        return max(1, (int) (Yii::$app->params['audit.retentionYears'] ?? 7));
    }
}
