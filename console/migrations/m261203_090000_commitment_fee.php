<?php

use yii\db\Migration;

/**
 * F22, the Ksh 250 commitment fee (docs/business-case.md):
 *
 * - consultant.plan: A (the patient pays the fee) or B (flat, no fee); every consultant starts on A
 * - consultant.insured_pay: on Plan A, whether insured patients pay; when not, the fee is absorbed
 *   by the consultant and billed to them
 * - appointments.payment_mode: cash or insurance, asked at booking
 * - commitment_fee: one per appointment that carries the fee, paid or absorbed, and what became of it
 */
class m261203_090000_commitment_fee extends Migration
{
    const FEE = '{{%commitment_fee}}';

    public function safeUp()
    {
        $this->addColumn('{{%consultant}}', 'plan', $this->string(1)->notNull()->defaultValue('A')->comment('A: commitment fee; B: flat'));
        $this->addColumn('{{%consultant}}', 'insured_pay', $this->boolean()->notNull()->defaultValue(true)->comment('Plan A: insured patients pay; else the consultant absorbs the fee'));
        $this->addColumn('{{%appointments}}', 'payment_mode', $this->string(10)->null()->comment('cash or insurance'));

        $this->createTable(self::FEE, [
            'id' => $this->primaryKey(),
            'appointment_id' => $this->integer()->notNull(),
            'patient_id' => $this->integer()->notNull(),
            'consultant_id' => $this->integer()->notNull()->comment('user.id, as appointments.consultant_id'),
            'facility_id' => $this->integer()->null(),
            'fee_date' => $this->date()->notNull()->comment('the appointment date: one fee a day per patient'),
            'amount' => $this->integer()->notNull(),
            'payer' => $this->string(12)->notNull()->comment('patient or consultant (absorbed)'),
            'state' => $this->string(12)->notNull()->comment('pending, paid, absorbed, failed, forfeited, transferred, credited, cancelled, refunded'),
            'phone' => $this->string(15)->null()->comment('the phone that was asked to pay'),
            'mpesa_transaction_id' => $this->integer()->null(),
            'paid_at' => $this->integer()->null(),
            'settled_at' => $this->integer()->null()->comment('forfeited, transferred, credited, cancelled or refunded'),
            'note' => $this->string(255)->null(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_by' => $this->integer(),
        ]);
        $this->createIndex('ux-commitment_fee-appointment', self::FEE, 'appointment_id', true);
        $this->createIndex('idx-commitment_fee-patient-date', self::FEE, ['patient_id', 'fee_date', 'state']);
        $this->createIndex('idx-commitment_fee-consultant-state', self::FEE, ['consultant_id', 'state']);
        $this->addForeignKey('fk-commitment_fee-appointment_id', self::FEE, 'appointment_id', '{{%appointments}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable(self::FEE);
        $this->dropColumn('{{%appointments}}', 'payment_mode');
        $this->dropColumn('{{%consultant}}', 'insured_pay');
        $this->dropColumn('{{%consultant}}', 'plan');
    }
}
