<?php

namespace frontend\tests\functional;

use frontend\models\Appointments;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

class BookingApiCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        $this->createParticipants();
    }

    public function patientBooksForThemselvesWhateverTheBodySays(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);

        $response = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date,
            'time' => '11:00:00',
            'brief' => 'Cough',
            'patient' => $this->otherClient->id,
            'consultant' => $this->consultantUser->id,
        ]);

        $I->assertSame('success', $response['status'], json_encode($response));
        $appointment = Appointments::find()->where(['date' => $this->date, 'time' => '11:00:00'])->one();
        $I->assertSame($this->client->id, (int) $appointment->patient_id);
        $I->assertSame($this->consultantUser->id, (int) $appointment->consultant_id);
    }

    public function consultantBooksForAPatientInTheirOwnCalendar(FunctionalTester $I)
    {
        $otherConsultant = $this->createUser('other.doctor', 'consultant');
        $I->amLoggedInAs($this->consultantUser);

        $response = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date,
            'time' => '11:00:00',
            'brief' => 'Walk-in',
            'patient' => $this->client->id,
            'consultant' => $otherConsultant->id,
        ]);

        $I->assertSame('success', $response['status'], json_encode($response));
        $appointment = Appointments::find()->where(['date' => $this->date, 'time' => '11:00:00'])->one();
        $I->assertSame($this->client->id, (int) $appointment->patient_id);
        $I->assertSame($this->consultantUser->id, (int) $appointment->consultant_id);
    }

    public function consultantMustChooseAPatient(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);

        $response = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date,
            'time' => '11:00:00',
            'brief' => 'Walk-in',
        ]);

        $I->assertSame('error', $response['status']);
        $I->assertStringContainsString('Patient', $response['message']);
        $I->assertFalse(Appointments::find()->where(['date' => $this->date])->exists());
    }

    public function consultantCannotBookStaffOrThemselvesAsThePatient(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);

        foreach ([$this->admin, $this->consultantUser] as $notAPatient) {
            $response = $I->sendJsonPost(['/api/visit'], [
                'date' => $this->date,
                'time' => '11:00:00',
                'brief' => 'Walk-in',
                'patient' => $notAPatient->id,
            ]);

            $I->assertSame('error', $response['status'], $notAPatient->username);
        }
        $I->assertFalse(Appointments::find()->where(['date' => $this->date])->exists());
    }

    public function consultantFindsPatientsByEmailOrPhoneOnly(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);

        $byEmail = $this->findPatient($I, 'client@example.com');
        $I->assertSame('success', $byEmail['status'], json_encode($byEmail));
        $I->assertSame([$this->client->id], array_column($byEmail['patients'], 'id'));
        $I->assertStringNotContainsString('client@', $byEmail['patients'][0]['email']);

        // Every fixture user shares this number; only the patient accounts may come back
        $byPhone = $this->findPatient($I, '+254 712 345 678');
        $ids = array_column($byPhone['patients'], 'id');
        sort($ids);
        $I->assertSame([$this->client->id, $this->otherClient->id], $ids);

        $byName = $this->findPatient($I, 'Client');
        $I->assertSame('error', $byName['status']);
    }

    public function patientsCannotLookUpOtherPatients(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);

        $I->amOnRoute('api/find-patient', ['q' => 'other.client@example.com']);
        $I->seeResponseCodeIs(403);
    }

    private function findPatient(FunctionalTester $I, $q)
    {
        $I->amOnRoute('api/find-patient', ['q' => $q]);
        $I->seeResponseCodeIs(200);

        return \yii\helpers\Json::decode($I->grabPageSource());
    }

    public function overlappingBookingIsRejected(FunctionalTester $I)
    {
        $this->book('11:00', $this->otherClient);
        $I->amLoggedInAs($this->client);

        $response = $I->sendJsonPost(['/api/visit'], [
            'date' => $this->date,
            'time' => '11:15:00',
            'brief' => 'Cough',
            'consultant' => $this->consultantUser->id,
        ]);

        $I->assertSame('error', $response['status']);
        $I->assertStringContainsString('not available', $response['message']);
    }

    public function patientCannotRescheduleSomeoneElsesAppointment(FunctionalTester $I)
    {
        $appointment = $this->book('11:00', $this->otherClient);
        $I->amLoggedInAs($this->client);

        $response = $I->sendJsonPost(['/api/update-visit'], [
            'id' => $appointment->id,
            'date' => $this->date,
            'time' => '14:00:00',
        ]);

        $I->assertSame('error', $response['status']);
        $I->assertSame('11:00:00', Appointments::findOne($appointment->id)->time);
    }

    public function patientCanRescheduleOwnAppointment(FunctionalTester $I)
    {
        $appointment = $this->book('11:00');
        $I->amLoggedInAs($this->client);

        $response = $I->sendJsonPost(['/api/update-visit'], [
            'id' => $appointment->id,
            'date' => $this->date,
            'time' => '14:00:00',
        ]);

        $I->assertSame('success', $response['status'], json_encode($response));
        $I->assertSame('14:00:00', Appointments::findOne($appointment->id)->time);
    }

    public function otherPatientsSlotsAreShownWithoutDetails(FunctionalTester $I)
    {
        $this->book('11:00', $this->otherClient);
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/calendar', ['cid' => $this->consultant->id]);

        $I->amOnRoute('api/appointments');
        $I->seeResponseCodeIs(200);
        $I->dontSeeInSource('Headache');
        $I->seeInSource('Booked');
    }
}
