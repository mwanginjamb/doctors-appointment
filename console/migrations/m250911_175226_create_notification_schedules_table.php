<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%notification_schedules}}`.
 * Has foreign keys to the tables:
 *
 * - `{{%user}}`
 */
class m250911_175226_create_notification_schedules_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%notification_schedules}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer(),
            'notification_type' => $this->string(20)->defaultValue('email')->comment('E-mail,sms,push'),
            'minutes_before' => $this->integer()->notNull(),
            'is_active' => $this->boolean()->defaultValue(1),
            'notification_method' => $this->string(20)->defaultValue('both')->comment('Patient,consultant,both'),
            'created_at' => $this->integer(25),
            'updated_at' => $this->integer(25),
            'created_by' => $this->integer(25),
            'updated_by' => $this->integer(25),
        ]);

        // creates index for column `user_id`
        $this->createIndex(
            '{{%idx-notification_schedules-user_id}}',
            '{{%notification_schedules}}',
            'user_id'
        );
        $this->createIndex('idx_notification_schedules_active', '{{%notification_schedules}}', 'is_active');
        $this->createIndex('idx_notification_schedules_type', '{{%notification_schedules}}', 'notification_type');


        // add foreign key for table `{{%user}}`
        $this->addForeignKey(
            '{{%fk-notification_schedules-user_id}}',
            '{{%notification_schedules}}',
            'user_id',
            '{{%user}}',
            'id',
            'CASCADE'
        );
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        // drops foreign key for table `{{%user}}`
        $this->dropForeignKey(
            '{{%fk-notification_schedules-user_id}}',
            '{{%notification_schedules}}'
        );

        // drops index for column `user_id`
        $this->dropIndex(
            '{{%idx-notification_schedules-user_id}}',
            '{{%notification_schedules}}'
        );

        $this->dropTable('{{%notification_schedules}}');
    }
}
