<?php

use yii\db\Migration;

/**
 * F18: an insured booking's insurer and member number, whether the consultant lists that insurer,
 * and its pre-authorisation: pending, approved or declined, with a reference, a note, who and when.
 */
class m261218_090000_appointment_insurer_preauth extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%appointments}}', 'insurer_id', $this->integer()->null()->comment('provider.id'));
        $this->addColumn('{{%appointments}}', 'member_number', $this->string(40)->null());
        $this->addColumn('{{%appointments}}', 'insurer_listed', $this->tinyInteger()->null()->comment('1 when the consultant lists the insurer'));
        $this->addColumn('{{%appointments}}', 'preauth_status', $this->string(10)->null()->comment('pending, approved or declined'));
        $this->addColumn('{{%appointments}}', 'preauth_reference', $this->string(64)->null());
        $this->addColumn('{{%appointments}}', 'preauth_note', $this->string(255)->null());
        $this->addColumn('{{%appointments}}', 'preauth_by', $this->integer()->null());
        $this->addColumn('{{%appointments}}', 'preauth_at', $this->integer()->null());
        $this->createIndex('ix_appointments_preauth', '{{%appointments}}', ['preauth_status', 'date']);
    }

    public function safeDown()
    {
        $this->dropIndex('ix_appointments_preauth', '{{%appointments}}');
        foreach (['preauth_at', 'preauth_by', 'preauth_note', 'preauth_reference', 'preauth_status', 'insurer_listed', 'member_number', 'insurer_id'] as $column) {
            $this->dropColumn('{{%appointments}}', $column);
        }
    }
}
