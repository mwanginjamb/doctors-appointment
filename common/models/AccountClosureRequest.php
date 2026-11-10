<?php

namespace common\models;

use common\behaviors\AuditBehavior;
use Yii;
use yii\db\ActiveRecord;

/**
 * A patient withdrew consent to data processing and asked for their account to be closed (F11).
 * Admins handle it: deactivate the account (and, with F12, export or delete the data) and close the
 * request. A patient who gives consent again withdraws their request.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $reason
 * @property string $status
 * @property int $created_at
 * @property int|null $handled_by
 * @property int|null $handled_at
 * @property string|null $handled_note
 *
 * @property User $user
 * @property User|null $handler
 */
class AccountClosureRequest extends ActiveRecord
{
    const STATUS_OPEN = 'open';
    const STATUS_CLOSED = 'closed';
    const STATUS_WITHDRAWN = 'withdrawn';

    public static function tableName()
    {
        return '{{%account_closure_request}}';
    }

    public function behaviors()
    {
        return ['audit' => ['class' => AuditBehavior::class, 'entityType' => 'closure_request']];
    }

    public function rules()
    {
        return [
            [['reason', 'handled_note'], 'trim'],
            [['reason', 'handled_note'], 'string', 'max' => 500],
            [['status'], 'in', 'range' => [self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_WITHDRAWN]],
        ];
    }

    /** The patient's open request, if any. */
    public static function openFor($userId)
    {
        return self::findOne(['user_id' => (int) $userId, 'status' => self::STATUS_OPEN]);
    }

    /**
     * Opens a request (or returns the one already open).
     */
    public static function open($userId, $reason = null)
    {
        if ($existing = self::openFor($userId)) {
            return $existing;
        }
        $request = new self([
            'user_id' => (int) $userId,
            'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null,
            'status' => self::STATUS_OPEN,
            'created_at' => time(),
        ]);
        $request->save(false);
        return $request;
    }

    /** Marks the request handled by the signed-in admin. */
    public function close($note = null)
    {
        $this->status = self::STATUS_CLOSED;
        $this->handled_by = Yii::$app->user->id;
        $this->handled_at = time();
        $this->handled_note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;
        return $this->save(false);
    }

    /** The patient changed their mind and consented again. */
    public function withdraw()
    {
        $this->status = self::STATUS_WITHDRAWN;
        $this->handled_at = time();
        $this->handled_note = 'The patient gave consent again.';
        return $this->save(false);
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getHandler()
    {
        return $this->hasOne(User::class, ['id' => 'handled_by']);
    }
}
