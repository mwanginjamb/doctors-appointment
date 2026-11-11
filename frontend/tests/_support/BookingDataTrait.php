<?php

namespace frontend\tests;

use common\models\Consent;
use common\models\User;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\ConsultantFacility;
use frontend\models\PublicHoliday;
use frontend\models\UserProfile;

/**
 * Builds the users, profiles and appointments the booking tests need.
 * Records are saved without validation; the test transaction rolls them back.
 */
trait BookingDataTrait
{
    /** @var User */
    protected $client;
    /** @var User */
    protected $otherClient;
    /** @var User */
    protected $consultantUser;
    /** @var Consultant */
    protected $consultant;
    /** @var User */
    protected $admin;
    /** @var string a Monday 1-7 days ahead: open in the default week, inside the booking window */
    protected $date;

    protected function createParticipants()
    {
        $this->date = date('Y-m-d', strtotime('next monday'));
        // Seeded holidays would close some Mondays; tests that need a holiday add their own
        PublicHoliday::deleteAll();

        $this->client = $this->createUser('client', 'client');
        $this->otherClient = $this->createUser('other.client', 'client');
        $this->consultantUser = $this->createUser('doctor', 'consultant');
        $this->admin = $this->createUser('site.admin', 'admin');

        $this->consultant = new Consultant([
            'user_id' => $this->consultantUser->id,
            'names' => 'Dr. Test',
            'consultant_email' => 'doctor@example.com',
            'consultant_phone_number' => '0712000000',
            'appointment_session_duration' => 30,
        ]);
        $this->consultant->save(false);

        foreach ([$this->client, $this->otherClient] as $user) {
            (new UserProfile(['user_id' => $user->id, 'dob' => '1990-01-01', 'phone' => '0712111111']))->save(false);
        }
    }

    /**
     * Sets the length of new bookings with the test consultant: at their facility (F8, where the
     * length now lives) and as their default for new facilities.
     */
    protected function setSessionMinutes($minutes, $consultant = null)
    {
        $consultant = $consultant ?? $this->consultant;
        $consultant->updateAttributes(['appointment_session_duration' => $minutes]);
        ConsultantFacility::updateAll(['session_minutes' => $minutes], ['consultant_id' => $consultant->id]);
        unset($consultant->engagements);
    }

    protected function createUser($username, $role)
    {
        $user = new User();
        $user->username = $username;
        $user->email = $username . '@example.com';
        $user->full_name = ucwords(str_replace('.', ' ', $username));
        $user->phone_number = '0712345678';
        $user->role = $role;
        $user->status = User::STATUS_ACTIVE;
        $user->setPassword('password_0');
        $user->generateAuthKey();
        $user->save(false);

        // Patients have agreed to the current privacy notice and to WhatsApp/SMS (F11), so they are
        // not held on the consent page; consent tests set up their own
        if ($role === 'client') {
            Consent::record($user->id, Consent::PURPOSE_DATA, true, Consent::METHOD_ONLINE);
            Consent::record($user->id, Consent::PURPOSE_REMINDERS, true, Consent::METHOD_ONLINE);
        }

        return $user;
    }

    /**
     * An unsaved booking by $patient (default: the client) with the test consultant.
     */
    protected function newBooking($time, $patient = null, $date = null)
    {
        $appointment = new Appointments();
        $appointment->date = $date ?? $this->date;
        $appointment->time = $time;
        $appointment->patient_id = ($patient ?? $this->client)->id;
        $appointment->consultant_id = $this->consultantUser->id;
        $appointment->symptoms_brief = 'Headache';

        return $appointment;
    }

    protected function book($time, $patient = null)
    {
        $appointment = $this->newBooking($time, $patient);
        if (!$appointment->saveBooking()) {
            throw new \RuntimeException('Fixture booking failed: ' . json_encode($appointment->errors));
        }

        return $appointment;
    }
}
