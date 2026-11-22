<?php

use yii\db\Migration;

/**
 * F25 part 2: paying an invoice by M-Pesa prompt, reminders, and pausing patients' online booking
 * with a consultant whose invoice is overdue.
 */
class m261214_090000_invoice_payments extends Migration
{
    const INVOICE = '{{%consultant_invoice}}';
    const CONSULTANT = '{{%consultant}}';

    public function safeUp()
    {
        $this->addColumn(self::INVOICE, 'reminders_sent', $this->smallInteger()->notNull()->defaultValue(0)->comment('on the due date, then a week later')->after('reminded_at'));
        $this->addColumn(self::INVOICE, 'pay_phone', $this->string(12)->null()->comment('the phone the last M-Pesa prompt went to')->after('mpesa_transaction_id'));
        $this->addColumn(self::INVOICE, 'pay_note', $this->string(255)->null()->comment('why the last M-Pesa prompt did not go through')->after('pay_phone'));
        $this->addColumn(self::CONSULTANT, 'online_booking_paused_at', $this->integer()->null()->comment('F25: patients cannot book online while an invoice is long overdue'));
    }

    public function safeDown()
    {
        $this->dropColumn(self::CONSULTANT, 'online_booking_paused_at');
        $this->dropColumn(self::INVOICE, 'pay_note');
        $this->dropColumn(self::INVOICE, 'pay_phone');
        $this->dropColumn(self::INVOICE, 'reminders_sent');
    }
}
