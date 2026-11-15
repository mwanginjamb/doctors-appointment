<?php

use yii\db\Migration;

/**
 * T11 M-Pesa (Daraja): one row per payment request the platform makes. "stk" is a patient paying
 * the platform by STK push; "b2c" is the platform paying someone (refunds, front-desk payouts).
 * Safaricom's answer arrives later, by callback; the row records what was asked and what came back.
 * link_type / link_id say what the money is for (for example a commitment fee), filled in by the
 * feature that asks for the payment.
 */
class m261130_090000_create_mpesa_transaction_table extends Migration
{
    const TABLE = '{{%mpesa_transaction}}';

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'kind' => $this->string(3)->notNull()->comment('stk: patient pays us; b2c: we pay out'),
            'status' => $this->string(10)->notNull()->defaultValue('pending')
                ->comment('pending, succeeded, failed, cancelled, timed_out'),
            'phone' => $this->string(12)->notNull()->comment('2547XXXXXXXX'),
            'amount' => $this->integer()->notNull()->comment('whole shillings'),
            'reference' => $this->string(20)->notNull()->comment('account reference (STK) or occasion (B2C)'),
            'description' => $this->string(100)->notNull(),
            'merchant_request_id' => $this->string(64)->null(),
            'checkout_request_id' => $this->string(64)->null()->comment('STK: the key callbacks and queries use'),
            'originator_conversation_id' => $this->string(64)->null()->comment('B2C: our id for the request'),
            'conversation_id' => $this->string(64)->null(),
            'receipt' => $this->string(20)->null()->comment('M-Pesa receipt / transaction id once it succeeds'),
            'result_code' => $this->string(20)->null(),
            'result_desc' => $this->string(255)->null(),
            'callback' => $this->text()->null()->comment('the last callback body, as received'),
            'link_type' => $this->string(30)->null()->comment('what the money is for, e.g. commitment_fee'),
            'link_id' => $this->integer()->null(),
            'settled_at' => $this->integer()->null()->comment('when the final status was recorded'),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer()->null(),
        ]);
        $this->createIndex('ux-mpesa_transaction-checkout_request_id', self::TABLE, 'checkout_request_id', true);
        $this->createIndex('ux-mpesa_transaction-originator_conversation_id', self::TABLE, 'originator_conversation_id', true);
        $this->createIndex('ux-mpesa_transaction-receipt', self::TABLE, 'receipt', true);
        $this->createIndex('idx-mpesa_transaction-status', self::TABLE, ['kind', 'status']);
        $this->createIndex('idx-mpesa_transaction-link', self::TABLE, ['link_type', 'link_id']);
    }

    public function safeDown()
    {
        $this->dropTable(self::TABLE);
    }
}
