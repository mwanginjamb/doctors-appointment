<?php

namespace frontend\models;

use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;

/**
 * This is the model class for table "specialization".
 *
 * @property int $id
 * @property string|null $specialization
 * @property string|null $description
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Specialization extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'specialization';
    }

    public function behaviors()
    {
        return [
            BlameableBehavior::class,
            TimestampBehavior::class
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['specialization', 'description', 'created_at', 'updated_at', 'created_by', 'updated_by'], 'default', 'value' => null],
            [['description'], 'string'],
            [['created_at', 'updated_at', 'created_by', 'updated_by'], 'integer'],
            [['specialization'], 'string', 'max' => 250],
            // make specialization unique
            [['specialization'], 'unique', 'message' => 'Specialization already exists'],
            [['specialization'], 'required'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'specialization' => 'Specialization',
            'description' => 'Description',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'created_by' => 'Created By',
            'updated_by' => 'Updated By',
        ];
    }

    /**
     * {@inheritdoc}
     * @return SpecializationQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new SpecializationQuery(get_called_class());
    }

}
