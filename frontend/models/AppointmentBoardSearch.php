<?php

namespace frontend\models;

use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * Filters for the clinic-wide appointments board: a date range (today by default), consultant,
 * status and a patient search. Parameters are read without a form prefix (?from=&to=&consultant=...).
 */
class AppointmentBoardSearch extends Model
{
    /** Status filter value meaning "scheduled or confirmed". */
    const STATUS_ACTIVE = 'active';

    public $from;
    public $to;
    public $consultant;
    public $status;
    public $q;
    /** @var int|null facility filter (admins; front desk is held to $facilityId) */
    public $facility;

    /** @var int[]|null consultant user ids the viewer may see; null = all (admins). Not a filter field. */
    public $consultantUserIds;

    /** @var int|null the only facility the viewer may see (front desk); null = all. Not a filter field. */
    public $facilityId;

    public function rules()
    {
        return [
            [['from', 'to'], 'date', 'format' => 'php:Y-m-d'],
            [['consultant', 'facility'], 'integer'],
            [['status'], 'in', 'range' => array_merge([self::STATUS_ACTIVE], array_keys(Appointments::getStatusList()))],
            [['q'], 'trim'],
            [['q'], 'string', 'max' => 100],
        ];
    }

    public function formName()
    {
        return '';
    }

    /**
     * @return array consultant user id => name, for the filter (only those the viewer may see)
     */
    public function consultantOptions()
    {
        return Consultant::find()
            ->select(['names', 'user_id'])
            ->where(['not', ['user_id' => null]])
            ->andFilterWhere(['user_id' => $this->consultantUserIds === null ? null : ($this->consultantUserIds ?: [0])])
            ->orderBy(['names' => SORT_ASC])
            ->indexBy('user_id')
            ->column();
    }

    /**
     * @return array facility id => name, for the filter: every facility for admins, none for front
     * desk (they only ever see their own)
     */
    public function facilityOptions()
    {
        if ($this->facilityId !== null) {
            return [];
        }

        return Facility::find()->select(['name', 'id'])->orderBy(['name' => SORT_ASC])->indexBy('id')->column();
    }

    public static function statusOptions()
    {
        return [self::STATUS_ACTIVE => Yii::t('app', 'Scheduled or confirmed')] + Appointments::getStatusList();
    }

    /**
     * @return ActiveDataProvider appointments in the range, earliest first
     */
    public function search($params)
    {
        $this->load($params);
        if (!$this->validate()) {
            // Fall back to today rather than showing every appointment ever booked
            foreach ($this->getErrors() as $attribute => $errors) {
                $this->$attribute = null;
            }
            $this->clearErrors();
        }

        $this->from = $this->from ?: date('Y-m-d');
        $this->to = $this->to ?: $this->from;
        if ($this->to < $this->from) {
            [$this->from, $this->to] = [$this->to, $this->from];
        }

        $query = Appointments::find()
            ->alias('a')
            ->joinWith(['patient p', 'consultant c'])
            ->with(['creator', 'facility'])
            ->orderBy(null) // drop AppointmentsQuery's newest-first default; the sort below applies
            ->andWhere(['between', 'a.date', $this->from, $this->to])
            ->andFilterWhere(['a.consultant_id' => $this->consultant])
            ->andFilterWhere(['a.facility_id' => $this->facility]);
        $this->applyScope($query, 'a');

        if ($this->status === self::STATUS_ACTIVE) {
            $query->andWhere(['or', ['a.status' => null], ['a.status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]]);
        } elseif ($this->status) {
            $query->andWhere(['a.status' => $this->status]);
        }

        if ($this->q !== null && $this->q !== '') {
            $condition = ['or', ['like', 'p.full_name', $this->q], ['like', 'p.email', $this->q]];
            $digits = preg_replace('/\D/', '', $this->q);
            if (strlen($digits) >= 4) {
                // Last digits match whatever prefix the number was stored with (07…, 254…, +254…)
                $condition[] = ['like', 'p.phone_number', substr($digits, -9)];
            }
            $query->andWhere($condition);
        }

        return new ActiveDataProvider([
            'query' => $query,
            'pagination' => ['pageSize' => 50],
            'sort' => [
                'defaultOrder' => ['when' => SORT_ASC],
                'attributes' => [
                    'when' => [
                        'asc' => ['a.date' => SORT_ASC, 'a.time' => SORT_ASC],
                        'desc' => ['a.date' => SORT_DESC, 'a.time' => SORT_DESC],
                        'label' => Yii::t('app', 'When'),
                    ],
                    'consultant' => [
                        'asc' => ['c.names' => SORT_ASC, 'a.date' => SORT_ASC, 'a.time' => SORT_ASC],
                        'desc' => ['c.names' => SORT_DESC, 'a.date' => SORT_ASC, 'a.time' => SORT_ASC],
                        'label' => Yii::t('app', 'Consultant'),
                    ],
                    'status' => [
                        'asc' => ['a.status' => SORT_ASC, 'a.date' => SORT_ASC, 'a.time' => SORT_ASC],
                        'desc' => ['a.status' => SORT_DESC, 'a.date' => SORT_ASC, 'a.time' => SORT_ASC],
                        'label' => Yii::t('app', 'Status'),
                    ],
                ],
            ],
        ]);
    }

    /**
     * Appointments in the viewer's scope that have started, in the last $days days, with no outcome
     * recorded yet (still scheduled or confirmed). Oldest first, so the backlog is worked through.
     *
     * @return Appointments[]
     */
    public function needsOutcome($days = 30, $limit = 50)
    {
        $query = Appointments::find()
            ->orderBy(['date' => SORT_ASC, 'time' => SORT_ASC])
            ->with(['patient', 'consultant'])
            ->andWhere(['between', 'date', date('Y-m-d', strtotime("-{$days} days")), date('Y-m-d')])
            ->andWhere(['or', ['status' => null], ['status' => [Appointments::STATUS_SCHEDULED, Appointments::STATUS_CONFIRMED]]])
            ->andWhere(['or', ['<', 'date', date('Y-m-d')], ['<=', 'time', date('H:i:s')]]);
        $this->applyScope($query, 'appointments');

        return $query->limit($limit)->all();
    }

    /**
     * Front desk only ever sees their assigned consultants ([0] matches nothing when they have
     * none), at their facility.
     */
    private function applyScope($query, $alias)
    {
        if ($this->consultantUserIds !== null) {
            $query->andWhere(["$alias.consultant_id" => $this->consultantUserIds ?: [0]]);
        }
        if ($this->facilityId !== null) {
            $query->andWhere(["$alias.facility_id" => $this->facilityId]);
        }
    }

    /**
     * Appointment counts by status for the current filters (ignoring the status filter itself).
     *
     * @return array status => count
     */
    public function countsByStatus()
    {
        $filters = clone $this;
        $filters->status = null;
        // search([]) loads no params, so the copied filters stay as they are
        $rows = $filters->search([])->query
            ->select(['status' => 'a.status', 'n' => 'COUNT(*)'])
            ->groupBy(['a.status'])
            ->orderBy(null)
            ->createCommand()
            ->queryAll();

        $counts = [];
        foreach ($rows as $row) {
            $status = $row['status'] ?: Appointments::STATUS_SCHEDULED; // legacy rows without a status
            $counts[$status] = ($counts[$status] ?? 0) + (int) $row['n'];
        }

        return $counts;
    }
}
