<?php

namespace frontend\tests;

use common\models\User;
use frontend\models\Appointments;
use frontend\models\Consultant;
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
