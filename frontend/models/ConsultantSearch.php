<?php

namespace frontend\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use frontend\models\Consultant;

/**
 * ConsultantSearch represents the model behind the search form of `app\models\Consultant`.
 */
class ConsultantSearch extends Consultant
{
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id', 'user_id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'consultant_id'], 'integer'],
            [['names', 'license_number', 'speciality', 'sub_speciality', 'kmpdc_registration_number', 'facility', 'physical_address', 'practice_type'], 'safe'],
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
        $query = Consultant::find();

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
            'user_id' => $this->user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'consultant_id' => $this->consultant_id,
            'practice_type' => $this->practice_type,
        ]);

        $this->addSpecialitySearch($query);

        $query->andFilterWhere(['like', 'names', $this->names])
            ->andFilterWhere(['like', 'license_number', $this->license_number])
            //->andFilterWhere(['like', 'speciality', $this->speciality])
            ->andFilterWhere(['like', 'sub_speciality', $this->sub_speciality])
            ->andFilterWhere(['like', 'kmpdc_registration_number', $this->kmpdc_registration_number])
            ->andFilterWhere(['like', 'facility', $this->facility])
            ->andFilterWhere(['like', 'physical_address', $this->physical_address])
            ->andFilterWhere(['like', 'practice_type', $this->practice_type]);

        return $dataProvider;
    }

    private function addSpecialitySearch($query)
    {
        if (!empty($this->speciality)) {
            $speciality = trim($this->speciality);

            // Multiple keyword search (recommended for better UX)
            $keywords = preg_split('/\s+/', $speciality);
            $keywords = array_filter($keywords); // Remove empty elements

            if (!empty($keywords)) {
                $conditions = ['or'];
                foreach ($keywords as $keyword) {
                    // Each keyword should match somewhere in the speciality field
                    $conditions[] = ['like', 'LOWER(speciality)', strtolower($this->speciality)];
                }
                $query->andWhere($conditions);
            }

        }
    }
}
