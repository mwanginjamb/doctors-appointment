<?php

use yii\db\Migration;

/**
 * Front desk registers walk-in patients by name and phone; email is optional for them.
 * The unique index stays: MySQL allows any number of NULLs in a unique column.
 * Accounts without an email get reminders by SMS/WhatsApp only and cannot reset a password
 * until an email is added.
 */
class m261111_090000_make_user_email_optional extends Migration
{
    public function safeUp()
    {
        $this->alterColumn('{{%user}}', 'email', $this->string()->null());
    }

    public function safeDown()
    {
        // Fails while any account has no email; give those accounts an email first
        $this->alterColumn('{{%user}}', 'email', $this->string()->notNull());
    }
}
