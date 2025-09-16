<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%gender}}`.
 */
class m250916_100037_add_updated_at_column_to_gender_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%gender}}', 'updated_at', $this->integer(25));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%gender}}', 'updated_at');
    }
}
