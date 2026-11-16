<?php

use yii\db\Migration;

/**
 * Moving a facility to standard terms after its pilot, in two steps: the facility confirms
 * (standard_terms_confirmed_*), then a platform admin approves (standard_terms_approved_*). Also
 * which notices went out (day 81 heads-up, day 91 start of the 30-day grace period, day 121
 * overdue to platform admins).
 */
class m261202_090000_standard_terms_confirmation extends Migration
{
    const TABLE = '{{%facility_agreement}}';

    public function safeUp()
    {
        $this->addColumn(self::TABLE, 'standard_terms_confirmed_by', $this->integer()->null()->comment('user.id; null from the console'));
        $this->addColumn(self::TABLE, 'standard_terms_approved_at', $this->integer()->null()->comment('a platform admin approved, after the facility confirmed'));
        $this->addColumn(self::TABLE, 'standard_terms_approved_by', $this->integer()->null()->comment('user.id; null from the console'));
        $this->addColumn(self::TABLE, 'notice_upcoming_sent_at', $this->integer()->null()->comment('day 81: standard terms in 10 days'));
        $this->addColumn(self::TABLE, 'notice_grace_sent_at', $this->integer()->null()->comment('day 91: standard terms start; 30 days to confirm'));
        $this->addColumn(self::TABLE, 'notice_overdue_sent_at', $this->integer()->null()->comment('day 121: platform admins told it is still unconfirmed'));
    }

    public function safeDown()
    {
        foreach (['notice_overdue_sent_at', 'notice_grace_sent_at', 'notice_upcoming_sent_at', 'standard_terms_approved_by', 'standard_terms_approved_at', 'standard_terms_confirmed_by'] as $column) {
            $this->dropColumn(self::TABLE, $column);
        }
    }
}
