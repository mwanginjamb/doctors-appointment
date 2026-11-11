<?php

use yii\db\Migration;

/**
 * F8: consultants ask for a facility that is not listed yet; an admin creates it (and the
 * consultant is engaged there) or declines, and the consultant is told either way.
 */
class m261123_100000_create_facility_request_table extends Migration
{
    const TABLE = '{{%facility_request}}';

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id who asked'),
            'name' => $this->string(150)->notNull(),
            'address' => $this->text()->null(),
            'phone' => $this->string(20)->null(),
            'map_url' => $this->string(500)->null(),
            'note' => $this->string(500)->null()->comment('from the consultant to the admin'),
            'status' => $this->string(10)->notNull()->defaultValue('pending')->comment('pending, created, declined'),
            'facility_id' => $this->integer()->null()->comment('the facility created for it'),
            'decision_note' => $this->string(500)->null()->comment('why it was declined; sent to the consultant'),
            'decided_at' => $this->integer()->null(),
            'decided_by' => $this->integer()->null(),
            'created_at' => $this->integer(),
            'created_by' => $this->integer(),
        ]);
        $this->createIndex('idx-facility_request-status', self::TABLE, ['status', 'created_at']);
        $this->createIndex('idx-facility_request-consultant', self::TABLE, 'consultant_id');
        $this->addForeignKey('fk-facility_request-consultant_id', self::TABLE, 'consultant_id', '{{%consultant}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-facility_request-facility_id', self::TABLE, 'facility_id', '{{%facility}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable(self::TABLE);
    }
}
