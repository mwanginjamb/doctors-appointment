<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A place consultants see patients: a hospital, clinic or consulting room. Consultants are engaged
 * at facilities (ConsultantFacility), and each block of their weekly hours is at one of them.
 *
 * An inactive facility keeps its history but offers no new slots.
 *
 * @property int $id
 * @property string $name
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $map_url
 * @property bool|int $active
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property ConsultantFacility[] $engagements
 */
class Facility extends ActiveRecord
{
    /** Name given to a facility made for a consultant who named none. */
    const FALLBACK_NAME = 'Main clinic';

    /**
     * Calendar colours, chosen by facility id. Distinct at a glance and readable with white text.
     */
    const COLORS = ['#0d6efd', '#198754', '#6f42c1', '#d63384', '#fd7e14', '#20c997', '#6610f2', '#0dcaf0'];

    public static function tableName()
    {
        return '{{%facility}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'facility'],
        ];
    }

    public function rules()
    {
        return [
            [['name', 'address', 'phone', 'map_url'], 'trim'],
            [['name'], 'required'],
            [['name'], 'string', 'max' => 150],
            [['name'], 'unique', 'message' => Yii::t('app', 'There is already a facility with this name.')],
            [['address'], 'string', 'max' => 1000],
            [['address', 'phone', 'map_url'], 'default', 'value' => null],
            [['phone'], 'match', 'pattern' => '/^\+?[0-9][0-9 ]{6,18}$/', 'message' => Yii::t('app', 'Enter a phone number such as 0712 345 678.')],
            [['map_url'], 'url', 'defaultScheme' => 'https'],
            [['map_url'], 'string', 'max' => 500],
            [['active'], 'boolean'],
            [['active'], 'default', 'value' => true],
        ];
    }

    public function attributeLabels()
    {
        return [
            'name' => Yii::t('app', 'Name'),
            'address' => Yii::t('app', 'Address'),
            'phone' => Yii::t('app', 'Phone'),
            'map_url' => Yii::t('app', 'Map link'),
            'active' => Yii::t('app', 'Taking bookings'),
        ];
    }

    public function attributeHints()
    {
        return [
            'address' => Yii::t('app', 'Sent to patients with every confirmation and reminder.'),
            'map_url' => Yii::t('app', 'e.g. a Google Maps link, so patients find the way.'),
            'active' => Yii::t('app', 'Untick when the clinic stops seeing patients here: nobody can book it, and appointments already booked are kept.'),
        ];
    }

    public function getEngagements()
    {
        return $this->hasMany(ConsultantFacility::class, ['facility_id' => 'id']);
    }

    public function isActive()
    {
        return (bool) $this->active;
    }

    /**
     * The facility called $name (case-insensitive), created with $address when there is none.
     * Used when a consultant names their facility on their profile.
     */
    public static function findOrCreateByName($name, $address = null)
    {
        $name = mb_substr(preg_replace('/\s+/', ' ', trim((string) $name)), 0, 150) ?: self::FALLBACK_NAME;
        $facility = static::find()->where(['name' => $name])->one();
        if ($facility === null) {
            $facility = new static(['name' => $name, 'address' => $address ?: null, 'active' => true]);
            $facility->save(false);
        }

        return $facility;
    }

    /**
     * Active facilities as id => name, for drop-down lists.
     *
     * @param int[] $exclude ids to leave out (e.g. where the consultant is already engaged)
     */
    public static function activeList(array $exclude = [])
    {
        return static::find()
            ->select(['name', 'id'])
            ->where(['active' => true])
            ->andFilterWhere(['not in', 'id', $exclude])
            ->orderBy(['name' => SORT_ASC])
            ->indexBy('id')
            ->column();
    }

    /** This facility's colour on calendars. */
    public function getColor()
    {
        return self::colorFor($this->id);
    }

    public static function colorFor($id)
    {
        return self::COLORS[((int) $id - 1 + count(self::COLORS)) % count(self::COLORS)];
    }

    /**
     * How patients find it, for messages: "Nairobi Hospital, Argwings Kodhek Rd. Map: https://…".
     */
    public function getDirections()
    {
        $text = $this->name;
        if ($this->address) {
            $text .= ', ' . preg_replace('/\s*\R\s*/', ', ', $this->address);
        }
        if ($this->map_url) {
            $text .= '. Map: ' . $this->map_url;
        }

        return $text;
    }
}
