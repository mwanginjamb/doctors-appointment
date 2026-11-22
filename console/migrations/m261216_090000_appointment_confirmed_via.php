<?php

use yii\db\Migration;

/**
 * F13: how, when and by whom an appointment was confirmed. Staff pick how the patient confirmed
 * (phone, in person, WhatsApp/SMS, other); the patient's own reminder link records "link".
 * Earlier confirmations have none.
 */
class m261216_090000_appointment_confirmed_via extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%appointments}}', 'confirmed_via', $this->string(16)->null());
        $this->addColumn('{{%appointments}}', 'confirmed_by', $this->integer()->null());
        $this->addColumn('{{%appointments}}', 'confirmed_at', $this->integer()->null());
    }

    public function safeDown()
    {
        $this->dropColumn('{{%appointments}}', 'confirmed_at');
        $this->dropColumn('{{%appointments}}', 'confirmed_by');
        $this->dropColumn('{{%appointments}}', 'confirmed_via');
    }
}
