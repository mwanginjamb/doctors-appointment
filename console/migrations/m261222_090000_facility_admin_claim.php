<?php

use yii\db\Migration;

/**
 * Consultants who run a facility become its facility admin as well.
 *
 * - facility_admin_claim: a consultant's claim to run a facility, from its row on their Availability
 *   page (approved or declined by a platform admin in /admin), or from a facility they asked to be
 *   listed (granted when it is listed). Approved claims are revoked there too.
 * - facility_request.runs_facility: the consultant said they run the facility they asked for.
 *
 * Granting sets user.facility_id and adds the facility-admin RBAC role next to the consultant's own.
 */
class m261222_090000_facility_admin_claim extends Migration
{
    const TABLE = '{{%facility_admin_claim}}';

    public function safeUp()
    {
        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull()->comment('the consultant (user.id)'),
            'facility_id' => $this->integer()->null()->comment('null while it waits for its facility request'),
            'facility_request_id' => $this->integer()->null(),
            'status' => $this->string(10)->notNull()->comment('pending, approved, declined, revoked, cancelled'),
            'decision_note' => $this->string(500)->null(),
            'decided_by' => $this->integer()->null(),
            'decided_at' => $this->integer()->null(),
            'created_at' => $this->integer()->notNull(),
        ]);
        $this->createIndex('idx-facility_admin_claim-user', self::TABLE, ['user_id', 'status']);
        $this->createIndex('idx-facility_admin_claim-status', self::TABLE, 'status');
        $this->addForeignKey('fk-facility_admin_claim-user_id', self::TABLE, 'user_id', '{{%user}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-facility_admin_claim-facility_id', self::TABLE, 'facility_id', '{{%facility}}', 'id', 'CASCADE', 'CASCADE');

        $this->addColumn('{{%facility_request}}', 'runs_facility', $this->tinyInteger(1)->notNull()->defaultValue(0)->after('note'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%facility_request}}', 'runs_facility');
        $this->dropTable(self::TABLE);
    }
}
