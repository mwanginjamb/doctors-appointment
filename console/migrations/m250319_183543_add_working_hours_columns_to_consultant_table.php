<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%consultant}}`.
 */
class m250319_183543_add_working_hours_columns_to_consultant_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%consultant}}', 'appointment_session_duration', $this->integer());
        $this->addColumn('{{%consultant}}', 'working_start_time', $this->time());
        $this->addColumn('{{%consultant}}', 'working_end_time', $this->time());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%consultant}}', 'appointment_session_duration');
        $this->dropColumn('{{%consultant}}', 'working_start_time');
        $this->dropColumn('{{%consultant}}', 'working_end_time');
    }
}
