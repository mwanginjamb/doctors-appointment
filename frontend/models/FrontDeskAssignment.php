<?php

namespace frontend\models;

use yii\db\ActiveRecord;

/**
 * A consultant a front-desk user books for.
 *
 * @property int $id
 * @property int $user_id the front-desk user
 * @property int $consultant_id consultant.id
 * @property int|null $created_at
 * @property int|null $created_by
 */
class FrontDeskAssignment extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%front_desk_assignment}}';
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }

    /**
     * @return int[] consultant.id values assigned to a front-desk user
     */
    public static function consultantIdsFor($userId)
    {
        return array_map('intval', static::find()->select('consultant_id')->where(['user_id' => $userId])->column());
    }

    /**
     * Replaces a front-desk user's consultants with $consultantIds (consultant.id values).
     */
    public static function replaceFor($userId, array $consultantIds)
    {
        $db = static::getDb();
        $transaction = $db->beginTransaction();
        try {
            $before = static::consultantIdsFor($userId);
            static::deleteAll(['user_id' => $userId]);
            $now = time();
            $by = \Yii::$app->get('user', false) && !\Yii::$app->user->isGuest ? \Yii::$app->user->id : null;
            $rows = [];
            foreach (array_unique(array_map('intval', $consultantIds)) as $consultantId) {
                $rows[] = [$userId, $consultantId, $now, $by];
            }
            if ($rows) {
                $db->createCommand()->batchInsert(static::tableName(), ['user_id', 'consultant_id', 'created_at', 'created_by'], $rows)->execute();
            }
            // Saved in bulk, so the audit behaviour does not see it. Logged by consultant name.
            $after = array_values(array_unique(array_map('intval', $consultantIds)));
            sort($before);
            sort($after);
            if ($before !== $after) {
                $names = Consultant::find()->select(['names', 'id'])->where(['id' => array_merge($before, $after)])->indexBy('id')->column();
                $label = function (array $ids) use ($names) {
                    return implode(', ', array_map(function ($id) use ($names) {
                        return $names[$id] ?? "#$id";
                    }, $ids)) ?: null;
                };
                \common\components\Audit::log(\common\models\AuditLog::ACTION_ASSIGNMENTS_CHANGED, 'user', $userId, [
                    'consultants' => [$label($before), $label($after)],
                ]);
            }
            $transaction->commit();
            \frontend\components\FrontDesk::reset();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
