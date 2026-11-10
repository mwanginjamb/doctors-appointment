<?php

use yii\db\Migration;

/**
 * F11, Kenya Data Protection Act 2019: proof of consent.
 *
 * consent: every time a patient grants or withdraws consent, for processing their (health) data or for
 * WhatsApp/SMS reminders, with the privacy-notice version, how it was given and who recorded it. Rows
 * are only added; a patient's current answer is their latest row for each purpose.
 *
 * account_closure_request: a patient who withdraws consent to data processing asks for their account
 * to be closed; admins act on it.
 */
class m261121_090000_create_consent_tables extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%consent}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'purpose' => $this->string(20)->notNull()->comment('data, reminders'),
            'granted' => $this->boolean()->notNull(),
            'notice_version' => $this->string(20)->notNull(),
            'method' => $this->string(20)->notNull()->comment('online, front_desk, booking_link'),
            'recorded_by' => $this->integer()->null()->comment('staff who recorded verbal consent'),
            'ip' => $this->string(45)->null(),
            'created_at' => $this->integer()->notNull(),
        ]);
        $this->createIndex('idx-consent-user-purpose', '{{%consent}}', ['user_id', 'purpose', 'id']);
        $this->addForeignKey('fk-consent-user_id', '{{%consent}}', 'user_id', '{{%user}}', 'id', 'CASCADE', 'CASCADE');

        $this->createTable('{{%account_closure_request}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'reason' => $this->string(500)->null(),
            'status' => $this->string(20)->notNull()->defaultValue('open')->comment('open, closed, withdrawn'),
            'created_at' => $this->integer()->notNull(),
            'handled_by' => $this->integer()->null(),
            'handled_at' => $this->integer()->null(),
            'handled_note' => $this->string(500)->null(),
        ]);
        $this->createIndex('idx-account_closure_request-status', '{{%account_closure_request}}', ['status', 'created_at']);
        $this->createIndex('idx-account_closure_request-user', '{{%account_closure_request}}', 'user_id');
        $this->addForeignKey('fk-account_closure_request-user_id', '{{%account_closure_request}}', 'user_id', '{{%user}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown()
    {
        $this->dropTable('{{%account_closure_request}}');
        $this->dropTable('{{%consent}}');
    }
}
