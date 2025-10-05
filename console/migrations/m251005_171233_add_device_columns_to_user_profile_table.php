<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%user_profile}}`.
 */
class m251005_171233_add_device_columns_to_user_profile_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%user_profile}}', 'device_token', $this->string(255));
        $this->addColumn('{{%user_profile}}', 'device_type', "ENUM('web', 'android', 'ios') NOT NULL DEFAULT 'web'");
        $this->addColumn('{{%user_profile}}', 'token_updated_at', $this->datetime());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%user_profile}}', 'device_token');
        $this->dropColumn('{{%user_profile}}', 'device_type');
        $this->dropColumn('{{%user_profile}}', 'token_updated_at');
    }
}
