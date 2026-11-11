<?php

namespace frontend\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;
use frontend\models\Consultant;

/**
 * ConsultantSearch represents the model behind the search form of `frontend\models\Consultant`.
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
        $this->addSubSpecialitySearch($query);

        $query->andFilterWhere(['like', 'consultant.names', $this->names])
            ->andFilterWhere(['like', 'license_number', $this->license_number])
            ->andFilterWhere(['like', 'kmpdc_registration_number', $this->kmpdc_registration_number])
            ->andFilterWhere(['like', 'practice_type', $this->practice_type]);

        // F8: a facility, or a location, also matches the facilities the consultant works at
        if ((string) $this->facility !== '') {
            $query->andWhere(['or',
                ['like', 'consultant.facility', $this->facility],
                ['consultant.id' => $this->engagedAt(['like', 'f.name', $this->facility])],
            ]);
        }
        if ((string) $this->physical_address !== '') {
            $query->andWhere(['or',
                ['like', 'consultant.physical_address', $this->physical_address],
                ['consultant.id' => $this->engagedAt(['or', ['like', 'f.name', $this->physical_address], ['like', 'f.address', $this->physical_address]])],
            ]);
        }

        return $dataProvider;
    }

    /**
     * Area of specialization. A main area also finds consultants filed under any of its
     * sub-specialties, e.g. "Dentistry" finds an orthodontist whose speciality is "Orthodontics".
     */
    private function addSpecialitySearch($query)
    {
        $term = trim((string) $this->speciality);
        if ($term === '') {
            return;
        }

        $names = array_merge([$term], Specialization::childNamesOf($term));
        $onProfile = ['or'];
        $onLinks = ['or'];
        foreach ($names as $name) {
            $onProfile[] = ['like', 'consultant.speciality', $name];
            $onLinks[] = ['like', 'name', $name];
        }

        $query->andWhere(['or',
            $onProfile,
            ['in', 'consultant.id', ConsultantSubSpeciality::find()->select('consultant_id')->where($onLinks)],
        ]);
    }

    /**
     * Sub-specialty: matches the consultant's sub-specialties, or a speciality saved as that
     * sub-specialty before sub-specialties existed.
     */
    private function addSubSpecialitySearch($query)
    {
        $term = trim((string) $this->sub_speciality);
        if ($term === '') {
            return;
        }

        $query->andWhere(['or',
            ['in', 'consultant.id', ConsultantSubSpeciality::find()->select('consultant_id')->where(['like', 'name', $term])],
            ['like', 'consultant.speciality', $term],
        ]);
    }

    /**
     * Ids of consultants engaged at a facility matching $condition (on facility alias "f").
     */
    private function engagedAt(array $condition)
    {
        return ConsultantFacility::find()
            ->alias('cf')
            ->select('cf.consultant_id')
            ->innerJoin(Facility::tableName() . ' f', 'f.id = cf.facility_id')
            ->where($condition);
    }
}
