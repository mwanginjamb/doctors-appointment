<?php

use yii\db\Migration;

/**
 * Which consultants each front-desk user books for. Front desk sees, books, moves and cancels
 * appointments only for the consultants assigned here by an admin.
 */
class m261111_090100_create_front_desk_assignment_table extends Migration
{
    const TABLE = '{{%front_desk_assignment}}';

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull()->comment('the front-desk user'),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id'),
            'created_at' => $this->integer(),
            'created_by' => $this->integer(),
        ]);
        $this->createIndex('ux-front_desk_assignment-user-consultant', self::TABLE, ['user_id', 'consultant_id'], true);
        $this->createIndex('idx-front_desk_assignment-consultant', self::TABLE, 'consultant_id');
        $this->addForeignKey('fk-front_desk_assignment-user_id', self::TABLE, 'user_id', '{{%user}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-front_desk_assignment-consultant_id', self::TABLE, 'consultant_id', '{{%consultant}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable(self::TABLE);
    }
}
