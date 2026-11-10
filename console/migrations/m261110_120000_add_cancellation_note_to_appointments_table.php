<?php

use yii\db\Migration;

/**
 * Lets whoever cancels an appointment say why. Optional; set only through Appointments::cancel().
 */
class m261110_120000_add_cancellation_note_to_appointments_table extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%appointments}}', 'cancellation_note', $this->string(500)->null()->after('status'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%appointments}}', 'cancellation_note');
    }
}
