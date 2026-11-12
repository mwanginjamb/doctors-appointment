<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A day a facility is shut (F8 phase 2), on top of the clinic-wide public holidays: nobody can be
 * booked there that day. Consultants engaged elsewhere that day are not affected. Appointments
 * already booked are kept for staff to move.
 *
 * @property int $id
 * @property int $facility_id
 * @property string $date Y-m-d
 * @property string $reason shown to patients and staff, e.g. "Staff training"
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property Facility $facility
 */
class FacilityClosure extends ActiveRecord
{
    /** @var array facility id => list of Y-m-d, loaded once per request */
    private static $cache = [];

    public static function tableName()
    {
        return '{{%facility_closure}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'facility_closure'],
        ];
    }

    public function rules()
    {
        return [
            [['date', 'reason'], 'required'],
            [['reason'], 'trim'],
            [['reason'], 'string', 'max' => 150],
            [['date'], 'date', 'format' => 'php:Y-m-d'],
            [['date'], 'compare', 'compareValue' => date('Y-m-d'), 'operator' => '>=', 'type' => 'string',
                'when' => function () {
                    return $this->isNewRecord;
                }, 'message' => Yii::t('app', 'Choose today or a later day.')],
            [['date'], 'unique', 'targetAttribute' => ['facility_id', 'date'], 'message' => Yii::t('app', 'The facility is already closed that day.')],
        ];
    }

    public function scenarios()
    {
        $scenarios = parent::scenarios();
        // Which facility comes from the page, never from the form
        $scenarios[self::SCENARIO_DEFAULT][] = '!facility_id';

        return $scenarios;
    }

    public function attributeLabels()
    {
        return [
            'date' => Yii::t('app', 'Date'),
            'reason' => Yii::t('app', 'Reason'),
        ];
    }

    public function attributeHints()
    {
        return [
            'reason' => Yii::t('app', 'Patients and staff see it, e.g. "Staff training" or "Power maintenance".'),
        ];
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);
        self::reset();
    }

    public function afterDelete()
    {
        parent::afterDelete();
        self::reset();
    }

    /**
     * The closure of a facility on a date (Y-m-d), or null when it is open.
     */
    public static function findOn($facilityId, $date)
    {
        return static::findOne(['facility_id' => $facilityId, 'date' => $date]);
    }

    /**
     * Whether the facility is shut on $date. The facility's closures are read once per request.
     */
    public static function isClosed($facilityId, $date)
    {
        $facilityId = (int) $facilityId;
        if (!isset(self::$cache[$facilityId])) {
            self::$cache[$facilityId] = static::find()->select('date')->where(['facility_id' => $facilityId])
                ->andWhere(['>=', 'date', date('Y-m-d', strtotime('-1 year'))])->column();
        }

        return in_array($date, self::$cache[$facilityId], true);
    }

    /**
     * Closures of these facilities between two dates (Y-m-d, inclusive), earliest first.
     *
     * @param int[] $facilityIds
     * @return static[]
     */
    public static function between(array $facilityIds, $from, $to)
    {
        if (!$facilityIds) {
            return [];
        }

        return static::find()->where(['facility_id' => $facilityIds])->andWhere(['between', 'date', $from, $to])
            ->orderBy(['date' => SORT_ASC])->all();
    }

    /** Forgets the closures read so far (after they change, and between tests). */
    public static function reset()
    {
        self::$cache = [];
    }
}
