<?php

use common\helpers\Phone;
use yii\db\Migration;
use yii\db\Query;

/**
 * Patients sign in with their phone number and a one-time code.
 *
 * - user.phone_key: the number in one form (254712345678) for finding accounts by number; the
 *   number as typed stays in phone_number. Not unique: family members share numbers.
 * - user.phone_verified_at: when someone last proved they hold the number with a code.
 * - login_code: the codes sent (hash only), which also count requests for the rate limits.
 */
class m261128_090000_phone_sign_in extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%user}}', 'phone_key', $this->string(15)->null()->after('phone_number'));
        $this->addColumn('{{%user}}', 'phone_verified_at', $this->integer()->null()->after('phone_key'));
        $this->createIndex('idx-user-phone_key', '{{%user}}', 'phone_key');

        $this->createTable('{{%login_code}}', [
            'id' => $this->primaryKey(),
            'phone' => $this->string(15)->notNull(),
            'code_hash' => $this->string(64)->notNull(),
            'channel' => $this->string(10)->notNull()->comment('whatsapp|sms'),
            'attempts' => $this->smallInteger()->notNull()->defaultValue(0),
            'expires_at' => $this->integer()->notNull(),
            'used_at' => $this->integer()->null(),
            'ip' => $this->string(45)->null(),
            'created_at' => $this->integer()->notNull(),
        ]);
        $this->createIndex('idx-login_code-phone-created', '{{%login_code}}', ['phone', 'created_at']);
        $this->createIndex('idx-login_code-ip-created', '{{%login_code}}', ['ip', 'created_at']);

        // Fill phone_key for existing accounts; numbers that cannot be read stay without one
        $unreadable = 0;
        foreach ((new Query())->select(['id', 'phone_number'])->from('{{%user}}')->where(['not', ['phone_number' => null]])->each(500) as $row) {
            $key = Phone::normalize($row['phone_number']);
            if ($key === null) {
                $unreadable++;
                continue;
            }
            $this->db->createCommand()->update('{{%user}}', ['phone_key' => $key], ['id' => $row['id']])->execute();
        }
        if ($unreadable) {
            echo "    > $unreadable phone number(s) could not be read; those accounts cannot sign in by phone until corrected.\n";
        }
    }

    public function safeDown()
    {
        $this->dropTable('{{%login_code}}');
        $this->dropIndex('idx-user-phone_key', '{{%user}}');
        $this->dropColumn('{{%user}}', 'phone_verified_at');
        $this->dropColumn('{{%user}}', 'phone_key');
    }
}
