<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%user}}`.
 */
class m250323_121751_add_oathfields_column_to_user_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%user}}', 'auth_provider', $this->string(100));
        $this->addColumn('{{%user}}', 'auth_client_id', $this->string(300));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%user}}', 'auth_provider');
        $this->dropColumn('{{%user}}', 'auth_client_id');
    }
}
