<?php

use yii\db\Migration;

/**
 * Who recorded an appointment's outcome (attended = completed, or no-show) and when.
 * The outcome itself is the existing status column.
 */
class m261113_100000_add_outcome_columns_to_appointments_table extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%appointments}}', 'outcome_at', $this->integer()->null()->after('cancellation_note'));
        $this->addColumn('{{%appointments}}', 'outcome_by', $this->integer()->null()->after('outcome_at'));
        // The no-show count per patient and the "needs an outcome" list look up by patient/status/date
        $this->createIndex('idx-appointments-patient-status', '{{%appointments}}', ['patient_id', 'status']);
        $this->createIndex('idx-appointments-status-date', '{{%appointments}}', ['status', 'date']);
    }

    public function safeDown()
    {
        $this->dropIndex('idx-appointments-status-date', '{{%appointments}}');
        $this->dropIndex('idx-appointments-patient-status', '{{%appointments}}');
        $this->dropColumn('{{%appointments}}', 'outcome_by');
        $this->dropColumn('{{%appointments}}', 'outcome_at');
    }
}
