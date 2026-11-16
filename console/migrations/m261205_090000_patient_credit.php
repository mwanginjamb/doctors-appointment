<?php

use yii\db\Migration;

/**
 * F23: rebooking credits. A paid commitment fee becomes a credit when the clinic cancels, or when
 * the patient cancels more than 24 hours ahead; also when a payment arrives after its time was
 * taken. A credit pays the fee of the patient's next booking, with any consultant, within 90
 * days. Credits from the clinic's side (or a late payment) can be refunded to M-Pesa instead, on
 * the patient's request and a platform admin's approval.
 */
class m261205_090000_patient_credit extends Migration
{
    const TABLE = '{{%patient_credit}}';

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'patient_id' => $this->integer()->notNull(),
            'amount' => $this->integer()->notNull(),
            'source_fee_id' => $this->integer()->notNull()->comment('the commitment fee it came from'),
            'reason' => $this->string(20)->notNull()->comment('clinic_cancelled, patient_cancelled or late_payment'),
            'refundable' => $this->boolean()->notNull()->defaultValue(false)->comment('the patient may ask for it back by M-Pesa'),
            'status' => $this->string(20)->notNull()->comment('available, used, expired, refund_requested, refunded, refund_rejected'),
            'expires_at' => $this->integer()->notNull(),
            'used_fee_id' => $this->integer()->null()->comment('the fee it paid'),
            'used_at' => $this->integer()->null(),
            'refund_requested_at' => $this->integer()->null(),
            'refund_phone' => $this->string(15)->null(),
            'refund_decided_by' => $this->integer()->null()->comment('the platform admin who approved or rejected'),
            'refund_decided_at' => $this->integer()->null(),
            'refund_mpesa_transaction_id' => $this->integer()->null()->comment('B2C, when sent through the app'),
            'refund_reference' => $this->string(40)->null()->comment('the M-Pesa receipt or reference'),
            'refunded_at' => $this->integer()->null(),
            'note' => $this->string(255)->null(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_by' => $this->integer(),
        ]);
        $this->createIndex('idx-patient_credit-patient-status', self::TABLE, ['patient_id', 'status', 'expires_at']);
        $this->createIndex('idx-patient_credit-status', self::TABLE, 'status');
        $this->createIndex('ux-patient_credit-source', self::TABLE, 'source_fee_id', true);
    }

    public function safeDown()
    {
        $this->dropTable(self::TABLE);
    }
}
