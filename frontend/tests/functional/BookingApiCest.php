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
            'patient_name' => $this->otherClient->id,
            'consultant' => $this->consultantUser->id,
        ]);

        $I->assertSame('success', $response['status'], json_encode($response));
        $appointment = Appointments::find()->where(['date' => $this->date, 'time' => '11:00:00'])->one();
        $I->assertSame($this->client->id, (int) $appointment->patient_id);
        $I->assertSame($this->consultantUser->id, (int) $appointment->consultant_id);
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
