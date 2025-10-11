<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%consultant}}`.
 */
class m251011_082623_add_establishment_column_to_consultant_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%consultant}}', 'practice_establishment_date', $this->date());
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%consultant}}', 'practice_establishment_date');
    }
}
