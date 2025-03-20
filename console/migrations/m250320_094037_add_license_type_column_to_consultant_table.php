<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%consultant}}`.
 */
class m250320_094037_add_license_type_column_to_consultant_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%consultant}}', 'license_type', $this->string());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%consultant}}', 'license_type');
    }
}
