<?php

use yii\db\Migration;

/**
 * OAuth accounts used to be created with their email address as the password,
 * which let anyone who knew a user's name and email sign in with the password form.
 * Replaces those passwords with random, undisclosed ones.
 */
class m261101_090000_rotate_oauth_user_passwords extends Migration
{
    public function safeUp()
    {
        $users = (new \yii\db\Query())
            ->select(['id'])
            ->from('{{%user}}')
            ->where(['not', ['auth_provider' => null]])
            ->column($this->db);

        foreach ($users as $id) {
            $this->update('{{%user}}', [
                'password_hash' => Yii::$app->security->generatePasswordHash(Yii::$app->security->generateRandomString(32)),
                'auth_key' => Yii::$app->security->generateRandomString(),
            ], ['id' => $id]);
        }
    }

    public function safeDown()
    {
        echo "m261101_090000_rotate_oauth_user_passwords cannot be reverted; old passwords are not recoverable.\n";
        return true;
    }
}
