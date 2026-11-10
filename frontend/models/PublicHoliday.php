<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A clinic-wide public holiday: no consultant can be booked on this date.
 *
 * @property int $id
 * @property string $date Y-m-d
 * @property string $name
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class PublicHoliday extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%public_holiday}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'holiday'],
        ];
    }

    public function rules()
    {
        return [
            [['date', 'name'], 'required'],
            [['name'], 'trim'],
            [['name'], 'string', 'max' => 150],
            [['date'], 'date', 'format' => 'php:Y-m-d'],
            [['date'], 'unique', 'message' => Yii::t('app', 'There is already a holiday on this date.')],
        ];
    }

    public function attributeLabels()
    {
        return [
            'date' => Yii::t('app', 'Date'),
            'name' => Yii::t('app', 'Holiday'),
        ];
    }

    /**
     * The holiday on a date (Y-m-d), or null.
     */
    public static function findOnDate($date)
    {
        return static::findOne(['date' => $date]);
    }

    /**
     * Holidays between two dates (Y-m-d, inclusive), oldest first.
     *
     * @return static[]
     */
    public static function between($from, $to)
    {
        return static::find()->where(['between', 'date', $from, $to])->orderBy(['date' => SORT_ASC])->all();
    }
}
