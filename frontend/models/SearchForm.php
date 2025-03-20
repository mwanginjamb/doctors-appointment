<?php
namespace app\models;

use yii\base\Model;
use Yii;


class SearchForm extends Model
{
    public $speciality;
    public $physical_address;
    public $service = [];

    public function rules()
    {
        return [
            [['speciality'], 'required'],
            [['physical_address'], 'string'],
            ['service', 'string'],
        ];
    }


    public function attributeLabels()
    {
        return [
            'speciality' => 'Area of Specialization',
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