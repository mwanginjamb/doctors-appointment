<?php

use yii\db\Migration;

/**
 * F14: an appointment that clashes with a facility closure, a consultant's time off or a public
 * holiday, once its patient has been told: when, and when staff were reminded that the patient
 * has not picked a new time. Whether it still clashes is worked out from the appointment.
 */
class m261217_090000_appointment_clash extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%appointment_clash}}', [
            'id' => $this->primaryKey(),
            'appointment_id' => $this->integer()->notNull(),
            'source_type' => $this->string(16)->notNull()->comment('closure, time_off or holiday'),
            'source_id' => $this->integer()->notNull(),
            'told_at' => $this->integer()->null(),
            'told_by' => $this->integer()->null(),
            'staff_reminded_at' => $this->integer()->null(),
            'created_at' => $this->integer()->null(),
        ]);
        $this->createIndex('ux_appointment_clash', '{{%appointment_clash}}', ['appointment_id', 'source_type', 'source_id'], true);
        $this->createIndex('ix_appointment_clash_source', '{{%appointment_clash}}', ['source_type', 'source_id']);
    }

    public function safeDown()
    {
        $this->dropTable('{{%appointment_clash}}');
    }
}
