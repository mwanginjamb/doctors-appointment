<?php

namespace frontend\queries;

/**
 * This is the ActiveQuery class for [[\frontend\models\Appointments]].
 *
 * @see \frontend\models\Appointments
 */
class AppointmentsQuery extends \yii\db\ActiveQuery
{
    /*public function active()
    {
        return $this->andWhere('[[status]]=1');
    }*/

    // sort by date and time in descending order by default
    public function init()
    {
        parent::init();
        $this->orderBy(['date' => SORT_DESC, 'time' => SORT_DESC]);
    }

    /**
     * {@inheritdoc}
     * @return \frontend\models\Appointments[]|array
     */
    public function all($db = null)
    {
        return parent::all($db);
    }

    /**
     * {@inheritdoc}
     * @return \frontend\models\Appointments|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
