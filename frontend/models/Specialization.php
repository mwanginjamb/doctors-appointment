<?php

namespace frontend\models;

use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;

/**
 * This is the model class for table "specialization".
 *
 * @property int $id
 * @property int|null $parent_id main area this sub-specialty belongs to (NULL for a main area)
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
            [['specialization'], 'trim'],

            // Two levels only: a parent must be a main area, and a main area with children cannot get a parent
            [['parent_id'], 'default', 'value' => null],
            [['parent_id'], 'integer'],
            [['parent_id'], 'validateParent'],
        ];
    }

    public function validateParent($attribute)
    {
        if ($this->parent_id === null) {
            return;
        }
        $parent = self::findOne($this->parent_id);
        if ($parent === null) {
            $this->addError($attribute, 'The selected main area does not exist.');
        } elseif (!$this->isNewRecord && (int) $parent->id === (int) $this->id) {
            $this->addError($attribute, 'A specialization cannot be its own main area.');
        } elseif ($parent->parent_id !== null) {
            $this->addError($attribute, "'{$parent->specialization}' is itself a sub-specialty; choose a main area.");
        } elseif (!$this->isNewRecord && $this->getChildren()->exists()) {
            $this->addError($attribute, 'This specialization has sub-specialties of its own, so it must stay a main area.');
        }
    }

    public function getParent()
    {
        return $this->hasOne(self::class, ['id' => 'parent_id']);
    }

    public function getChildren()
    {
        return $this->hasMany(self::class, ['parent_id' => 'id'])->orderBy(['specialization' => SORT_ASC]);
    }

    /**
     * Main areas as id => name, for the admin "main area" dropdown.
     * @param int|null $excludeId
     */
    public static function mainAreaOptions($excludeId = null)
    {
        return self::find()
            ->select(['specialization', 'id'])
            ->where(['parent_id' => null])
            ->andFilterWhere(['<>', 'id', $excludeId])
            ->orderBy(['specialization' => SORT_ASC])
            ->indexBy('id')
            ->column();
    }

    /**
     * The whole catalogue grouped for pickers:
     *   ['main' => [name => ['description' => .., 'children' => [names]]],
     *    'sub'  => [name => ['description' => .., 'parent' => main name]]]
     */
    public static function catalogue()
    {
        $rows = self::find()
            ->select(['id', 'parent_id', 'specialization', 'description'])
            ->where(['not', ['specialization' => null]])
            ->orderBy(['specialization' => SORT_ASC])
            ->asArray()
            ->all();

        $names = array_column($rows, 'specialization', 'id');
        $main = $sub = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] === null || !isset($names[$row['parent_id']])) {
                $main[$row['specialization']] = ['description' => (string) $row['description'], 'children' => []];
            }
        }
        foreach ($rows as $row) {
            $parentName = $row['parent_id'] !== null ? ($names[$row['parent_id']] ?? null) : null;
            if ($parentName === null) {
                continue;
            }
            if (isset($main[$parentName])) {
                $sub[$row['specialization']] = ['description' => (string) $row['description'], 'parent' => $parentName];
                $main[$parentName]['children'][] = $row['specialization'];
            } else {
                // Parent is not a main area (should not happen); list it as a main area rather than lose it
                $main[$row['specialization']] = ['description' => (string) $row['description'], 'children' => []];
            }
        }

        return ['main' => $main, 'sub' => $sub];
    }

    /**
     * Sub-specialty names under a main area (case-insensitive), used to widen searches.
     * @param string $mainName
     * @return string[]
     */
    public static function childNamesOf($mainName)
    {
        return self::find()
            ->alias('s')
            ->select('s.specialization')
            ->innerJoin(['p' => self::tableName()], 'p.id = s.parent_id')
            ->where(['LOWER(p.specialization)' => mb_strtolower(trim($mainName))])
            ->column();
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
            'parent_id' => 'Main area',
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
