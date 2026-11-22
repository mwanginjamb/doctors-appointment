<?php

use yii\db\Migration;

/**
 * F29: the consultant's business card. Which of their facilities it lists (up to three, in order),
 * as comma-separated facility ids; null lists the first three they work at.
 */
class m261215_090000_business_card extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%consultant}}', 'card_facility_ids', $this->string(64)->null());
    }

    public function safeDown()
    {
        $this->dropColumn('{{%consultant}}', 'card_facility_ids');
    }
}
