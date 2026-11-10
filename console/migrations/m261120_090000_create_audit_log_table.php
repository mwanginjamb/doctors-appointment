<?php

use yii\db\Migration;

/**
 * Audit log: who did what, to which record, and when, beyond created_by / updated_by. Rows are only
 * ever added; `php yii audit/purge` removes those older than the retention period
 * (audit.retentionYears, 7 by default).
 */
class m261120_090000_create_audit_log_table extends Migration
{
    const TABLE = '{{%audit_log}}';

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->bigPrimaryKey(),
            'created_at' => $this->integer()->notNull(),
            'actor_id' => $this->integer()->null()->comment('user.id, when a user acted'),
            'actor_type' => $this->string(20)->notNull()->comment('staff, patient, booking_link, self_service, system, guest'),
            'actor_name' => $this->string(150)->null()->comment('name at the time, kept if the account changes'),
            'action' => $this->string(40)->notNull(),
            'entity_type' => $this->string(40)->null(),
            'entity_id' => $this->string(64)->null(),
            'changes' => $this->text()->null()->comment('JSON: field => [old, new]'),
            'note' => $this->string(255)->null(),
            'ip' => $this->string(45)->null(),
        ]);
        $this->createIndex('idx-audit_log-entity', self::TABLE, ['entity_type', 'entity_id', 'created_at']);
        $this->createIndex('idx-audit_log-actor', self::TABLE, ['actor_id', 'created_at']);
        $this->createIndex('idx-audit_log-created_at', self::TABLE, 'created_at');
        $this->createIndex('idx-audit_log-action', self::TABLE, 'action');
    }

    public function safeDown()
    {
        $this->dropTable(self::TABLE);
    }
}
