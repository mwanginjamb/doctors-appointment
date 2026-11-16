<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * F20: the pilot agreement a facility signed (docs/poc-commercial-agreement.html). Signing gives the
 * facility access; it starts no clock. Each consultant's pilot starts at their first booking
 * (see frontend\services\Pilot). A newer row replaces an older one: the latest signed is current.
 *
 * @property int $id
 * @property int $facility_id
 * @property string $version e.g. 1.2
 * @property string $signed_on Y-m-d
 * @property string $facility_signatory name and title
 * @property string $platform_signatory
 * @property int $walk_in_exempt same-day walk-ins at reception pay no commitment fee
 * @property int|null $standard_terms_confirmed_at step 1: the facility (or a platform admin for it) confirmed standard terms
 * @property int|null $standard_terms_confirmed_by user.id; null when confirmed from the console
 * @property int|null $standard_terms_approved_at step 2: a platform admin approved; standard terms apply from then
 * @property int|null $standard_terms_approved_by user.id; null when approved from the console
 * @property int|null $notice_upcoming_sent_at day 81 notice sent
 * @property int|null $notice_grace_sent_at day 91 notice sent
 * @property int|null $notice_overdue_sent_at day 121 notice to platform admins sent
 * @property string|null $ended_on the facility left
 * @property string|null $notes
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property Facility $facility
 */
class FacilityAgreement extends ActiveRecord
{
    /** Versions of the agreement that exist; v1.2 added the Ksh 100 cancellation charge. */
    const VERSIONS = ['1.1.0', '1.2'];

    public static function tableName()
    {
        return '{{%facility_agreement}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'facility_agreement'],
        ];
    }

    public function rules()
    {
        return [
            [['facility_id', 'version', 'signed_on', 'facility_signatory', 'platform_signatory'], 'required'],
            [['facility_id'], 'integer'],
            [['facility_id'], 'exist', 'targetClass' => Facility::class, 'targetAttribute' => ['facility_id' => 'id']],
            [['version'], 'in', 'range' => self::VERSIONS],
            [['signed_on', 'ended_on'], 'date', 'format' => 'php:Y-m-d'],
            [['facility_signatory', 'platform_signatory'], 'trim'],
            [['facility_signatory', 'platform_signatory'], 'string', 'max' => 150],
            [['walk_in_exempt'], 'boolean'],
            [['walk_in_exempt'], 'default', 'value' => 1],
            [['notes'], 'string'],
        ];
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    /** The facility's current agreement: the latest signed; null when it signed none. */
    public static function currentFor($facilityId)
    {
        return static::find()->where(['facility_id' => $facilityId])
            ->orderBy(['signed_on' => SORT_DESC, 'id' => SORT_DESC])->one();
    }

    /** Whether this version includes the Ksh 100 cancellation charge (from v1.2). */
    public function hasCancellationCharge()
    {
        return version_compare($this->version, '1.2', '>=');
    }

    public function attributeLabels()
    {
        return [
            'facility_id' => Yii::t('app', 'Facility'),
            'version' => Yii::t('app', 'Version signed'),
            'signed_on' => Yii::t('app', 'Signed on'),
            'facility_signatory' => Yii::t('app', 'Signed for the facility by'),
            'platform_signatory' => Yii::t('app', 'Signed for the platform by'),
            'walk_in_exempt' => Yii::t('app', 'Same-day walk-ins pay no commitment fee'),
            'standard_terms_confirmed_at' => Yii::t('app', 'Standard terms confirmed'),
            'ended_on' => Yii::t('app', 'Left on'),
        ];
    }
}
