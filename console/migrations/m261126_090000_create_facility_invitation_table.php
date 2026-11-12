<?php

use yii\db\Migration;

/**
 * Facility admins: invitations to consultants.
 *
 * When an admin ticks a consultant for a front-desk account at a facility where the consultant does
 * not work yet, the consultant is invited to work there. They accept by adding the facility on
 * their Availability page (with their session length and fee there), or decline. Front desk can
 * book for them there only once they have hours there.
 *
 * Facility admin accounts themselves need no table: they are users with role "facility_admin" and
 * the facility they run in user.facility_id (added in F8 phase 2).
 */
class m261126_090000_create_facility_invitation_table extends Migration
{
    const TABLE = '{{%facility_invitation}}';

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'facility_id' => $this->integer()->notNull(),
            'consultant_id' => $this->integer()->notNull()->comment('consultant.id invited'),
            'status' => $this->string(10)->notNull()->defaultValue('pending')->comment('pending, accepted, declined'),
            'decided_at' => $this->integer()->null(),
            'created_at' => $this->integer(),
            'created_by' => $this->integer()->comment('the admin who invited'),
        ]);
        $this->createIndex('idx-facility_invitation-consultant-status', self::TABLE, ['consultant_id', 'status']);
        $this->createIndex('idx-facility_invitation-facility-status', self::TABLE, ['facility_id', 'status']);
        $this->addForeignKey('fk-facility_invitation-facility_id', self::TABLE, 'facility_id', '{{%facility}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-facility_invitation-consultant_id', self::TABLE, 'consultant_id', '{{%consultant}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable(self::TABLE);
    }
}
