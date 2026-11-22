<?php

use yii\db\Migration;

/**
 * F25, consultants' monthly invoices (docs/business-case.md, agreement v1.2 §3–§5). One invoice a
 * month per consultant, across all their facilities, with lines:
 * - absorbed: a commitment fee the consultant absorbed for an insured patient (from day 31);
 * - plan: the plan's monthly charge for an active consultant on standard terms, prorated by days;
 * - cancellation: Ksh 100 for a booking the consultant or their front desk cancelled (from day 91,
 *   at facilities that signed v1.2), added when it happens.
 * Each line keeps the amount in force when it was made.
 */
class m261207_090000_consultant_invoice extends Migration
{
    const INVOICE = '{{%consultant_invoice}}';
    const LINE = '{{%consultant_invoice_line}}';

    public function safeUp()
    {
        $this->createTable(self::INVOICE, [
            'id' => $this->primaryKey(),
            'number' => $this->string(24)->notNull()->comment('INV-YYYYMM-C<consultant id>'),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id'),
            'period_start' => $this->date()->notNull()->comment('first day of the month billed'),
            'period_end' => $this->date()->notNull(),
            'amount' => $this->integer()->notNull()->comment('Ksh, lines not waived'),
            'status' => $this->string(12)->notNull()->comment('issued, paid, void'),
            'pausable' => $this->boolean()->notNull()->defaultValue(false)->comment('carries plan or cancellation charges: unpaid, it pauses online booking (F25 part 2)'),
            'issued_at' => $this->integer()->notNull(),
            'due_on' => $this->date()->notNull(),
            'paid_at' => $this->integer()->null(),
            'paid_by' => $this->integer()->null()->comment('the platform admin who recorded it; null when paid by M-Pesa prompt'),
            'reference' => $this->string(40)->null()->comment('the M-Pesa receipt or reference'),
            'mpesa_transaction_id' => $this->integer()->null(),
            'void_reason' => $this->string(255)->null(),
            'voided_at' => $this->integer()->null(),
            'voided_by' => $this->integer()->null(),
            'reminded_at' => $this->integer()->null(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
        ]);
        $this->createIndex('ux-consultant_invoice-number', self::INVOICE, 'number', true);
        $this->createIndex('idx-consultant_invoice-consultant-period', self::INVOICE, ['consultant_id', 'period_start']);
        $this->createIndex('idx-consultant_invoice-status-due', self::INVOICE, ['status', 'due_on']);
        $this->addForeignKey('fk-consultant_invoice-consultant_id', self::INVOICE, 'consultant_id', '{{%consultant}}', 'id', 'CASCADE', 'CASCADE');

        $this->createTable(self::LINE, [
            'id' => $this->primaryKey(),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id'),
            'invoice_id' => $this->integer()->null()->comment('null until the monthly run puts it on an invoice'),
            'type' => $this->string(16)->notNull()->comment('absorbed, plan, cancellation'),
            'source_id' => $this->integer()->null()->comment('absorbed: commitment_fee.id; cancellation: appointments.id; plan: null'),
            'facility_id' => $this->integer()->null()->comment('null for the plan charge'),
            'line_date' => $this->date()->notNull(),
            'description' => $this->string(255)->notNull(),
            'amount' => $this->integer()->notNull(),
            'waived' => $this->boolean()->notNull()->defaultValue(false),
            'waive_reason' => $this->string(255)->null(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
        ]);
        // A fee or a cancellation is billed once
        $this->createIndex('ux-consultant_invoice_line-type-source', self::LINE, ['type', 'source_id'], true);
        $this->createIndex('idx-consultant_invoice_line-open', self::LINE, ['consultant_id', 'invoice_id', 'line_date']);
        $this->addForeignKey('fk-consultant_invoice_line-invoice_id', self::LINE, 'invoice_id', self::INVOICE, 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk-consultant_invoice_line-consultant_id', self::LINE, 'consultant_id', '{{%consultant}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable(self::LINE);
        $this->dropTable(self::INVOICE);
    }
}
