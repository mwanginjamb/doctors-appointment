<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%appointment_notifications}}`.
 */
class m250911_200129_create_appointment_notifications_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%appointment_notifications}}', [
            'id' => $this->primaryKey(),
            'appointment_id' => $this->integer()->notNull(),
            'notification_schedule_id' => $this->integer()->notNull(),
            'notification_type' => $this->string(20)->notNull(),
            'minutes_before' => $this->integer()->notNull(),
            'status' => $this->string(20)->defaultValue('pending')->comment('pending,sent,failed,cancelled'),
            'scheduled_time' => $this->dateTime()->comment('When notification should be sent'),
            'sent_at' => $this->dateTime()->comment('When notification was actually sent'),
            'error_message' => $this->text()->comment('Error message if failed'),
            'created_at' => $this->integer(25),
            'updated_at' => $this->integer(25),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%appointment_notifications}}');
    }
}
