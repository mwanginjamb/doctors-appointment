<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%provider}}`.
 */
class m250912_200611_create_provider_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%provider}}', [
            'id' => $this->primaryKey(),
            'provider' => $this->string(150),
            'created_at' => $this->integer(25),
            'updated_at' => $this->integer(25),
            'updated_by' => $this->integer(25),
            'created_by' => $this->integer(25),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%provider}}');
    }
}
