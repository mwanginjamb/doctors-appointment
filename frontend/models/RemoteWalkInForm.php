<?php

namespace frontend\models;

use common\jobs\SendWhatsappTextJob;
use common\models\Consent;
use common\models\User;
use frontend\services\BookingService;
use Yii;
use yii\base\Model;

/**
 * A potential patient booking through a booking link, treated like a front-desk walk-in: they give
 * their details, and the appointment is made for the patient who owns the contact the link was sent
 * to, or for a new basic account. The contact the link was sent to is fixed; the patient cannot
 * change it, so a forwarded link still books for the person staff addressed.
 */
class RemoteWalkInForm extends Model
{
    public $date;
    public $time;
    public $full_name;
    public $phone;
    public $email;
    public $reason;

    /** @var string|null F11: agrees to the privacy notice */
    public $consent_data;

    /** @var string|null F11: WhatsApp/SMS reminders, '1' or '0' */
    public $consent_reminders;

    /** @var BookingLink */
    private $link;

    /** @var Appointments|null after book() */
    public $appointment;

    public function __construct(BookingLink $link, $config = [])
    {
        $this->link = $link;
        $this->full_name = $link->recipient_name;
        $this->phone = $link->recipient_phone;
        $this->email = $link->recipient_email;
        parent::__construct($config);
    }

    public function rules()
    {
        $phoneMessage = Yii::t('app', 'Enter a phone number such as 0712 345 678 or +254 712 345 678.');

        return [
            [['full_name', 'phone', 'email', 'reason'], 'trim'],
            [['date', 'time', 'full_name', 'phone'], 'required'],
            [['date'], 'date', 'format' => 'php:Y-m-d'],
            [['time'], 'match', 'pattern' => '/^([01]\d|2[0-3]):[0-5]\d(:00)?$/'],
            [['full_name'], 'string', 'min' => 2, 'max' => 150],
            [['phone'], 'match', 'pattern' => '/^\+?[0-9][0-9 ]{8,16}$/', 'message' => $phoneMessage],
            [['email'], 'default', 'value' => null],
            [['email'], 'email'],
            [['email'], 'string', 'max' => 255],
            [['reason'], 'string', 'max' => 500],
            // F11: the person booking agrees to the privacy notice and answers the reminders question
            [['consent_data'], 'required', 'requiredValue' => '1', 'when' => function () { return Consent::isEnabled(); }, 'message' => Yii::t('app', 'Please agree to the privacy notice to book.')],
            [['consent_reminders'], 'required', 'when' => function () { return Consent::isEnabled(); }, 'message' => Yii::t('app', 'Choose whether you want WhatsApp/SMS reminders.')],
            [['consent_reminders'], 'in', 'range' => ['0', '1']],
        ];
    }

    public function attributeLabels()
    {
        return [
            'full_name' => Yii::t('app', 'Full name'),
            'phone' => Yii::t('app', 'Phone'),
            'email' => Yii::t('app', 'Email (optional, for the confirmation)'),
            'reason' => Yii::t('app', 'Reason for the visit (optional)'),
            'consent_data' => (new ConsentForm())->getAttributeLabel('data'),
            'consent_reminders' => (new ConsentForm())->getAttributeLabel('reminders'),
        ];
    }

    /** Whether the phone / email field is fixed to what the link was sent to. */
    public function isLocked($attribute)
    {
        return ($attribute === 'phone' && $this->link->recipient_phone) || ($attribute === 'email' && $this->link->recipient_email);
    }

    public function load($data, $formName = null)
    {
        $loaded = parent::load($data, $formName);
        // Whatever was posted, the contact the link was sent to stays
        if ($this->link->recipient_phone) {
            $this->phone = $this->link->recipient_phone;
        }
        if ($this->link->recipient_email) {
            $this->email = $this->link->recipient_email;
        }

        return $loaded;
    }

    /**
     * Books the slot. The link is locked for the duration so it can only ever book once.
     */
    public function book()
    {
        if (!$this->validate()) {
            return false;
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            // Lock the link row: two tabs submitting at once cannot both use it
            $db->createCommand('SELECT [[id]] FROM {{%booking_link}} WHERE [[id]] = :id FOR UPDATE', [':id' => $this->link->id])->queryScalar();
            $this->link->refresh();
            if (!$this->link->isUsable()) {
                $this->addError('time', Yii::t('app', 'This booking link has expired or has already been used. Please contact the clinic for a new one.'));
                $transaction->rollBack();
                return false;
            }
            if (!$this->link->offers($this->date, $this->time)) {
                $this->addError('time', Yii::t('app', 'That time is no longer free. Please choose another.'));
                $transaction->rollBack();
                return false;
            }

            $patient = $this->findOrRegisterPatient();
            if ($patient === null) {
                $transaction->rollBack();
                return false;
            }
            if (Consent::isEnabled()) {
                (new ConsentForm(['data' => '1', 'reminders' => (string) $this->consent_reminders]))
                    ->save($patient->id, Consent::METHOD_BOOKING_LINK);
            }

            $appointment = new Appointments();
            $appointment->date = $this->date;
            $appointment->time = substr($this->time, 0, 5) . ':00';
            $appointment->symptoms_brief = $this->reason;
            $appointment->patient_id = $patient->id;
            $appointment->consultant_id = $this->link->consultant->user_id;
            $appointment->booking_link_id = $this->link->id;
            $appointment->skipNotice = true;
            if (!Yii::createObject(BookingService::class)->book($appointment)) {
                $errors = $appointment->getFirstErrors();
                $this->addError('time', $errors ? reset($errors) : Yii::t('app', 'The appointment could not be booked.'));
                $transaction->rollBack();
                return false;
            }
            // Booked on the clinic's behalf, by whoever sent the link
            Appointments::updateAll(['created_by' => $this->link->created_by], ['id' => $appointment->id]);
            $this->link->updateAttributes(['used_at' => time(), 'appointment_id' => $appointment->id]);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->appointment = $appointment;
        $this->confirmByWhatsapp($patient);

        return true;
    }

    /**
     * The patient who owns the contact the link was sent to (its phone, else its email). With
     * several (e.g. a family sharing a phone), the one whose name matches; otherwise a new walk-in
     * account. Other account holders are never shown to the person booking.
     */
    private function findOrRegisterPatient()
    {
        $query = User::find()
            ->where(['status' => User::STATUS_ACTIVE])
            ->andWhere(['or', ['role' => 'client'], ['role' => null], ['role' => '']]);
        if ($this->link->recipient_phone) {
            $query->andWhere(['like', 'phone_number', '%' . substr(preg_replace('/\D/', '', $this->link->recipient_phone), -9), false]);
        } else {
            $query->andWhere(['email' => $this->link->recipient_email]);
        }
        $matches = $query->limit(20)->all();

        if (count($matches) === 1) {
            return $matches[0];
        }
        foreach ($matches as $user) {
            if (mb_strtolower(trim((string) $user->full_name)) === mb_strtolower($this->full_name)) {
                return $user;
            }
        }

        $register = new RegisterPatientForm([
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'confirm_new' => true,
            'consent' => $this->consent_data,
            'reminders' => $this->consent_reminders,
        ]);
        $register->consentMethod = Consent::METHOD_BOOKING_LINK;
        $user = $register->register();
        if ($user === null) {
            foreach ($register->getFirstErrors() as $attribute => $message) {
                // The "look the patient up instead" wording is meant for staff
                $this->addError($attribute === 'email' ? 'email' : 'full_name', $attribute === 'email'
                    ? Yii::t('app', 'This email cannot be used. Leave it empty or use another one.')
                    : $message);
            }
        }

        return $user;
    }

    /** WhatsApp confirmation; the email one is sent by the booking itself when there is an email. */
    private function confirmByWhatsapp(User $patient)
    {
        $phone = $this->link->recipient_phone ?: $this->phone;
        // F11: only with consent to WhatsApp/SMS
        if (!$phone || !Consent::remindersAllowed($patient->id)) {
            return;
        }
        $doctor = $this->link->consultant->names;
        $when = date('l j M Y \a\t H:i', strtotime($this->appointment->date . ' ' . $this->appointment->time));
        Yii::$app->queue->push(new SendWhatsappTextJob([
            'phone' => $phone,
            'text' => "Hello {$this->full_name}, your appointment with {$doctor} at " . $this->appointment->getPlaceText() . " is booked for {$when}. "
                . 'Please arrive 15 minutes early. To change or cancel it, contact the clinic.',
        ]));
    }
}
