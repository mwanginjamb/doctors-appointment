<?php

namespace app\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use app\models\Appointments;

/**
 * AppointmentsSearch represents the model behind the search form of `app\models\Appointments`.
 */
class AppointmentsSearch extends Appointments
{
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id', 'patient_id', 'speciality_id', 'service_id', 'provider_id', 'recurring_appointment', 'walk_in_appointment', 'created_at', 'updated_at', 'created_by', 'updated_by', 'consultant_id'], 'integer'],
            [['date', 'time', 'location', 'symptoms_brief'], 'safe'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    /**
     * Creates data provider instance with search query applied
     *
     * @param array $params
     * @param string|null $formName Form name to be used into `->load()` method.
     *
     * @return ActiveDataProvider
     */
    public function search($params, $formName = null)
    {
        $query = Appointments::find();

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        $this->load($params, $formName);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'id' => $this->id,
            'date' => $this->date,
            'time' => $this->time,
            'patient_id' => $this->patient_id,
            'speciality_id' => $this->speciality_id,
            'service_id' => $this->service_id,
            'provider_id' => $this->provider_id,
            'recurring_appointment' => $this->recurring_appointment,
            'walk_in_appointment' => $this->walk_in_appointment,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'consultant_id' => $this->consultant_id,
        ]);

        $query->andFilterWhere(['like', 'location', $this->location])
            ->andFilterWhere(['like', 'symptoms_brief', $this->symptoms_brief]);

        return $dataProvider;
    }
}
