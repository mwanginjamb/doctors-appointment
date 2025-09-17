<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%specialization}}`.
 */
class m250917_081024_create_specialization_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%specialization}}', [
            'id' => $this->primaryKey(),
            'specialization' => $this->string(250),
            'description' => $this->text(),
            'created_at' => $this->integer(25),
            'updated_at' => $this->integer(25),
            'created_by' => $this->integer(),
            'updated_by' => $this->integer(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%specialization}}');
    }
}
