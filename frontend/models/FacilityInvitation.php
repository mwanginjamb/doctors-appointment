<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\db\ActiveRecord;
use yii\helpers\Url;

/**
 * An invitation to a consultant to work at a facility. It is sent when an admin (a facility admin,
 * or a consultancy admin) ticks the consultant for a front-desk account at a facility where they do
 * not work yet.
 *
 * The consultant accepts by adding the facility on their Availability page, with their own session
 * length and fee there; front desk can book for them once they have hours there. Declining removes
 * the consultant from that facility's front desk.
 *
 * @property int $id
 * @property int $facility_id
 * @property int $consultant_id consultant.id
 * @property string $status pending, accepted, declined
 * @property int|null $decided_at
 * @property int|null $created_at
 * @property int|null $created_by the admin who invited
 *
 * @property Facility $facility
 * @property Consultant $consultant
 */
class FacilityInvitation extends ActiveRecord
{
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_DECLINED = 'declined';

    public static function tableName()
    {
        return '{{%facility_invitation}}';
    }

    public function behaviors()
    {
        return [
            'blame' => ['class' => BlameableBehavior::class, 'updatedByAttribute' => false],
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'facility_invitation'],
        ];
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }

    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Invites the consultant to work at the facility, unless they already work there or an
     * invitation is waiting. The consultant is told by email and WhatsApp.
     *
     * @return static|null the new invitation, or null when none was needed
     */
    public static function invite($facilityId, $consultantId)
    {
        $engaged = ConsultantFacility::find()->where(['facility_id' => $facilityId, 'consultant_id' => $consultantId])->exists();
        if ($engaged || static::find()->where(['facility_id' => $facilityId, 'consultant_id' => $consultantId, 'status' => self::STATUS_PENDING])->exists()) {
            return null;
        }
        $invitation = new static([
            'facility_id' => (int) $facilityId,
            'consultant_id' => (int) $consultantId,
            'status' => self::STATUS_PENDING,
            'created_at' => time(),
        ]);
        $invitation->save(false);
        $invitation->notify();

        return $invitation;
    }

    /**
     * Marks the consultant's waiting invitation to a facility accepted: called when they add it.
     */
    public static function acceptFor($consultantId, $facilityId)
    {
        foreach (static::find()->where(['consultant_id' => $consultantId, 'facility_id' => $facilityId, 'status' => self::STATUS_PENDING])->all() as $invitation) {
            $invitation->status = self::STATUS_ACCEPTED;
            $invitation->decided_at = time();
            $invitation->save(false);
        }
    }

    /**
     * The consultant does not work there. Front desk at the facility no longer books for them.
     */
    public function decline()
    {
        if (!$this->isPending()) {
            return false;
        }
        $this->status = self::STATUS_DECLINED;
        $this->decided_at = time();
        $this->save(false);

        $frontDesk = (new \yii\db\Query())->select('id')->from('{{%user}}')
            ->where(['role' => \common\models\User::ACCOUNT_FRONT_DESK, 'facility_id' => $this->facility_id])->column();
        foreach ($frontDesk as $userId) {
            $ids = FrontDeskAssignment::consultantIdsFor($userId);
            if (in_array((int) $this->consultant_id, $ids, true)) {
                FrontDeskAssignment::replaceFor($userId, array_values(array_diff($ids, [(int) $this->consultant_id])));
            }
        }

        return true;
    }

    /**
     * Waiting invitations of a consultant, oldest first.
     *
     * @return static[]
     */
    public static function pendingFor($consultantId)
    {
        return static::find()->where(['consultant_id' => $consultantId, 'status' => self::STATUS_PENDING])
            ->with('facility')->orderBy(['created_at' => SORT_ASC])->all();
    }

    /**
     * Consultant ids with an invitation to the facility waiting.
     *
     * @return int[]
     */
    public static function pendingConsultantIds($facilityId)
    {
        return array_map('intval', static::find()->select('consultant_id')
            ->where(['facility_id' => $facilityId, 'status' => self::STATUS_PENDING])->column());
    }

    /**
     * Tells the consultant they are invited, by email and WhatsApp.
     *
     * @return string[] channels queued
     */
    public function notify()
    {
        $consultant = $this->consultant;
        $facility = $this->facility;
        if ($consultant === null || $facility === null) {
            return [];
        }
        $doctor = $consultant->names ?: 'Doctor';
        $url = self::availabilityUrl();
        $subject = "{$facility->name} invites you to see patients there";
        $text = "Hello {$doctor},\n\n{$facility->name} would like its front desk to book patients for you there. "
            . 'If you work there, add it under Facilities on your Availability page, with your session length, '
            . 'fee and hours there; or decline the invitation on the same page'
            . ($url ? ": {$url}" : '.');

        $channels = [];
        if ($consultant->consultant_email) {
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $consultant->consultant_email,
                'toName' => $doctor,
                'subject' => $subject,
                'text' => $text,
            ]));
            $channels[] = 'email';
        }
        if ($consultant->consultant_phone_number) {
            Yii::$app->queue->push(new SendWhatsappTextJob(['phone' => $consultant->consultant_phone_number, 'text' => $text]));
            $channels[] = 'whatsapp';
        }

        return $channels;
    }

    /** The consultant's Availability page, when there is a public address to link to. */
    private static function availabilityUrl()
    {
        $base = rtrim((string) (Yii::$app->params['app.publicUrl'] ?? ''), '/');
        if ($base !== '') {
            return $base . '/availability/index#facilities';
        }
        try {
            return Url::to(['/availability/index', '#' => 'facilities'], true);
        } catch (\Throwable $e) {
            return null; // console without a request
        }
    }
}
