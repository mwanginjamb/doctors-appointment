<?php

use yii\db\Migration;

/**
 * T2: the latest result of each health check (php yii health/check, every 5 minutes), so alerts go
 * out once per problem (repeated every few hours, plus "resolved"), and the admin dashboard shows the
 * state without running the checks itself.
 */
class m261122_090000_create_health_state_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%health_state}}', [
            'check_key' => $this->string(40)->notNull(),
            'failing' => $this->boolean()->notNull()->defaultValue(false),
            'message' => $this->string(500)->null(),
            'since' => $this->integer()->null()->comment('when it started failing'),
            'checked_at' => $this->integer()->notNull(),
            'alerted_at' => $this->integer()->null()->comment('last alert email'),
        ]);
        $this->addPrimaryKey('pk-health_state', '{{%health_state}}', 'check_key');
    }

    public function safeDown()
    {
        $this->dropTable('{{%health_state}}');
    }
}
