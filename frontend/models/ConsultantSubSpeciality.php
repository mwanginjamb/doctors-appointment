<?php

namespace frontend\models;

use yii\behaviors\TimestampBehavior;

/**
 * A sub-specialty held by a consultant.
 *
 * @property int $id
 * @property int $consultant_id
 * @property int|null $specialization_id catalogue entry, NULL when the consultant typed a name not in it
 * @property string $name
 * @property int|null $created_at
 *
 * @property Consultant $consultant
 * @property Specialization|null $specialization
 */
class ConsultantSubSpeciality extends \yii\db\ActiveRecord
{
    public static function tableName()
    {
        return '{{%consultant_sub_speciality}}';
    }

    public function behaviors()
    {
        return [
            ['class' => TimestampBehavior::class, 'updatedAtAttribute' => false],
        ];
    }

    public function rules()
    {
        return [
            [['consultant_id', 'name'], 'required'],
            [['consultant_id', 'specialization_id'], 'integer'],
            [['name'], 'string', 'max' => 250],
        ];
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }

    public function getSpecialization()
    {
        return $this->hasOne(Specialization::class, ['id' => 'specialization_id']);
    }
}
