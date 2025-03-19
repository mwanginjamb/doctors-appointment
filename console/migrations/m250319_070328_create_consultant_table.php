<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%consultant}}`.
 */
class m250319_070328_create_consultant_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%consultant}}', [
            'id' => $this->primaryKey(),
            'names' => $this->string(150),
            'license_number' => $this->string(100),
            'speciality' => $this->text(),
            'sub_speciality' => $this->text(),
            'kmpdc_registration_number' => $this->string(),
            'user_id' => $this->integer(),
            'facility' => $this->string(250),
            'physical_address' => $this->text(),
            'created_at' => $this->integer(25),
            'updated_at' => $this->integer(25),
            'created_by' => $this->integer(25),
            'updated_by' => $this->integer(25),
            'consultant_id' => $this->integer(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%consultant}}');
    }
}
