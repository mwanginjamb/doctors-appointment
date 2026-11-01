<?php

namespace frontend\queries;

/**
 * This is the ActiveQuery class for [[\frontend\models\Consultant]].
 *
 * @see \frontend\models\Consultant
 */
class ConsultantQuery extends \yii\db\ActiveQuery
{
    /*public function active()
    {
        return $this->andWhere('[[status]]=1');
    }*/
    // eager load gender relationship
    public function init()
    {
        parent::init();
        $this->with('genderIdentity');
    }

    /**
     * {@inheritdoc}
     * @return \frontend\models\Consultant[]|array
     */
    public function all($db = null)
    {
        return parent::all($db);
    }

    /**
     * {@inheritdoc}
     * @return \frontend\models\Consultant|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
