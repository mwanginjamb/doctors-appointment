<?php

namespace frontend\models;

use common\models\User;
use yii\db\ActiveRecord;

/**
 * A consultant's claim to run a facility, so they also become its facility admin
 * (see frontend\services\FacilityAdmins).
 *
 * @property int $id
 * @property int $user_id the consultant's user.id
 * @property int|null $facility_id null while it waits for its facility request
 * @property int|null $facility_request_id
 * @property string $status
 * @property string|null $decision_note
 * @property int|null $decided_by
 * @property int|null $decided_at
 * @property int $created_at
 *
 * @property User $user
 * @property Facility|null $facility
 * @property FacilityRequest|null $facilityRequest
 * @property User|null $decider
 */
class FacilityAdminClaim extends ActiveRecord
{
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_DECLINED = 'declined';
    const STATUS_REVOKED = 'revoked';
    /** Its facility request was declined, or the consultant withdrew it */
    const STATUS_CANCELLED = 'cancelled';

    public static function tableName()
    {
        return '{{%facility_admin_claim}}';
    }

    public function behaviors()
    {
        return ['audit' => ['class' => \common\behaviors\AuditBehavior::class, 'entityType' => 'facility_admin_claim']];
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    public function getFacilityRequest()
    {
        return $this->hasOne(FacilityRequest::class, ['id' => 'facility_request_id']);
    }

    public function getDecider()
    {
        return $this->hasOne(User::class, ['id' => 'decided_by']);
    }

    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** The facility's name, or the requested one while it is not listed yet. */
    public function facilityName()
    {
        return $this->facility->name ?? ($this->facilityRequest->name ?? '');
    }
}
