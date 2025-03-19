<?php

use yii\db\Migration;

/**
 * Handles the creation of table `{{%appointments}}`.
 */
class m250319_064841_create_appointments_table extends Migration
{
    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable('{{%appointments}}', [
            'id' => $this->primaryKey(),
            'date' => $this->date(),
            'time' => $this->time(),
            'patient_id' => $this->integer(),
            'speciality_id' => $this->integer(),
            'service_id' => $this->integer(),
            'provider_id' => $this->integer(),
            'location' => $this->text(),
            'recurring_appointment' => $this->boolean(),
            'walk_in_appointment' => $this->boolean(),
            'symptoms_brief' => $this->text(),
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
        $this->dropTable('{{%appointments}}');
    }
}
