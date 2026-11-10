<?php

namespace common\models;

use yii\db\ActiveRecord;

/**
 * The latest result of one health check (T2), kept between runs of `php yii health/check`.
 *
 * @property string $check_key
 * @property bool $failing
 * @property string|null $message
 * @property int|null $since when it started failing
 * @property int $checked_at
 * @property int|null $alerted_at last alert email about it
 */
class HealthState extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%health_state}}';
    }

    public static function primaryKey()
    {
        return ['check_key'];
    }
}
