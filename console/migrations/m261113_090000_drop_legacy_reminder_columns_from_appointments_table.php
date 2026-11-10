<?php

use yii\db\Migration;

/**
 * Drops reminder_5hrs_sent / reminder_2hrs_sent. They belonged to the old `yii send-reminders`
 * command (removed), which queued its own 5- and 2-hour emails alongside the reminder scheduler
 * and ignored cancellations. Reminders are tracked per notification in appointment_notifications.
 */
class m261113_090000_drop_legacy_reminder_columns_from_appointments_table extends Migration
{
    public function safeUp()
    {
        $this->dropColumn('{{%appointments}}', 'reminder_5hrs_sent');
        $this->dropColumn('{{%appointments}}', 'reminder_2hrs_sent');
    }

    public function safeDown()
    {
        $this->addColumn('{{%appointments}}', 'reminder_5hrs_sent', $this->integer()->null());
        $this->addColumn('{{%appointments}}', 'reminder_2hrs_sent', $this->integer()->null());
    }
}
