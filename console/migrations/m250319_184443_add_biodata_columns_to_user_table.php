<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%user}}`.
 */
class m250319_184443_add_biodata_columns_to_user_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%user}}', 'full_name', $this->string(150));
        $this->addColumn('{{%user}}', 'phone_number', $this->string(15));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%user}}', 'full_name');
        $this->dropColumn('{{%user}}', 'phone_number');
    }
}
