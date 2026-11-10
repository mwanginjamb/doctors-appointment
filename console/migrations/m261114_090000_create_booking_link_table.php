<?php

use yii\db\Migration;

/**
 * Booking links ("remote walk-ins"): a consultant or front desk sends a potential patient a link,
 * by WhatsApp and/or email, to book one of the open slots in a short date range. Only a SHA-256
 * hash of the link's token is stored. A link expires shortly after it is created and books once.
 */
class m261114_090000_create_booking_link_table extends Migration
{
    const TABLE = '{{%booking_link}}';

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'token_hash' => $this->char(64)->notNull()->unique(),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id'),
            'recipient_name' => $this->string(150)->notNull(),
            'recipient_phone' => $this->string(15)->null(),
            'recipient_email' => $this->string(255)->null(),
            'from_date' => $this->date()->notNull(),
            'to_date' => $this->date()->notNull(),
            'day_start' => $this->time()->null()->comment('optional daily window'),
            'day_end' => $this->time()->null(),
            'expires_at' => $this->integer()->notNull(),
            'used_at' => $this->integer()->null(),
            'appointment_id' => $this->integer()->null(),
            'revoked_at' => $this->integer()->null(),
            'created_at' => $this->integer(),
            'created_by' => $this->integer()->comment('the consultant or front desk who sent it'),
        ]);
        $this->createIndex('idx-booking_link-consultant', self::TABLE, 'consultant_id');
        $this->createIndex('idx-booking_link-created_by', self::TABLE, ['created_by', 'created_at']);
        $this->addForeignKey('fk-booking_link-consultant_id', self::TABLE, 'consultant_id', '{{%consultant}}', 'id', 'CASCADE', 'CASCADE');

        $this->addColumn('{{%appointments}}', 'booking_link_id', $this->integer()->null()->after('consultant_id'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%appointments}}', 'booking_link_id');
        $this->dropTable(self::TABLE);
    }
}
