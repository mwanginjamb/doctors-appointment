<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%consultant}}`.
 */
class m251005_165454_add_device_columns_to_consultant_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%consultant}}', 'device_token', $this->string(255));
        $this->addColumn('{{%consultant}}', 'device_type', "ENUM('web', 'android', 'ios') NOT NULL DEFAULT 'web'");
        $this->addColumn('{{%consultant}}', 'token_updated_at', $this->datetime());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%consultant}}', 'device_token');
        $this->dropColumn('{{%consultant}}', 'device_type');
        $this->dropColumn('{{%consultant}}', 'token_updated_at');
    }
}
