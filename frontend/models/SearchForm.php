<?php
namespace frontend\models;

use Yii;
use yii\base\Model;
use frontend\models\ConsultantSearch;


class SearchForm extends Model
{
    public $speciality;
    public $sub_speciality;
    public $physical_address;
    public $service = [];

    public function rules()
    {
        return [
            [['speciality', 'sub_speciality', 'physical_address'], 'filter', 'filter' => function ($value) {
                return trim((string) $value);
            }],
            [['speciality', 'sub_speciality'], 'string', 'max' => 250],
            [['physical_address'], 'string'],
            ['service', 'string'],
            ['speciality', 'validateSomethingToSearch', 'skipOnEmpty' => false],
        ];
    }

    public function validateSomethingToSearch($attribute)
    {
        if ($this->speciality === '' && $this->sub_speciality === '' && $this->physical_address === '') {
            $this->addError($attribute, 'Choose an area of specialization, a sub-speciality or a location.');
        }
    }

    public function attributeLabels()
    {
        return [
            'speciality' => 'Area of Specialization',
            'sub_speciality' => 'Sub-speciality',
            'physical_address' => 'Location',
        ];
    }


    public function findConsultants($params, $formName)
    {
        $model = new ConsultantSearch();
        $results = $model->search($params, $formName)->getModels();
        return $results;
    }
}
