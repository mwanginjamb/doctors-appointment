<?php

namespace frontend\tests\unit;

use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\FacilityAgreement;
use frontend\services\BookingService;
use frontend\services\Pilot;
use frontend\tests\BookingDataTrait;
use Yii;

/**
 * F20, pilot groundwork: each booking's channel, who cancelled and why, the consultant's first
 * booking (the start of their pilot) and the facility's agreement.
 */
class PilotGroundworkTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    protected function _before()
    {
        $this->createParticipants();
    }

    protected function _after()
    {
        Yii::$app->user->logout();
    }

    private function bookAs($user, $time, $date = null)
    {
        Yii::$app->user->login($user);
        $appointment = $this->newBooking($time, null, $date);
        $this->assertTrue(Yii::createObject(BookingService::class)->book($appointment), json_encode($appointment->errors));

        return Appointments::findOne($appointment->id);
    }

    public function testAPatientBookingIsOnline()
    {
        $this->assertSame(Appointments::CHANNEL_ONLINE, $this->bookAs($this->client, '10:00')->booking_channel);
    }

    public function testTheConsultantBookingALaterDayIsAStaffBooking()
    {
        $this->assertSame(Appointments::CHANNEL_STAFF, $this->bookAs($this->consultantUser, '10:00')->booking_channel);
    }

    public function testTheConsultantBookingForTodayIsAWalkIn()
    {
        Yii::$app->user->login($this->consultantUser);
        $appointment = $this->newBooking('10:00', null, date('Y-m-d'));
        $appointment->facility_id = $this->consultant->engagements[0]->facility_id;

        $this->assertSame(Appointments::CHANNEL_WALK_IN, Yii::createObject(BookingService::class)->channelOf($appointment));
    }

    public function testABookingLinkIsItsOwnChannel()
    {
        $appointment = $this->newBooking('10:00');
        $appointment->booking_link_id = 99;

        $this->assertSame(Appointments::CHANNEL_LINK, Yii::createObject(BookingService::class)->channelOf($appointment));
    }

    public function testTheFirstBookingStartsThePilotOnceAndNeverMoves()
    {
        $this->assertNull($this->consultant->first_booking_at);

        $first = $this->bookAs($this->client, '10:00');
        $started = (int) Consultant::findOne($this->consultant->id)->first_booking_at;
        $this->assertSame((int) $first->created_at, $started);

        // A later booking, and cancelling the first, leave it alone
        Consultant::updateAll(['first_booking_at' => $started - 86400], ['id' => $this->consultant->id]);
        $this->bookAs($this->otherClient, '11:00');
        Yii::$app->user->login($this->client);
        $this->assertTrue($first->cancel());
        $this->assertSame($started - 86400, (int) Consultant::findOne($this->consultant->id)->first_booking_at);
    }

    public function testAFailedBookingDoesNotStartThePilot()
    {
        $this->bookAs($this->client, '10:00');
        Consultant::updateAll(['first_booking_at' => null], ['id' => $this->consultant->id]);

        Yii::$app->user->login($this->otherClient);
        $clash = $this->newBooking('10:00', $this->otherClient);
        $this->assertFalse(Yii::createObject(BookingService::class)->book($clash));
        $this->assertNull(Consultant::findOne($this->consultant->id)->first_booking_at);
    }

    public function testThePatientCancellingIsAtTheirRequest()
    {
        $appointment = $this->bookAs($this->client, '10:00');
        $this->assertTrue($appointment->cancel('Travelling'));

        $appointment->refresh();
        $this->assertSame(Appointments::PARTY_PATIENT, $appointment->cancelled_party);
        $this->assertSame(1, (int) $appointment->cancelled_at_patient_request);
        $this->assertSame($this->client->id, (int) $appointment->cancelled_by);
        $this->assertNotNull($appointment->cancelled_at);
    }

    public function testAGuestCancellingFromTheManageLinkIsThePatient()
    {
        $appointment = $this->bookAs($this->client, '10:00');
        Yii::$app->user->logout();
        $this->assertTrue($appointment->cancel());

        $appointment->refresh();
        $this->assertSame(Appointments::PARTY_PATIENT, $appointment->cancelled_party);
        $this->assertSame(1, (int) $appointment->cancelled_at_patient_request);
        $this->assertNull($appointment->cancelled_by);
    }

    public function testTheConsultantSaysWhy()
    {
        $decided = $this->bookAs($this->client, '10:00');
        $asked = $this->bookAs($this->otherClient, '11:00');
        $closed = $this->bookAs($this->client, '12:00');

        Yii::$app->user->login($this->consultantUser);
        $this->assertTrue($decided->cancel(null, Appointments::REASON_CLINIC));
        $this->assertTrue($asked->cancel(null, Appointments::REASON_PATIENT_REQUEST));
        $this->assertTrue($closed->cancel(null, Appointments::REASON_FACILITY_CLOSED));

        $decided->refresh();
        $asked->refresh();
        $closed->refresh();
        $this->assertSame([Appointments::PARTY_CONSULTANT, 0], [$decided->cancelled_party, (int) $decided->cancelled_at_patient_request]);
        $this->assertSame([Appointments::PARTY_CONSULTANT, 1], [$asked->cancelled_party, (int) $asked->cancelled_at_patient_request]);
        $this->assertSame([Appointments::PARTY_FACILITY, 0], [$closed->cancelled_party, (int) $closed->cancelled_at_patient_request]);
    }

    public function testOnlyStaffAreAskedWhy()
    {
        $this->assertFalse(Appointments::asksCancelReason(null));
        $this->assertFalse(Appointments::asksCancelReason($this->client));
        $this->assertTrue(Appointments::asksCancelReason($this->consultantUser));
        $this->assertTrue(Appointments::asksCancelReason($this->admin));
    }

    public function testPilotDaysAndPhases()
    {
        $consultant = new Consultant();
        $this->assertNull(Pilot::day($consultant));
        $this->assertSame(Pilot::PHASE_NOT_STARTED, Pilot::phase($consultant));

        $consultant->first_booking_at = strtotime('2026-01-01 16:30');
        $at = function ($date) { return strtotime($date . ' 09:00'); };
        $this->assertSame(1, Pilot::day($consultant, $at('2026-01-01')));
        $this->assertSame(Pilot::PHASE_BASELINE, Pilot::phase($consultant, $at('2026-01-30')));
        $this->assertSame(31, Pilot::day($consultant, $at('2026-01-31')));
        $this->assertSame(Pilot::PHASE_FEES, Pilot::phase($consultant, $at('2026-01-31')));
        $this->assertSame(Pilot::PHASE_FULL, Pilot::phase($consultant, $at('2026-03-02')));   // day 61
        $this->assertSame(Pilot::PHASE_FULL, Pilot::phase($consultant, $at('2026-03-31')));   // day 90
        $this->assertSame(Pilot::PHASE_STANDARD, Pilot::phase($consultant, $at('2026-04-01'))); // day 91
    }

    public function testAFacilityAgreementIsRecordedAndTheLatestIsCurrent()
    {
        $facilityId = $this->consultant->engagements[0]->facility_id;
        $old = new FacilityAgreement(['facility_id' => $facilityId, 'version' => '1.1.0', 'signed_on' => '2026-10-01',
            'facility_signatory' => 'Jane Admin, Director', 'platform_signatory' => 'Gumzo HealthFirst']);
        $this->assertTrue($old->save(), json_encode($old->errors));
        $new = new FacilityAgreement(['facility_id' => $facilityId, 'version' => '1.2', 'signed_on' => '2026-11-20',
            'facility_signatory' => 'Jane Admin, Director', 'platform_signatory' => 'Gumzo HealthFirst']);
        $this->assertTrue($new->save(), json_encode($new->errors));

        $current = FacilityAgreement::currentFor($facilityId);
        $this->assertSame($new->id, $current->id);
        $this->assertTrue($current->hasCancellationCharge());
        $this->assertFalse($old->hasCancellationCharge());
        $this->assertSame(1, (int) $current->walk_in_exempt);

        $bad = new FacilityAgreement(['facility_id' => $facilityId, 'version' => '9', 'signed_on' => '2026-11-20',
            'facility_signatory' => 'x', 'platform_signatory' => 'y']);
        $this->assertFalse($bad->save());
        $this->assertArrayHasKey('version', $bad->errors);
    }
}
