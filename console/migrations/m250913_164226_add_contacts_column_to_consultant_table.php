<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%consultant}}`.
 */
class m250913_164226_add_contacts_column_to_consultant_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%consultant}}', 'consultant_email', $this->string(150));
        $this->addColumn('{{%consultant}}', 'consultant_phone_number', $this->string(15));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%consultant}}', 'consultant_email');
        $this->dropColumn('{{%consultant}}', 'consultant_phone_number');
    }
}
