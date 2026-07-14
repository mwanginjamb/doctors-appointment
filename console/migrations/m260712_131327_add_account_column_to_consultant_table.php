<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%consultant}}`.
 */
class m260712_131327_add_account_column_to_consultant_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%consultant}}', 'create_user_account', $this->boolean());
        $this->addColumn('{{%consultant}}', 'consultant_email', $this->string(200));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%consultant}}', 'create_user_account');
        $this->dropColumn('{{%consultant}}', 'consultant_email');
    }
}
