<?php

namespace frontend\models;

use common\behaviors\AuditBehavior;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * A consultant's engagement at a facility: the length of new bookings there, the fee, whether
 * patients can book it themselves (online), and the dates it runs. The consultant's weekly hours
 * at the facility are ConsultantWorkingHours rows with its facility_id.
 *
 * @property int $id
 * @property int $consultant_id consultant.id
 * @property int $facility_id
 * @property int $session_minutes
 * @property string|null $fee decimal, in params['currency']
 * @property bool|int $booking_open patients can book it themselves; staff and booking links always can
 * @property string|null $from_date Y-m-d, first day; null when already running
 * @property string|null $to_date Y-m-d, last day; null when open-ended
 * @property int|null $created_at
 * @property int|null $updated_at
 * @property int|null $created_by
 * @property int|null $updated_by
 *
 * @property Facility $facility
 * @property Consultant $consultant
 */
class ConsultantFacility extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%consultant_facility}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            BlameableBehavior::class,
            'audit' => ['class' => AuditBehavior::class, 'entityType' => 'engagement'],
        ];
    }

    public function rules()
    {
        return [
            [['facility_id', 'session_minutes'], 'required'],
            [['facility_id', 'session_minutes'], 'integer'],
            [['session_minutes'], 'in', 'range' => self::sessionLengths(), 'message' => Yii::t('app', 'Choose one of the listed session lengths.')],
            [['facility_id'], 'exist', 'targetClass' => Facility::class, 'targetAttribute' => 'id', 'filter' => ['active' => true],
                'message' => Yii::t('app', 'Choose a facility that is taking bookings.'), 'when' => function () {
                    return $this->isNewRecord || $this->isAttributeChanged('facility_id', false);
                }],
            [['facility_id'], 'unique', 'targetAttribute' => ['consultant_id', 'facility_id'], 'message' => Yii::t('app', 'Already engaged at this facility.')],
            [['fee'], 'filter', 'filter' => function ($value) {
                $value = is_string($value) ? str_replace([',', ' '], '', $value) : $value;
                return $value === '' ? null : $value;
            }],
            [['fee'], 'number', 'min' => 0, 'max' => 9999999],
            [['booking_open'], 'boolean'],
            [['booking_open'], 'default', 'value' => true],
            [['from_date', 'to_date'], 'default', 'value' => null],
            [['from_date', 'to_date'], 'date', 'format' => 'php:Y-m-d'],
            [['to_date'], 'compare', 'compareAttribute' => 'from_date', 'operator' => '>=', 'type' => 'string', 'skipOnError' => true,
                'when' => function () {
                    return $this->from_date !== null;
                }, 'message' => Yii::t('app', 'The last day must be on or after the first.')],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        $scenarios = parent::scenarios();
        // Whose engagement it is comes from the page, never from the form
        $scenarios[self::SCENARIO_DEFAULT][] = '!consultant_id';

        return $scenarios;
    }

    public function attributeLabels()
    {
        return [
            'facility_id' => Yii::t('app', 'Facility'),
            'session_minutes' => Yii::t('app', 'Session length'),
            'fee' => Yii::t('app', 'Fee ({currency})', ['currency' => self::currency()]),
            'booking_open' => Yii::t('app', 'Patients can book online'),
            'from_date' => Yii::t('app', 'From'),
            'to_date' => Yii::t('app', 'Until'),
        ];
    }

    public function getFacility()
    {
        return $this->hasOne(Facility::class, ['id' => 'facility_id']);
    }

    public function getConsultant()
    {
        return $this->hasOne(Consultant::class, ['id' => 'consultant_id']);
    }

    /**
     * Whether the consultant sees patients here on $date (Y-m-d): inside the engagement's dates,
     * the facility is taking bookings, and it is not closed that day.
     */
    public function isRunningOn($date)
    {
        if ($this->from_date !== null && $date < $this->from_date) {
            return false;
        }
        if ($this->to_date !== null && $date > $this->to_date) {
            return false;
        }

        return $this->facility !== null && $this->facility->isActive()
            && !FacilityClosure::isClosed($this->facility_id, $date);
    }

    public function isBookingOpen()
    {
        return (bool) $this->booking_open;
    }

    /** "KES 3,500", or null when no fee is set. */
    public function getFeeLabel()
    {
        return self::formatFee($this->fee);
    }

    public static function formatFee($fee)
    {
        if ($fee === null || $fee === '') {
            return null;
        }
        $fee = (float) $fee;

        return self::currency() . ' ' . number_format($fee, floor($fee) == $fee ? 0 : 2);
    }

    public static function currency()
    {
        return (string) (Yii::$app->params['currency'] ?? 'KES');
    }

    /** "From 1 Dec 2026", "Until 31 Jan 2027", "1 Dec 2026 – 31 Jan 2027", or null when open-ended. */
    public function getPeriodLabel()
    {
        $f = function ($date) {
            return date('j M Y', strtotime($date));
        };
        if ($this->from_date && $this->to_date) {
            return $f($this->from_date) . ' – ' . $f($this->to_date);
        }
        if ($this->from_date) {
            return Yii::t('app', 'From {date}', ['date' => $f($this->from_date)]);
        }
        if ($this->to_date) {
            return Yii::t('app', 'Until {date}', ['date' => $f($this->to_date)]);
        }

        return null;
    }

    /** @return int[] the session lengths consultants can choose, in minutes */
    public static function sessionLengths()
    {
        return array_map('intval', Yii::$app->params['booking.sessionLengths'] ?? [30]);
    }

    /**
     * The engagement a new consultant starts with: at the facility named on their profile (else
     * their practice name, else Facility::FALLBACK_NAME), with their session length.
     */
    public static function createDefault(Consultant $consultant)
    {
        $facility = Facility::findOrCreateByName(
            trim((string) $consultant->facility) ?: trim((string) $consultant->practice_name),
            $consultant->physical_address
        );
        $engagement = new static([
            'consultant_id' => $consultant->id,
            'facility_id' => $facility->id,
            'session_minutes' => $consultant->appointment_session_duration > 0
                ? (int) $consultant->appointment_session_duration
                : Appointments::defaultSessionMinutes(),
            'booking_open' => true,
        ]);
        $engagement->save(false);
        $engagement->populateRelation('facility', $facility);

        return $engagement;
    }
}
