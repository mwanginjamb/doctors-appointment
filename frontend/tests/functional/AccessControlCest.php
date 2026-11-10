<?php

namespace frontend\tests\functional;

use frontend\models\AppointmentNotifications;
use frontend\models\Appointments;
use frontend\models\Gender;
use frontend\models\UserProfile;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\helpers\Url;

class AccessControlCest
{
    use BookingDataTrait;

    /** @var Appointments */
    private $appointment;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
        $this->appointment = $this->book('10:00');
    }

    public function guestIsSentToLoginForPatientProfiles(FunctionalTester $I)
    {
        $I->amOnRoute('user-profile/index');
        $I->seeInCurrentUrl('site/login');
    }

    public function guestCannotBookThroughApi(FunctionalTester $I)
    {
        $before = Appointments::find()->count();

        $I->sendAjaxPostRequest(Url::to(['/api/visit']), ['date' => $this->date, 'time' => '11:00:00']);

        $I->dontSeeResponseCodeIs(200);
        $I->assertEquals($before, Appointments::find()->count());
    }

    public function patientCanViewOwnAppointment(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);
        $I->seeResponseCodeIs(200);
        $I->seeInSource('value="Headache"');
    }

    public function patientCannotViewSomeoneElsesAppointment(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->otherClient);
        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);
        $I->seeResponseCodeIs(404);
    }

    public function patientCannotConfirmAppointment(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->sendCsrfPost(['/appointments/confirm', 'id' => $this->appointment->id]);
        $I->seeResponseCodeIs(403);
        $I->assertSame(Appointments::STATUS_SCHEDULED, Appointments::findOne($this->appointment->id)->status);
    }

    public function patientCancelsOwnAppointmentAndTheRecordIsKept(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->sendCsrfPost(['/appointments/cancel', 'id' => $this->appointment->id]);

        $cancelled = Appointments::findOne($this->appointment->id);
        $I->assertNotNull($cancelled, 'cancelling must not delete the appointment');
        $I->assertSame(Appointments::STATUS_CANCELLED, $cancelled->status);
        $I->assertSame($this->client->id, (int) $cancelled->updated_by);
        $I->assertFalse(AppointmentNotifications::find()
            ->where(['appointment_id' => $this->appointment->id, 'status' => AppointmentNotifications::STATUS_PENDING])
            ->exists(), 'pending reminders must be cancelled');
    }

    public function cancellationNoteIsKeptAndShown(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);
        $I->submitForm('#cancel-appointment-form', ['note' => '  Doctor is away at a <conference>  ']);

        $cancelled = Appointments::findOne($this->appointment->id);
        $I->assertSame(Appointments::STATUS_CANCELLED, $cancelled->status);
        $I->assertSame('Doctor is away at a <conference>', $cancelled->cancellation_note);

        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);
        $I->see('Cancelled', '.alert');
        $I->seeInSource('Doctor is away at a &lt;conference&gt;');
        $I->dontSeeElement('#cancel-appointment-form');
    }

    public function overlongCancellationNoteIsRejected(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/view', ['id' => $this->appointment->id]);
        $I->postAsLink(['/appointments/cancel', 'id' => $this->appointment->id], ['note' => str_repeat('x', 501)]);

        $unchanged = Appointments::findOne($this->appointment->id);
        $I->assertSame(Appointments::STATUS_SCHEDULED, $unchanged->status);
        $I->assertNull($unchanged->cancellation_note);
    }

    public function consultantCanCancelTheirPatientsAppointment(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->sendCsrfPost(['/appointments/cancel', 'id' => $this->appointment->id]);

        $I->assertSame(Appointments::STATUS_CANCELLED, Appointments::findOne($this->appointment->id)->status);
        $I->assertSame($this->consultantUser->id, (int) Appointments::findOne($this->appointment->id)->updated_by);
    }

    public function patientCannotCancelSomeoneElsesAppointment(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->otherClient);
        $I->sendCsrfPost(['/appointments/cancel', 'id' => $this->appointment->id]);

        $I->seeResponseCodeIs(404);
        $I->assertSame(Appointments::STATUS_SCHEDULED, Appointments::findOne($this->appointment->id)->status);
    }

    public function appointmentsCannotBeDeletedFromTheApp(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->sendCsrfPost(['/appointments/delete', 'id' => $this->appointment->id]);

        $I->seeResponseCodeIs(404);
        $I->assertNotNull(Appointments::findOne($this->appointment->id));
    }

    public function pastAppointmentsCannotBeCancelled(FunctionalTester $I)
    {
        Appointments::updateAll(['date' => date('Y-m-d', strtotime('-1 day'))], ['id' => $this->appointment->id]);
        $I->amLoggedInAs($this->client);
        $I->sendCsrfPost(['/appointments/cancel', 'id' => $this->appointment->id]);

        $I->assertSame(Appointments::STATUS_SCHEDULED, Appointments::findOne($this->appointment->id)->status);
    }

    public function cancelledAppointmentCannotBeRescheduledButItsSlotIsFree(FunctionalTester $I)
    {
        $this->appointment->cancel();
        $I->amLoggedInAs($this->client);

        $moved = $I->sendJsonPost(['/api/update-visit'], [
            'id' => $this->appointment->id,
            'date' => $this->date,
            'time' => '14:00:00',
        ]);
        $I->assertSame('error', $moved['status']);
        $I->assertSame('10:00:00', Appointments::findOne($this->appointment->id)->time);

        $I->amLoggedInAs($this->otherClient);
        $rebooked = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date,
            'time' => '10:00:00',
            'brief' => 'Fever',
            'consultant' => $this->consultantUser->id,
        ]);
        $I->assertSame('success', $rebooked['status'], json_encode($rebooked));
    }

    public function consultantCanUpdateAppointmentDetails(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/update', ['id' => $this->appointment->id]);
        $I->seeResponseCodeIs(200);

        $I->submitForm('.appointments-form form', ['Appointments[symptoms_brief]' => 'Migraine, worse at night']);

        $updated = Appointments::findOne($this->appointment->id);
        $I->assertSame('Migraine, worse at night', $updated->symptoms_brief);
        $I->assertSame('10:00:00', $updated->time, 'date and time are read-only on this form');
    }

    public function patientCannotViewSomeoneElsesProfile(FunctionalTester $I)
    {
        $profile = UserProfile::findOne(['user_id' => $this->client->id]);

        $I->amLoggedInAs($this->otherClient);
        $I->amOnRoute('user-profile/view', ['id' => $profile->id]);
        $I->seeResponseCodeIs(404);
    }

    public function consultantCanViewTheirPatientsProfile(FunctionalTester $I)
    {
        $profile = UserProfile::findOne(['user_id' => $this->client->id]);

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('user-profile/view', ['id' => $profile->id]);
        $I->seeResponseCodeIs(200);
    }

    public function consultantCannotSeeOtherPatientsProfile(FunctionalTester $I)
    {
        $profile = UserProfile::findOne(['user_id' => $this->otherClient->id]);

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('user-profile/view', ['id' => $profile->id]);
        $I->seeResponseCodeIs(404);
    }

    public function consultantCannotEditAnotherConsultantsProfile(FunctionalTester $I)
    {
        $otherDoctor = $this->createUser('other.doctor', 'consultant');

        $I->amLoggedInAs($otherDoctor);
        $I->amOnRoute('consultant/update', ['id' => $this->consultant->id]);
        $I->seeResponseCodeIs(404);
    }

    public function nonAdminCannotManageLookups(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('gender/index');
        $I->seeResponseCodeIs(403);
    }

    public function adminCanManageLookups(FunctionalTester $I)
    {
        $I->haveRecord(Gender::class, ['name' => 'Female']);

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('gender/index');
        $I->seeResponseCodeIs(200);
        $I->see('Female');
    }

    public function consultantDetailsAreHtmlEncoded(FunctionalTester $I)
    {
        $this->consultant->names = '<script>alert(1)</script>';
        $this->consultant->save(false);

        $I->amLoggedInAs($this->client);
        $I->amOnRoute('consultant/view', ['id' => $this->consultant->id, 'consultant' => 1]);
        $I->seeResponseCodeIs(200);
        $I->dontSeeInSource('<script>alert(1)</script>');
        $I->seeInSource('&lt;script&gt;alert(1)&lt;/script&gt;');
    }
}
