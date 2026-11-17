<?php

use yii\db\Migration;

/**
 * F22 part 2, paying the commitment fee at booking: how long an unpaid booking holds its slot, and
 * when the patient accepted the booking terms.
 */
class m261204_090000_commitment_fee_payment extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%appointments}}', 'hold_expires_at', $this->integer()->null()->comment('awaiting payment: the slot is released after this'));
        $this->createIndex('idx-appointments-status-hold', '{{%appointments}}', ['status', 'hold_expires_at']);
        $this->addColumn('{{%commitment_fee}}', 'terms_accepted_at', $this->integer()->null()->comment('the patient accepted the booking terms'));
    }

    public function safeDown()
    {
        $this->dropColumn('{{%commitment_fee}}', 'terms_accepted_at');
        $this->dropIndex('idx-appointments-status-hold', '{{%appointments}}');
        $this->dropColumn('{{%appointments}}', 'hold_expires_at');
    }
}
