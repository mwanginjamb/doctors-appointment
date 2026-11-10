<?php

namespace common\models;

use Yii;
use yii\base\NotSupportedException;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

/**
 * User model
 *
 * @property integer $id
 * @property string $username
 * @property string $password_hash
 * @property string $password_reset_token
 * @property string $verification_token
 * @property string $email
 * @property string $auth_key
 * @property string $auth_provider
 * @property string $auth_client_id
 * @property string $role
 * @property integer $status
 * @property integer $created_at
 * @property integer $updated_at
 * @property string $password write-only password
 * @property string $full_name
 */
class User extends ActiveRecord implements IdentityInterface
{
    use AccountTypeTrait;

    const STATUS_DELETED = 0;
    const STATUS_INACTIVE = 9;
    const STATUS_ACTIVE = 10;

    /**
     * Account types (user.role). They decide which version of a page someone gets (a patient's
     * calendar or a consultant's) and the RBAC role they start with. They are not permissions:
     * what someone may do is checked with Yii::$app->user->can().
     */
    const ACCOUNT_PATIENT = 'client';
    const ACCOUNT_CONSULTANT = 'consultant';
    const ACCOUNT_FRONT_DESK = 'front_desk';
    const ACCOUNT_ADMIN = 'admin';


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%user}}';
    }

    // Find user by OAuth provider and client ID
    public static function findByAuthClient($provider, $clientId)
    {
        return self::findOne(['auth_provider' => $provider, 'auth_client_id' => $clientId]);
    }

    /**
     * Finds, links or creates the user for an OAuth login.
     *
     * An existing account is only linked by email when the provider has verified that email,
     * otherwise anyone could take over an account by registering its address with a provider.
     *
     * @return static|null the user to log in, or null if no usable account could be resolved
     */
    public static function createOrUpdateFromOauth($provider, $clientId, $email, $name, $emailVerified = true)
    {
        $user = self::findByAuthClient($provider, $clientId);
        if ($user) {
            return (int) $user->status === self::STATUS_DELETED ? null : $user;
        }

        $user = self::findOne(['email' => $email]);
        if ($user) {
            if (!$emailVerified || (int) $user->status === self::STATUS_DELETED) {
                return null;
            }
            $user->auth_provider = $provider;
            $user->auth_client_id = (string) $clientId;
            // The provider has proven ownership of the address
            $user->status = self::STATUS_ACTIVE;
            return $user->save(false) ? $user : null;
        }

        $user = new self();
        $user->auth_provider = $provider;
        $user->auth_client_id = (string) $clientId;
        // OAuth accounts sign in through the provider; the password is random and never disclosed
        $user->setPassword(Yii::$app->security->generateRandomString(32));
        $user->email = $email;
        $user->username = self::generateUniqueUsername($name ?: strstr($email, '@', true));
        $user->full_name = $name;
        $user->generateAuthKey();
        $user->role = 'client'; // Default role
        $user->status = $emailVerified ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;

        return $user->save(false) ? $user : null;
    }

    /**
     * Returns $base, or $base with a numeric suffix if that username is already taken.
     */
    public static function generateUniqueUsername($base)
    {
        $base = mb_substr(trim((string) $base), 0, 240) ?: 'user';
        $username = $base;
        while (self::find()->where(['username' => $username])->exists()) {
            $username = $base . random_int(1000, 999999);
        }

        return $username;
    }

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            TimestampBehavior::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            ['status', 'default', 'value' => self::STATUS_INACTIVE],
            ['status', 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_DELETED]],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function findIdentity($id)
    {
        return static::findOne(['id' => $id, 'status' => self::STATUS_ACTIVE]);
    }

    /**
     * {@inheritdoc}
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        throw new NotSupportedException('"findIdentityByAccessToken" is not implemented.');
    }

    /**
     * Finds user by username
     *
     * @param string $username
     * @return static|null
     */
    public static function findByUsername($username)
    {
        return static::findOne(['username' => $username, 'status' => self::STATUS_ACTIVE]);
    }

    /**
     * Finds user by password reset token
     *
     * @param string $token password reset token
     * @return static|null
     */
    public static function findByPasswordResetToken($token)
    {
        if (!static::isPasswordResetTokenValid($token)) {
            return null;
        }

        return static::findOne([
            'password_reset_token' => $token,
            'status' => self::STATUS_ACTIVE,
        ]);
    }

    /**
     * Finds user by verification email token
     *
     * @param string $token verify email token
     * @return static|null
     */
    public static function findByVerificationToken($token)
    {
        return static::findOne([
            'verification_token' => $token,
            'status' => self::STATUS_INACTIVE
        ]);
    }

    /**
     * Finds out if password reset token is valid
     *
     * @param string $token password reset token
     * @return bool
     */
    public static function isPasswordResetTokenValid($token)
    {
        if (empty($token)) {
            return false;
        }

        $timestamp = (int) substr($token, strrpos($token, '_') + 1);
        $expire = Yii::$app->params['user.passwordResetTokenExpire'];
        return $timestamp + $expire >= time();
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->getPrimaryKey();
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthKey()
    {
        return $this->auth_key;
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthKey($authKey)
    {
        return $this->getAuthKey() === $authKey;
    }

    /**
     * Validates password
     *
     * @param string $password password to validate
     * @return bool if password provided is valid for current user
     */
    public function validatePassword($password)
    {
        return Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    /**
     * Generates password hash from password and sets it to the model
     *
     * @param string $password
     */
    public function setPassword($password)
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash($password);
    }

    /**
     * Generates "remember me" authentication key
     */
    public function generateAuthKey()
    {
        $this->auth_key = Yii::$app->security->generateRandomString();
    }

    /**
     * Generates new password reset token
     */
    public function generatePasswordResetToken()
    {
        $this->password_reset_token = Yii::$app->security->generateRandomString() . '_' . time();
    }

    /**
     * Generates new token for email verification
     */
    public function generateEmailVerificationToken()
    {
        $this->verification_token = Yii::$app->security->generateRandomString() . '_' . time();
    }

    /**
     * Removes password reset token
     */
    public function removePasswordResetToken()
    {
        $this->password_reset_token = null;
    }

    // get consultancy profile
    public function getConsultancy()
    {
        return $this->hasOne(\frontend\models\Consultant::class, ['user_id' => 'id']);
    }

    /**
     * New users get the RBAC role matching their `role` column (consultant / patient / consultancy-admin),
     * and the assignment follows the column when it changes.
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if ($insert || array_key_exists('role', $changedAttributes)) {
            \common\components\RbacHelper::syncNativeRole(
                $this->id,
                $this->role,
                $insert ? null : $changedAttributes['role']
            );
        }
    }
}
