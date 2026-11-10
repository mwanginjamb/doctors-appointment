<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\helpers\Json;

class OutcomesCest
{
    use BookingDataTrait;

    /** @var Appointments yesterday 10:00 with Dr. Test */
    private $past;
    /** @var User front desk assigned to Dr. Test */
    private $frontDesk;

    public function _before(FunctionalTester $I)
    {
        FrontDesk::reset();
        $this->createParticipants();
        $this->past = $this->bookInThePast('10:00', $this->client, 1);

        $this->frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id]);
    }

    /**
     * Books on the fixture date, then moves it $daysAgo into the past (the booking check refuses past times).
     */
    private function bookInThePast($time, $patient, $daysAgo, $consultantUserId = null)
    {
        $appointment = $this->newBooking($time, $patient);
        if ($consultantUserId) {
            $appointment->consultant_id = $consultantUserId;
        }
        $appointment->saveBooking();
        Appointments::updateAll(['date' => date('Y-m-d', strtotime("-{$daysAgo} days"))], ['id' => $appointment->id]);

        return Appointments::findOne($appointment->id);
    }

    private function record(FunctionalTester $I, Appointments $appointment, $outcome)
    {
        $I->amOnRoute('appointments/view', ['id' => $appointment->id]);
        $I->postAsLink(['/appointments/outcome', 'id' => $appointment->id], ['outcome' => $outcome]);
    }

    public function consultantRecordsAttended(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/view', ['id' => $this->past->id]);
        $I->see('Did the patient come?');

        $this->record($I, $this->past, Appointments::STATUS_COMPLETED);

        $I->see('Recorded as attended.');
        $saved = Appointments::findOne($this->past->id);
        $I->assertSame(Appointments::STATUS_COMPLETED, $saved->status);
        $I->assertSame($this->consultantUser->id, (int) $saved->outcome_by);
        $I->assertNotNull($saved->outcome_at);
    }

    public function frontDeskRecordsNoShowsOnlyForTheirConsultants(FunctionalTester $I)
    {
        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        (new Consultant(['user_id' => $otherDoctor->id, 'names' => 'Dr. Other']))->save(false);
        $notTheirs = $this->bookInThePast('11:00', $this->otherClient, 1, $otherDoctor->id);

        $I->amLoggedInAs($this->frontDesk);
        $this->record($I, $this->past, Appointments::STATUS_NO_SHOW);
        $I->see('Recorded as a no-show.');
        $I->assertSame(Appointments::STATUS_NO_SHOW, Appointments::findOne($this->past->id)->status);

        $I->amOnRoute('appointments/view', ['id' => $notTheirs->id]);
        $I->seeResponseCodeIs(404);
    }

    public function patientsCannotRecordOutcomes(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/view', ['id' => $this->past->id]);
        $I->dontSee('Did the patient come?');
        $I->postAsLink(['/appointments/outcome', 'id' => $this->past->id], ['outcome' => Appointments::STATUS_COMPLETED]);

        $I->seeResponseCodeIs(403);
        $I->assertSame(Appointments::STATUS_SCHEDULED, Appointments::findOne($this->past->id)->status);
    }

    public function upcomingAppointmentsCannotHaveAnOutcome(FunctionalTester $I)
    {
        $upcoming = $this->book('14:00');
        $I->amLoggedInAs($this->consultantUser);
        $this->record($I, $upcoming, Appointments::STATUS_NO_SHOW);

        $I->see('An outcome can be recorded once the appointment has started');
        $I->assertSame(Appointments::STATUS_SCHEDULED, Appointments::findOne($upcoming->id)->status);
    }

    public function aWrongOutcomeCanBeCorrectedForSevenDays(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $this->record($I, $this->past, Appointments::STATUS_COMPLETED);
        $this->record($I, $this->past, Appointments::STATUS_NO_SHOW);
        $I->assertSame(Appointments::STATUS_NO_SHOW, Appointments::findOne($this->past->id)->status);

        // Recorded 8 days ago: too late to change
        Appointments::updateAll(['outcome_at' => time() - 8 * 86400], ['id' => $this->past->id]);
        $this->record($I, $this->past, Appointments::STATUS_COMPLETED);
        $I->assertSame(Appointments::STATUS_NO_SHOW, Appointments::findOne($this->past->id)->status);
    }

    public function boardListsAppointmentsThatNeedAnOutcome(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('board/index');
        $I->see('Needs an outcome (1)');
        $I->see('Client', '.needs-outcome');

        $this->record($I, $this->past, Appointments::STATUS_COMPLETED);
        $I->amOnRoute('board/index');
        $I->dontSeeElement('.needs-outcome');
    }

    public function staffSeeAPatientsNoShowHistory(FunctionalTester $I)
    {
        $second = $this->bookInThePast('11:00', $this->client, 3);
        Appointments::updateAll(['status' => Appointments::STATUS_NO_SHOW], ['id' => [$this->past->id, $second->id]]);
        $upcoming = $this->book('14:00');

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/view', ['id' => $upcoming->id]);
        $I->see('This patient has 2 no-shows in the last 12 months.');

        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/view', ['id' => $upcoming->id]);
        $I->dontSee('no-shows in the last 12 months');

        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('api/find-patient', ['q' => 'client@example.com']);
        $found = Json::decode($I->grabPageSource());
        $I->assertSame(2, $found['patients'][0]['noShows']);
    }

    public function dashboardShowsTheNoShowRate(FunctionalTester $I)
    {
        $attended = $this->bookInThePast('11:00', $this->otherClient, 2);
        Appointments::updateAll(['status' => Appointments::STATUS_NO_SHOW], ['id' => $this->past->id]);
        Appointments::updateAll(['status' => Appointments::STATUS_COMPLETED], ['id' => $attended->id]);

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('site/settings');
        $I->see('No-shows (last 30 days)', '.stat-tile');
        $I->see('50%', '.stat-tile');
        $I->see('1 of 2 with an outcome', '.stat-tile');
    }
}
