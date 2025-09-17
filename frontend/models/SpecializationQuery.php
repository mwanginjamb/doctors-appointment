<?php

namespace frontend\models;

/**
 * This is the ActiveQuery class for [[Specialization]].
 *
 * @see Specialization
 */
class SpecializationQuery extends \yii\db\ActiveQuery
{
    /*public function active()
    {
        return $this->andWhere('[[status]]=1');
    }*/

    /**
     * {@inheritdoc}
     * @return Specialization[]|array
     */
    public function all($db = null)
    {
        return parent::all($db);
    }

    /**
     * {@inheritdoc}
     * @return Specialization|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
