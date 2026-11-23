<?php

namespace frontend\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * F30: a patient's 1–5 rating of the consultant for one attended appointment.
 *
 * @property int $id
 * @property int $appointment_id
 * @property int $consultant_id consultant.id
 * @property int $patient_id
 * @property int|null $facility_id
 * @property int $stars 1 to 5
 * @property string|null $comment private: the consultant (anonymously) and admins only
 * @property int $withdrawn 1 when the visit was corrected to a no-show
 * @property int|null $created_at
 * @property int|null $updated_at
 *
 * @property Appointments $appointment
 * @property Consultant $consultant
 */
class ConsultantRating extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%consultant_rating}}';
    }

    public function behaviors()
    {
        return [TimestampBehavior::class];
    }

    public function rules()
    {
        return [
            [['stars'], 'required', 'message' => \Yii::t('app', 'Choose from 1 to 5 stars.')],
            [['stars'], 'integer', 'min' => 1, 'max' => 5, 'tooSmall' => \Yii::t('app', 'Choose from 1 to 5 stars.'), 'tooBig' => \Yii::t('app', 'Choose from 1 to 5 stars.')],
            [['comment'], 'trim'],
            [['comment'], 'string', 'max' => 1000],
            [['comment'], 'default', 'value' => null],
        ];
    }

    public function getAppointment()
    {
        return $this->hasOne(Appointments::class, ['id' => 'appointment_id']);
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }
}
