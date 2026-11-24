<?php

use yii\db\Migration;

/**
 * F12: patients export and delete their data.
 *
 * - account_closure_request.delete_data: the patient asked for their data to be deleted (not only
 *   the account closed); data_deleted_at: when an admin deleted it.
 * - user.anonymised_at: when the account's personal data was removed (on request or by the
 *   retention rules); the row stays so appointments, fees and reports still add up.
 */
class m261220_090000_patient_data extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%account_closure_request}}', 'delete_data', $this->tinyInteger(1)->notNull()->defaultValue(0)->after('reason'));
        $this->addColumn('{{%account_closure_request}}', 'data_deleted_at', $this->integer()->null()->after('handled_note'));
        $this->addColumn('{{%user}}', 'anonymised_at', $this->integer()->null());
        $this->createIndex('idx-user-anonymised_at', '{{%user}}', 'anonymised_at');
    }

    public function safeDown()
    {
        $this->dropIndex('idx-user-anonymised_at', '{{%user}}');
        $this->dropColumn('{{%user}}', 'anonymised_at');
        $this->dropColumn('{{%account_closure_request}}', 'data_deleted_at');
        $this->dropColumn('{{%account_closure_request}}', 'delete_data');
    }
}
