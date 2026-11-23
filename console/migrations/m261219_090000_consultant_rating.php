<?php

use yii\db\Migration;

/**
 * F30: patients rate the consultant 1 to 5 after a visit recorded as attended, with an optional
 * private comment; one rating per appointment. And when each appointment's rating link was sent.
 */
class m261219_090000_consultant_rating extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%consultant_rating}}', [
            'id' => $this->primaryKey(),
            'appointment_id' => $this->integer()->notNull(),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id'),
            'patient_id' => $this->integer()->notNull(),
            'facility_id' => $this->integer()->null(),
            'stars' => $this->tinyInteger()->notNull(),
            'comment' => $this->string(1000)->null()->comment('private: the consultant and admins only'),
            'withdrawn' => $this->tinyInteger()->notNull()->defaultValue(0)->comment('1 when the visit was corrected to a no-show'),
            'created_at' => $this->integer()->null(),
            'updated_at' => $this->integer()->null(),
        ]);
        $this->createIndex('ux_consultant_rating_appointment', '{{%consultant_rating}}', 'appointment_id', true);
        $this->createIndex('ix_consultant_rating_consultant', '{{%consultant_rating}}', ['consultant_id', 'withdrawn']);
        $this->addColumn('{{%appointments}}', 'rating_requested_at', $this->integer()->null()->comment('F30: when the rating link was sent'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%appointments}}', 'rating_requested_at');
        $this->dropTable('{{%consultant_rating}}');
    }
}
