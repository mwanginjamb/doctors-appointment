<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%consultant}}`.
 */
class m250915_193411_add_profile_attribute_column_to_consultant_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%consultant}}', 'gender', $this->integer());
        $this->addColumn('{{%consultant}}', 'practice_name', $this->string(250));
        $this->addColumn('{{%consultant}}', 'working_hours', $this->text());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%consultant}}', 'gender');
        $this->dropColumn('{{%consultant}}', 'practice_name');
        $this->dropColumn('{{%consultant}}', 'working_hours');
    }
}
