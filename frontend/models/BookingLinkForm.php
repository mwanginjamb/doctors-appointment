<?php

namespace frontend\models;

use common\jobs\SendEmailMessageJob;
use common\jobs\SendWhatsappTextJob;
use Yii;
use yii\base\Model;

/**
 * Staff form to send a booking link to a potential patient: who it is for (a phone for WhatsApp
 * and/or an email), and which slots it offers (a date range of at most bookingLink.maxDays days,
 * optionally only certain hours each day).
 */
class BookingLinkForm extends Model
{
    public $recipient_name;
    public $recipient_phone;
    public $recipient_email;
    public $from_date;
    public $to_date;
    public $day_start;
    public $day_end;

    /** @var Consultant the consultant the link books with; set by the controller */
    public $consultant;

    /** @var BookingLink|null and its URL, after send() */
    public $link;
    public $url;

    public function init()
    {
        parent::init();
        $this->from_date = $this->from_date ?: date('Y-m-d');
        $this->to_date = $this->to_date ?: date('Y-m-d', strtotime('+' . (self::maxDays() - 1) . ' days'));
    }

    public static function maxDays()
    {
        return max(1, (int) (Yii::$app->params['bookingLink.maxDays'] ?? 5));
    }

    public function rules()
    {
        $phoneMessage = Yii::t('app', 'Enter a phone number such as 0712 345 678 or +254 712 345 678.');

        return [
            [['recipient_name', 'recipient_phone', 'recipient_email', 'day_start', 'day_end'], 'trim'],
            [['recipient_name', 'from_date', 'to_date'], 'required'],
            [['recipient_name'], 'string', 'min' => 2, 'max' => 150],
            [['recipient_phone', 'recipient_email', 'day_start', 'day_end'], 'default', 'value' => null],
            [['recipient_phone'], 'match', 'pattern' => '/^\+?[0-9][0-9 ]{8,16}$/', 'message' => $phoneMessage],
            [['recipient_phone'], function ($attribute) use ($phoneMessage) {
                $digits = strlen(preg_replace('/\D/', '', $this->$attribute));
                if ($digits < 9 || strlen($this->getNormalizedPhone()) > 15) {
                    $this->addError($attribute, $phoneMessage);
                }
            }, 'skipOnError' => true],
            [['recipient_email'], 'email'],
            [['recipient_email'], 'string', 'max' => 255],
            // The link has to reach the patient: WhatsApp needs a phone, email an address
            [['recipient_phone'], 'required', 'when' => function () {
                return !$this->recipient_email;
            }, 'message' => Yii::t('app', 'Enter a phone number for WhatsApp, an email address, or both.')],
            [['from_date', 'to_date'], 'date', 'format' => 'php:Y-m-d'],
            [['to_date'], 'validateRange', 'skipOnError' => true],
            [['day_start', 'day_end'], 'match', 'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/', 'message' => Yii::t('app', 'Enter the time as HH:MM.')],
            [['day_end'], 'validateHours', 'skipOnError' => true],
        ];
    }

    public function attributeLabels()
    {
        return [
            'recipient_name' => Yii::t('app', "Patient's name"),
            'recipient_phone' => Yii::t('app', 'WhatsApp number'),
            'recipient_email' => Yii::t('app', 'Email'),
            'from_date' => Yii::t('app', 'Offer slots from'),
            'to_date' => Yii::t('app', 'to'),
            'day_start' => Yii::t('app', 'Only from (optional)'),
            'day_end' => Yii::t('app', 'until'),
        ];
    }

    public function validateRange($attribute)
    {
        $today = date('Y-m-d');
        $lastBookable = date('Y-m-d', strtotime('+' . (int) (Yii::$app->params['booking.maxDaysAhead'] ?? 90) . ' days'));
        $days = (strtotime($this->to_date) - strtotime($this->from_date)) / 86400 + 1;

        if ($this->from_date < $today) {
            $this->addError('from_date', Yii::t('app', 'The range cannot start in the past.'));
        } elseif ($days < 1) {
            $this->addError($attribute, Yii::t('app', 'The range must end on or after the day it starts.'));
        } elseif ($days > self::maxDays()) {
            $this->addError($attribute, Yii::t('app', 'Offer at most {n} days.', ['n' => self::maxDays()]));
        } elseif ($this->to_date > $lastBookable) {
            $this->addError($attribute, Yii::t('app', 'Appointments can be booked at most {n} days ahead.', ['n' => (int) (Yii::$app->params['booking.maxDaysAhead'] ?? 90)]));
        }
    }

    public function validateHours($attribute)
    {
        if (($this->day_start === null) !== ($this->day_end === null)) {
            $this->addError($attribute, Yii::t('app', 'Enter both times, or neither.'));
        } elseif ($this->day_start !== null && $this->day_start >= $this->day_end) {
            $this->addError($attribute, Yii::t('app', 'The hours must end after they start.'));
        }
    }

    public function getNormalizedPhone()
    {
        if ($this->recipient_phone === null) {
            return null;
        }
        $phone = (string) $this->recipient_phone;

        return (strpos($phone, '+') === 0 ? '+' : '') . preg_replace('/\D/', '', $phone);
    }

    /**
     * Creates the link and queues it by WhatsApp and/or email.
     *
     * @return bool false when invalid, or when the range has no free slot to offer
     */
    public function send()
    {
        if (!$this->validate()) {
            return false;
        }

        $attributes = [
            'consultant_id' => $this->consultant->id,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->getNormalizedPhone(),
            'recipient_email' => $this->recipient_email,
            'from_date' => $this->from_date,
            'to_date' => $this->to_date,
            'day_start' => $this->day_start ? $this->day_start . ':00' : null,
            'day_end' => $this->day_end ? $this->day_end . ':00' : null,
        ];

        // Do not send a link that offers nothing
        $preview = new BookingLink($attributes);
        $preview->populateRelation('consultant', $this->consultant);
        if (!array_filter($preview->openSlots())) {
            $this->addError('to_date', Yii::t('app', '{doctor} has no free slot in that range. Choose other days or hours.', ['doctor' => $this->consultant->names]));
            return false;
        }

        [$this->link, $token] = BookingLink::issue($attributes);
        $this->url = BookingLink::urlFor($token);
        $this->dispatch();

        return true;
    }

    private function dispatch()
    {
        $user = Yii::$app->user->identity;
        $sender = $user->full_name ?: $user->username;
        $expires = date('H:i', $this->link->expires_at);
        $range = $this->from_date === $this->to_date
            ? date('D j M', strtotime($this->from_date))
            : date('D j M', strtotime($this->from_date)) . ' – ' . date('D j M', strtotime($this->to_date));
        $text = "Hello {$this->recipient_name},\n\n"
            . "{$sender} from " . Yii::$app->name . " has invited you to book an appointment with {$this->consultant->names} ({$range}).\n\n"
            . 'Choose a time using the link below. It works for ' . BookingLink::ttlMinutes() . " minutes, until {$expires}.";

        if ($this->link->recipient_phone) {
            Yii::$app->queue->push(new SendWhatsappTextJob([
                'phone' => $this->link->recipient_phone,
                'text' => $text . "\n\n" . $this->url,
            ]));
        }
        if ($this->link->recipient_email) {
            Yii::$app->queue->push(new SendEmailMessageJob([
                'to' => $this->link->recipient_email,
                'toName' => $this->recipient_name,
                'subject' => 'Book your appointment with ' . $this->consultant->names,
                'text' => $text,
                'linkUrl' => $this->url,
                'linkLabel' => 'Choose a time',
            ]));
        }
    }
}
