<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * F8 phase 2: front desk, patient history and reporting per facility.
 *
 * - `user.facility_id`: the facility a front-desk account works at. They book, see and handle only
 *   appointments there. Null means not set yet: they keep seeing their consultants everywhere, and
 *   the Front Desk Staff page asks an admin to choose.
 * - `consultant.travel_buffer_minutes`: the gap a consultant needs before a block at another
 *   facility; the last slots before the move are not offered.
 * - `facility_closure`: days a facility is shut, on top of the public holidays.
 * - `audit_log.facility_id`: the facility an entry is about, when it is about one.
 *
 * Existing front-desk accounts get the one facility where all their consultants work, when there is
 * exactly one; the others are left for an admin.
 */
class m261125_090000_facility_phase2 extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%user}}', 'facility_id', $this->integer()->null()->comment('front desk: the facility they work at'));
        $this->addForeignKey('fk-user-facility_id', '{{%user}}', 'facility_id', '{{%facility}}', 'id', 'SET NULL', 'CASCADE');

        $this->addColumn('{{%consultant}}', 'travel_buffer_minutes', $this->smallInteger()->notNull()->defaultValue(0)
            ->comment('gap needed before a block at another facility'));

        $this->createTable('{{%facility_closure}}', [
            'id' => $this->primaryKey(),
            'facility_id' => $this->integer()->notNull(),
            'date' => $this->date()->notNull(),
            'reason' => $this->string(150)->notNull(),
            'created_at' => $this->integer(),
            'updated_at' => $this->integer(),
            'created_by' => $this->integer(),
            'updated_by' => $this->integer(),
        ]);
        $this->createIndex('ux-facility_closure-facility-date', '{{%facility_closure}}', ['facility_id', 'date'], true);
        $this->createIndex('idx-facility_closure-date', '{{%facility_closure}}', 'date');
        $this->addForeignKey('fk-facility_closure-facility_id', '{{%facility_closure}}', 'facility_id', '{{%facility}}', 'id', 'CASCADE', 'CASCADE');

        // No foreign key: entries are kept as written, even for a facility that is later removed
        $this->addColumn('{{%audit_log}}', 'facility_id', $this->integer()->null()->after('entity_id'));
        $this->createIndex('idx-audit_log-facility', '{{%audit_log}}', ['facility_id', 'created_at']);

        $this->backfillFrontDesk();
    }

    private function backfillFrontDesk()
    {
        $set = 0;
        $left = 0;
        $users = (new Query())->select('id')->from('{{%user}}')->where(['role' => 'front_desk'])->column($this->db);
        foreach ($users as $userId) {
            $consultantIds = (new Query())->select('consultant_id')->from('{{%front_desk_assignment}}')
                ->where(['user_id' => $userId])->column($this->db);
            $shared = null;
            foreach ($consultantIds as $consultantId) {
                $facilities = (new Query())->select('facility_id')->from('{{%consultant_facility}}')
                    ->where(['consultant_id' => $consultantId])->column($this->db);
                $shared = $shared === null ? $facilities : array_values(array_intersect($shared, $facilities));
            }
            if ($shared !== null && count($shared) === 1) {
                $this->update('{{%user}}', ['facility_id' => (int) reset($shared)], ['id' => $userId]);
                $set++;
            } else {
                $left++;
            }
        }

        echo "    > front desk: facility set for {$set} account(s); {$left} left for an admin to choose\n";
    }

    public function safeDown()
    {
        $this->dropIndex('idx-audit_log-facility', '{{%audit_log}}');
        $this->dropColumn('{{%audit_log}}', 'facility_id');
        $this->dropTable('{{%facility_closure}}');
        $this->dropColumn('{{%consultant}}', 'travel_buffer_minutes');
        $this->dropForeignKey('fk-user-facility_id', '{{%user}}');
        $this->dropColumn('{{%user}}', 'facility_id');
    }
}
