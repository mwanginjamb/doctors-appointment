<?php

use yii\db\Migration;

/**
 * Handles adding columns to table `{{%appointments}}`.
 */
class m250911_182518_add_status_column_to_appointments_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->addColumn('{{%appointments}}', 'status', $this->string(20)->defaultValue('scheduled')->after('consultant_id'));
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropColumn('{{%appointments}}', 'status');
    }
}
