<?php

namespace app\queries;

/**
 * This is the ActiveQuery class for [[\app\models\Consultant]].
 *
 * @see \app\models\Consultant
 */
class ConsultantQuery extends \yii\db\ActiveQuery
{
    /*public function active()
    {
        return $this->andWhere('[[status]]=1');
    }*/

    /**
     * {@inheritdoc}
     * @return \app\models\Consultant[]|array
     */
    public function all($db = null)
    {
        return parent::all($db);
    }

    /**
     * {@inheritdoc}
     * @return \app\models\Consultant|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
