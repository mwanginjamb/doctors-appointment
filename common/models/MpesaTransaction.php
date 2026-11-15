<?php

namespace common\models;

use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * One M-Pesa payment request (T11): an STK push a patient answers on their phone, or a B2C payment
 * the platform makes. Created by {@see \common\components\Mpesa}; settled by Safaricom's callback,
 * or by a status query when the callback does not come.
 *
 * @property int $id
 * @property string $kind stk | b2c
 * @property string $status pending | succeeded | failed | cancelled | timed_out
 * @property string $phone 2547XXXXXXXX / 2541XXXXXXXX
 * @property int $amount whole shillings
 * @property string $reference
 * @property string $description
 * @property string|null $merchant_request_id
 * @property string|null $checkout_request_id
 * @property string|null $originator_conversation_id
 * @property string|null $conversation_id
 * @property string|null $receipt
 * @property string|null $result_code
 * @property string|null $result_desc
 * @property string|null $callback
 * @property string|null $link_type
 * @property int|null $link_id
 * @property int|null $settled_at
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 */
class MpesaTransaction extends ActiveRecord
{
    const KIND_STK = 'stk';
    const KIND_B2C = 'b2c';

    const STATUS_PENDING = 'pending';
    const STATUS_SUCCEEDED = 'succeeded';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_TIMED_OUT = 'timed_out';

    public static function tableName()
    {
        return '{{%mpesa_transaction}}';
    }

    public function init()
    {
        parent::init();
        // The column's default is not copied onto a new object, and "not pending" means settled
        if ($this->status === null) {
            $this->status = self::STATUS_PENDING;
        }
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            ['class' => BlameableBehavior::class, 'updatedByAttribute' => false],
        ];
    }

    public function rules()
    {
        return [
            [['kind', 'phone', 'amount', 'reference', 'description'], 'required'],
            ['kind', 'in', 'range' => [self::KIND_STK, self::KIND_B2C]],
            ['status', 'in', 'range' => [self::STATUS_PENDING, self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_TIMED_OUT]],
            ['phone', 'match', 'pattern' => '/^254[17]\d{8}$/'],
            ['amount', 'integer', 'min' => 1],
            [['reference'], 'string', 'max' => 20],
            [['description'], 'string', 'max' => 100],
            [['link_type'], 'string', 'max' => 30],
            [['link_id'], 'integer'],
        ];
    }

    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSettled()
    {
        return !$this->isPending();
    }
}
