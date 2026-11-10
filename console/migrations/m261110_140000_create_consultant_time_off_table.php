<?php

use yii\db\Migration;

/**
 * Periods a consultant is away (leave, conferences, part of a day). Nothing can be booked
 * during them. end_at is exclusive: a whole-day leave on 14 Dec is 14 Dec 00:00 to 15 Dec 00:00.
 */
class m261110_140000_create_consultant_time_off_table extends Migration
{
    const TABLE = '{{%consultant_time_off}}';

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id'),
            'start_at' => $this->dateTime()->notNull(),
            'end_at' => $this->dateTime()->notNull()->comment('exclusive'),
            'reason' => $this->string(255)->null(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_by' => $this->integer(),
        ]);
        $this->createIndex('idx-consultant_time_off-consultant-period', self::TABLE, ['consultant_id', 'start_at', 'end_at']);
        $this->addForeignKey(
            'fk-consultant_time_off-consultant_id',
            self::TABLE,
            'consultant_id',
            '{{%consultant}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropTable(self::TABLE);
    }
}
