<?php

use yii\db\Migration;

/**
 * F4 waiting list: a patient waits for a time with a consultant at a facility, within a window of
 * dates (and optionally part of the day). When a time in the window frees up, everyone waiting for
 * it gets a link to book it; the first to book gets it. An entry tied to an appointment ("tell me
 * if something earlier opens") moves that appointment instead of booking a second one.
 */
class m261129_090000_create_waitlist_entry_table extends Migration
{
    const TABLE = '{{%waitlist_entry}}';

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'patient_id' => $this->integer()->notNull()->comment('user.id'),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id'),
            'facility_id' => $this->integer()->notNull(),
            'from_date' => $this->date()->notNull(),
            'to_date' => $this->date()->notNull(),
            'day_start' => $this->time()->null()->comment('optional part of the day'),
            'day_end' => $this->time()->null(),
            'appointment_id' => $this->integer()->null()->comment('the later appointment to move: "something earlier"'),
            'status' => $this->string(10)->notNull()->defaultValue('waiting'),
            'offered_slots' => $this->text()->null()->comment('JSON: the times the patient was last told about'),
            'notices_sent' => $this->integer()->notNull()->defaultValue(0),
            'notices_on' => $this->date()->null(),
            'notices_that_day' => $this->smallInteger()->notNull()->defaultValue(0),
            'last_notified_at' => $this->integer()->null(),
            'booked_appointment_id' => $this->integer()->null(),
            'ended_at' => $this->integer()->null(),
            'created_at' => $this->integer(),
            'created_by' => $this->integer()->comment('the patient, or the staff member who added them'),
        ]);
        $this->createIndex('idx-waitlist_entry-consultant-status', self::TABLE, ['consultant_id', 'status']);
        $this->createIndex('idx-waitlist_entry-patient-status', self::TABLE, ['patient_id', 'status']);
        $this->addForeignKey('fk-waitlist_entry-consultant_id', self::TABLE, 'consultant_id', '{{%consultant}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-waitlist_entry-patient_id', self::TABLE, 'patient_id', '{{%user}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable(self::TABLE);
    }
}
