<?php

use yii\db\Migration;

/**
 * F24, the Ksh 50 front-desk incentive (docs/business-case.md): one per Plan A booking a front-desk
 * user made ahead whose commitment fee was paid or absorbed; earned when the patient attends; paid
 * weekly, grouped per front-desk user into a payout.
 */
class m261206_090000_frontdesk_incentive extends Migration
{
    const INCENTIVE = '{{%frontdesk_incentive}}';
    const PAYOUT = '{{%incentive_payout}}';

    public function safeUp()
    {
        $this->createTable(self::PAYOUT, [
            'id' => $this->primaryKey(),
            'front_desk_id' => $this->integer()->notNull()->comment('user.id'),
            'period_start' => $this->date()->notNull()->comment('Monday'),
            'period_end' => $this->date()->notNull()->comment('Sunday'),
            'amount' => $this->integer()->notNull(),
            'incentives' => $this->integer()->notNull(),
            'status' => $this->string(12)->notNull()->comment('ready, held (no phone), sending, paid'),
            'phone' => $this->string(15)->null(),
            'mpesa_transaction_id' => $this->integer()->null()->comment('B2C, when sent through the app'),
            'reference' => $this->string(40)->null()->comment('the M-Pesa receipt or reference'),
            'paid_at' => $this->integer()->null(),
            'paid_by' => $this->integer()->null()->comment('the platform admin who sent or recorded it'),
            'note' => $this->string(255)->null(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
        ]);
        $this->createIndex('ux-incentive_payout-user-period', self::PAYOUT, ['front_desk_id', 'period_start'], true);
        $this->createIndex('idx-incentive_payout-status', self::PAYOUT, 'status');

        $this->createTable(self::INCENTIVE, [
            'id' => $this->primaryKey(),
            'appointment_id' => $this->integer()->notNull(),
            'fee_id' => $this->integer()->notNull(),
            'front_desk_id' => $this->integer()->notNull()->comment('user.id: who booked it'),
            'patient_id' => $this->integer()->notNull(),
            'facility_id' => $this->integer()->null(),
            'amount' => $this->integer()->notNull(),
            'state' => $this->string(12)->notNull()->comment('pending, earned, cancelled, paid'),
            'flagged' => $this->boolean()->notNull()->defaultValue(false)->comment('cancel-and-rebook of the same patient within 24 hours'),
            'earned_at' => $this->integer()->null(),
            'payout_id' => $this->integer()->null(),
            'note' => $this->string(255)->null(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
        ]);
        $this->createIndex('ux-frontdesk_incentive-appointment', self::INCENTIVE, 'appointment_id', true);
        $this->createIndex('idx-frontdesk_incentive-user-state', self::INCENTIVE, ['front_desk_id', 'state', 'earned_at']);
        $this->addForeignKey('fk-frontdesk_incentive-appointment_id', self::INCENTIVE, 'appointment_id', '{{%appointments}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-frontdesk_incentive-payout_id', self::INCENTIVE, 'payout_id', self::PAYOUT, 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable(self::INCENTIVE);
        $this->dropTable(self::PAYOUT);
    }
}
