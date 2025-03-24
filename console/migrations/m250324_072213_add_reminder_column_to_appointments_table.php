<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%appointments}}`.
 */
class m250324_072213_add_reminder_column_to_appointments_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%appointments}}', 'reminder_5hr_sent', $this->integer());
        $this->addColumn('{{%appointments}}', 'reminder_2hrs_sent', $this->integer());

        $this->renameColumn('{{%appointments}}', 'reminder_5hr_sent', 'reminder_5hrs_sent');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%appointments}}', 'reminder_5hr_sent');
        $this->dropColumn('{{%appointments}}', 'reminder_2hrs_sent');
    }
}
