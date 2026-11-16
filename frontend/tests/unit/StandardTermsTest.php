<?php

namespace frontend\tests\unit;

use common\models\User;
use frontend\models\Consultant;
use frontend\models\FacilityAgreement;
use frontend\services\Pilot;
use frontend\services\StandardTerms;
use frontend\tests\BookingDataTrait;
use Yii;
use yii\db\Query;

/**
 * The move to standard terms after the pilot: notices on days 81, 91 and 121, the warning during
 * the grace period, the facility confirming and a platform admin approving, and charging only once
 * approved.
 */
class StandardTermsTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var int */
    private $facilityId;
    /** @var User */
    private $frontDesk;
    /** @var User */
    private $facilityAdmin;

    protected function _before()
    {
        $this->createParticipants();
        $this->facilityId = (int) $this->consultant->engagements[0]->facility_id;
        $this->frontDesk = $this->createUser('front.desk', User::ACCOUNT_FRONT_DESK);
        $this->frontDesk->updateAttributes(['facility_id' => $this->facilityId]);
        $this->facilityAdmin = $this->createUser('facility.admin', User::ACCOUNT_FACILITY_ADMIN);
        $this->facilityAdmin->updateAttributes(['facility_id' => $this->facilityId]);

        $agreement = new FacilityAgreement(['facility_id' => $this->facilityId, 'version' => '1.2', 'signed_on' => '2026-08-01',
            'facility_signatory' => 'Jane Admin, Director', 'platform_signatory' => 'Gumzo HealthFirst']);
        $agreement->save(false);
    }

    /** Starts the consultant's pilot so that today is pilot day $day. */
    private function onDay($day, $consultant = null)
    {
        $consultant = $consultant ?? $this->consultant;
        Consultant::updateAll(['first_booking_at' => strtotime('-' . ($day - 1) . ' days 10:00')], ['id' => $consultant->id]);
        $consultant->refresh();
    }

    private function queuedTo($email, $subjectPart = '')
    {
        return (int) (new Query())->from('{{%queue}}')
            ->where(['like', 'job', 'SendEmailMessageJob'])
            ->andWhere(['like', 'job', $email])
            ->andWhere(['like', 'job', $subjectPart])->count();
    }

    public function testTheStatusFollowsTheFacilitysPilotDay()
    {
        $this->assertSame(Pilot::TERMS_NOT_DUE, Pilot::termsStatus($this->facilityId));
        $this->onDay(80);
        $this->assertSame(Pilot::TERMS_NOT_DUE, Pilot::termsStatus($this->facilityId));
        $this->onDay(81);
        $this->assertSame(Pilot::TERMS_UPCOMING, Pilot::termsStatus($this->facilityId));
        $this->onDay(91);
        $this->assertSame(Pilot::TERMS_GRACE, Pilot::termsStatus($this->facilityId));
        $this->onDay(120);
        $this->assertSame(Pilot::TERMS_GRACE, Pilot::termsStatus($this->facilityId));
        $this->onDay(121);
        $this->assertSame(Pilot::TERMS_OVERDUE, Pilot::termsStatus($this->facilityId));

        StandardTerms::confirm($this->facilityId, $this->frontDesk->id);
        $this->assertSame(Pilot::TERMS_AWAITING_APPROVAL, Pilot::termsStatus($this->facilityId));
        StandardTerms::approve($this->facilityId, $this->admin->id);
        $this->assertSame(Pilot::TERMS_APPROVED, Pilot::termsStatus($this->facilityId));
    }

    public function testTheFacilityFollowsItsEarliestPilot()
    {
        $later = $this->createUser('later.doctor', 'consultant');
        $laterConsultant = new Consultant(['user_id' => $later->id, 'names' => 'Dr. Later']);
        // Saving a consultant engages them at the default facility, the test consultant's too
        $laterConsultant->save(false);
        $this->assertSame([$this->facilityId], array_map('intval', array_column($laterConsultant->engagements, 'facility_id')));

        $this->onDay(95);
        $this->onDay(10, $laterConsultant);
        $this->assertSame(95, Pilot::facilityDay($this->facilityId));
    }

    public function testNoticesGoOutOnDays81And91OnceEach()
    {
        $this->onDay(81);
        $this->assertCount(1, StandardTerms::sendDueNotices());
        $this->assertSame(1, $this->queuedTo('front.desk@example.com', 'Standard terms start'));
        $this->assertSame(1, $this->queuedTo('facility.admin@example.com', 'Standard terms start'));
        $this->assertSame(0, $this->queuedTo('doctor@example.com'), 'consultants are told in the app, not by email');
        $this->assertSame([], StandardTerms::sendDueNotices(), 'sent once');

        $this->onDay(91);
        $this->assertCount(1, StandardTerms::sendDueNotices());
        $this->assertSame(1, $this->queuedTo('front.desk@example.com', 'please confirm'));
        $this->assertSame([], StandardTerms::sendDueNotices());
    }

    public function testPlatformAdminsAreToldAfterTheGracePeriod()
    {
        $this->onDay(121);
        $sent = StandardTerms::sendDueNotices();
        $this->assertCount(2, $sent, 'the day 91 notice it missed, and the overdue one');
        $this->assertSame(1, $this->queuedTo('site.admin@example.com', 'has not confirmed'));
        $this->assertSame([], StandardTerms::sendDueNotices());
    }

    public function testNothingIsSentOnceConfirmed()
    {
        StandardTerms::confirm($this->facilityId, $this->facilityAdmin->id);
        $this->onDay(95);
        $this->assertSame([], StandardTerms::sendDueNotices());
    }

    public function testTheWarningShowsDuringTheGracePeriodToTheFacilitysPeople()
    {
        $this->onDay(85);
        $this->assertSame([], StandardTerms::warningsFor($this->frontDesk), 'not before day 91');

        $this->onDay(95);
        foreach ([$this->consultantUser, $this->frontDesk, $this->facilityAdmin] as $user) {
            $warnings = StandardTerms::warningsFor($user);
            $this->assertCount(1, $warnings, $user->username);
            $this->assertSame(Pilot::TERMS_GRACE, $warnings[0]['status']);
        }
        $this->assertSame([], StandardTerms::warningsFor($this->client));

        StandardTerms::confirm($this->facilityId, $this->frontDesk->id);
        $this->assertSame([], StandardTerms::warningsFor($this->consultantUser));
    }

    public function testWhoMayConfirm()
    {
        $this->assertTrue(Pilot::canConfirm($this->frontDesk, $this->facilityId));
        $this->assertTrue(Pilot::canConfirm($this->facilityAdmin, $this->facilityId));
        $this->assertTrue(Pilot::canConfirm($this->admin, $this->facilityId));
        $this->assertTrue(Pilot::canApprove($this->admin));
        $this->assertFalse(Pilot::canApprove($this->facilityAdmin));
        $this->assertFalse(Pilot::canApprove($this->frontDesk));
        $this->assertFalse(Pilot::canConfirm($this->consultantUser, $this->facilityId));
        $this->assertFalse(Pilot::canConfirm($this->client, $this->facilityId));
        $this->assertFalse(Pilot::canConfirm($this->frontDesk, $this->facilityId + 1000));

        $this->assertTrue(Pilot::canView($this->consultantUser, $this->facilityId));
        $this->assertFalse(Pilot::canView($this->client, $this->facilityId));
    }

    public function testConfirmingRecordsWhoOnceAndAsksPlatformAdminsToApprove()
    {
        $this->assertFalse(StandardTerms::approve($this->facilityId, $this->admin->id), 'nothing to approve before the facility confirms');

        $this->assertTrue(StandardTerms::confirm($this->facilityId, $this->frontDesk->id));
        $this->assertFalse(StandardTerms::confirm($this->facilityId, $this->facilityAdmin->id));
        $agreement = FacilityAgreement::currentFor($this->facilityId);
        $this->assertSame($this->frontDesk->id, (int) $agreement->standard_terms_confirmed_by);
        $this->assertNotNull($agreement->standard_terms_confirmed_at);
        $this->assertSame(1, $this->queuedTo('site.admin@example.com', 'please approve'));
    }

    public function testAPlatformAdminApprovesOnceAndTheFacilityIsTold()
    {
        StandardTerms::confirm($this->facilityId, $this->frontDesk->id);
        $this->assertTrue(StandardTerms::approve($this->facilityId, $this->admin->id));
        $this->assertFalse(StandardTerms::approve($this->facilityId, $this->admin->id));

        $agreement = FacilityAgreement::currentFor($this->facilityId);
        $this->assertSame($this->admin->id, (int) $agreement->standard_terms_approved_by);
        $this->assertSame(1, $this->queuedTo('front.desk@example.com', 'are approved'));
        $this->assertSame(1, $this->queuedTo('facility.admin@example.com', 'are approved'));
    }

    public function testPlatformAdminsAreWarnedUntilTheyApprove()
    {
        $this->onDay(95);
        $this->assertSame([], StandardTerms::warningsFor($this->admin));

        StandardTerms::confirm($this->facilityId, $this->frontDesk->id);
        $this->assertSame([], StandardTerms::warningsFor($this->frontDesk), 'the facility did its part');
        $warnings = StandardTerms::warningsFor($this->admin);
        $this->assertCount(1, $warnings);
        $this->assertSame(Pilot::TERMS_AWAITING_APPROVAL, $warnings[0]['status']);

        StandardTerms::approve($this->facilityId, $this->admin->id);
        $this->assertSame([], StandardTerms::warningsFor($this->admin));
    }

    public function testChargingNeedsDay91AndTheTermsApproved()
    {
        $this->onDay(95);
        $this->assertFalse(Pilot::onStandardTerms($this->consultant, $this->facilityId), 'not confirmed');
        StandardTerms::confirm($this->facilityId);
        $this->assertFalse(Pilot::onStandardTerms($this->consultant, $this->facilityId), 'confirmed, not approved');
        StandardTerms::approve($this->facilityId);
        $this->assertTrue(Pilot::onStandardTerms($this->consultant, $this->facilityId));

        $this->onDay(50);
        $this->assertFalse(Pilot::onStandardTerms($this->consultant, $this->facilityId), 'their own pilot is still running');
    }
}
