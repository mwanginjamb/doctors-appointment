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
 * A consultant's request for a facility that is not listed yet. An admin either creates it (the
 * facility is listed and the consultant is engaged there) or declines it, and the consultant is
 * told by email and WhatsApp either way.
 *
 * @property int $id
 * @property int $consultant_id consultant.id who asked
 * @property string $name
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $map_url
 * @property string|null $note from the consultant to the admin
 * @property string $status
 * @property int|null $facility_id the facility created for it
 * @property string|null $decision_note why it was declined; sent to the consultant
 * @property int|null $decided_at
 * @property int|null $decided_by
 * @property int|null $created_at
 * @property int|null $created_by
 *
 * @property Consultant $consultant
 * @property Facility|null $facility
 */
class FacilityRequest extends ActiveRecord
{
    const STATUS_PENDING = 'pending';
    const STATUS_CREATED = 'created';
    const STATUS_DECLINED = 'declined';

    /** A consultant may have this many requests waiting at once. */
    const MAX_PENDING = 5;

    public static function tableName()
    {
        return '{{%facility_request}}';
    }

    public function behaviors()
    {
        return [
            'blame' => ['class' => BlameableBehavior::class, 'updatedByAttribute' => false],
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'facility_request'],
        ];
    }

    public function rules()
    {
        return [
            [['name', 'address', 'phone', 'map_url', 'note'], 'trim'],
            [['name'], 'required'],
            [['name'], 'string', 'max' => 150],
            [['address'], 'string', 'max' => 1000],
            [['note'], 'string', 'max' => 500],
            [['address', 'phone', 'map_url', 'note'], 'default', 'value' => null],
            [['phone'], 'match', 'pattern' => '/^\+?[0-9][0-9 ]{6,18}$/', 'message' => Yii::t('app', 'Enter a phone number such as 0712 345 678.')],
            [['map_url'], 'url', 'defaultScheme' => 'https'],
            [['map_url'], 'string', 'max' => 500],
            [['name'], 'validateNotListed', 'skipOnError' => true],
            [['name'], 'validateNotPending', 'skipOnError' => true],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        // Who asked and what became of it are set here, never from the form
        return [self::SCENARIO_DEFAULT => ['name', 'address', 'phone', 'map_url', 'note']];
    }

    public function attributeLabels()
    {
        return [
            'name' => Yii::t('app', 'Facility name'),
            'address' => Yii::t('app', 'Address'),
            'phone' => Yii::t('app', 'Phone'),
            'map_url' => Yii::t('app', 'Map link'),
            'note' => Yii::t('app', 'Note to the administrator'),
            'decision_note' => Yii::t('app', 'Reason'),
        ];
    }

    /** A listed facility is chosen from the list, not requested again. */
    public function validateNotListed($attribute)
    {
        $listed = Facility::find()->where(['name' => $this->$attribute])->one();
        if ($listed !== null) {
            $this->addError($attribute, $listed->isActive()
                ? Yii::t('app', '{name} is already listed: choose it from the list.', ['name' => $listed->name])
                : Yii::t('app', '{name} is listed but not taking bookings. Ask the administrator to reopen it.', ['name' => $listed->name]));
        }
    }

    public function validateNotPending($attribute)
    {
        $pending = static::find()->where(['consultant_id' => $this->consultant_id, 'status' => self::STATUS_PENDING]);
        if ((clone $pending)->andWhere(['name' => $this->$attribute])->exists()) {
            $this->addError($attribute, Yii::t('app', 'You have already asked for {name}.', ['name' => $this->$attribute]));
        } elseif ($pending->count() >= self::MAX_PENDING) {
            $this->addError($attribute, Yii::t('app', 'You have {n} requests waiting. Please wait for the administrator to answer them.', ['n' => self::MAX_PENDING]));
        }
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    public static function pendingCount()
    {
        return (int) static::find()->where(['status' => self::STATUS_PENDING])->count();
    }

    /**
     * A facility form filled in from the request, for the admin to check before creating it.
     */
    public function toFacility()
    {
        return new Facility([
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'map_url' => $this->map_url,
            'active' => true,
        ]);
    }

    /**
     * Lists $facility (validated and saved here), engages the consultant there with their default
     * session length, closes the request and tells the consultant.
     *
     * @return bool false when the facility is invalid (see its errors) or the request was answered already
     */
    public function approve(Facility $facility)
    {
        if (!$this->isPending()) {
            return false;
        }

        $transaction = static::getDb()->beginTransaction();
        try {
            if (!$facility->save()) {
                $transaction->rollBack();
                return false;
            }
            $consultant = $this->consultant;
            if (!ConsultantFacility::find()->where(['consultant_id' => $consultant->id, 'facility_id' => $facility->id])->exists()) {
                (new ConsultantFacility([
                    'consultant_id' => $consultant->id,
                    'facility_id' => $facility->id,
                    'session_minutes' => $consultant->getSessionMinutes(),
                    'booking_open' => true,
                ]))->save(false);
            }
            $this->close(self::STATUS_CREATED, null, $facility->id);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->notify();

        return true;
    }

    /**
     * Closes the request without listing a facility and tells the consultant why.
     *
     * @param string|null $reason sent to the consultant
     * @return bool false when the request was answered already or the reason is too long
     */
    public function decline($reason = null)
    {
        if (!$this->isPending()) {
            return false;
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) > 500) {
            $this->addError('decision_note', Yii::t('app', 'Keep the reason under 500 characters.'));
            return false;
        }

        $this->close(self::STATUS_DECLINED, $reason === '' ? null : $reason);
        $this->notify();

        return true;
    }

    private function close($status, $note = null, $facilityId = null)
    {
        $user = Yii::$app->get('user', false);
        $this->status = $status;
        $this->decision_note = $note;
        $this->facility_id = $facilityId;
        $this->decided_at = time();
        $this->decided_by = $user && !$user->isGuest ? $user->id : null;
        $this->save(false);
    }

    /**
     * Tells the consultant what became of their request, by email and WhatsApp.
     *
     * @return string[] channels queued
     */
    public function notify()
    {
        $consultant = $this->consultant;
        if ($consultant === null) {
            return [];
        }
        $doctor = $consultant->names ?: 'Doctor';
        $url = self::availabilityUrl();

        if ($this->status === self::STATUS_CREATED) {
            $name = $this->facility->name ?? $this->name;
            $subject = "{$name} has been added";
            $text = "Hello {$doctor},\n\n{$name} is now listed, as you asked, and has been added to your facilities "
                . 'with your usual session length. Add your hours there, and its fee, on your Availability page'
                . ($url ? ": {$url}" : '.');
        } else {
            $subject = "Your request for {$this->name}";
            $text = "Hello {$doctor},\n\nYour request to list {$this->name} as a facility was declined."
                . ($this->decision_note ? "\n\nReason: {$this->decision_note}" : '')
                . "\n\nPlease contact the clinic administrator if you have questions.";
        }

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
