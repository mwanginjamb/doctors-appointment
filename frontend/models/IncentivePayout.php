<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * F24: one front-desk user's earned incentives for a week (Monday to Sunday), paid together by
 * M-Pesa to the phone on their account (see Incentives).
 *
 * @property int $id
 * @property int $front_desk_id
 * @property string $period_start Y-m-d, a Monday
 * @property string $period_end Y-m-d, the Sunday after
 * @property int $amount
 * @property int $incentives how many
 * @property string $status ready, held, sending or paid
 * @property string|null $phone
 * @property int|null $mpesa_transaction_id
 * @property string|null $reference
 * @property int|null $paid_at
 * @property int|null $paid_by
 * @property string|null $note
 * @property int|null $created_at
 * @property int|null $updated_at
 *
 * @property User $frontDesk
 * @property FrontdeskIncentive[] $items
 */
class IncentivePayout extends ActiveRecord
{
    const STATUS_READY = 'ready';
    /** No Kenyan phone on the account: fix the account, then it is ready again */
    const STATUS_HELD = 'held';
    /** Sent by B2C, waiting for M-Pesa's answer */
    const STATUS_SENDING = 'sending';
    const STATUS_PAID = 'paid';

    public static function tableName()
    {
        return '{{%incentive_payout}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'incentive_payout'],
        ];
    }

    public function getFrontDesk()
    {
        return $this->hasOne(User::class, ['id' => 'front_desk_id']);
    }

    public function getItems()
    {
        return $this->hasMany(FrontdeskIncentive::class, ['payout_id' => 'id']);
    }

    public static function statusLabels()
    {
        return [
            self::STATUS_READY => 'Ready to pay',
            self::STATUS_HELD => 'Held: no M-Pesa number on the account',
            self::STATUS_SENDING => 'Sent, waiting for M-Pesa',
            self::STATUS_PAID => 'Paid',
        ];
    }
}
