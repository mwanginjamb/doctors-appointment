<?php

namespace frontend\models;

use yii\db\ActiveRecord;

/**
 * F14: an appointment's patient was told it clashes with a closure, time off or holiday.
 *
 * @property int $id
 * @property int $appointment_id
 * @property string $source_type closure, time_off or holiday
 * @property int $source_id facility_closure.id, consultant_time_off.id or public_holiday.id
 * @property int|null $told_at
 * @property int|null $told_by
 * @property int|null $staff_reminded_at the day before, staff were told the patient had not moved
 * @property int|null $created_at
 *
 * @property Appointments $appointment
 */
class AppointmentClash extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%appointment_clash}}';
    }

    public function getAppointment()
    {
        return $this->hasOne(Appointments::class, ['id' => 'appointment_id']);
    }
}
