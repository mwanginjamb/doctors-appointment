<?php

namespace frontend\tests\unit;

use frontend\models\Appointments;
use frontend\models\Consultant;
use frontend\models\Provider;
use frontend\services\BookingService;
use frontend\services\FeeRules;
use frontend\services\Insurance;
use frontend\tests\BookingDataTrait;
use Yii;
use yii\db\Query;

/**
 * F18: insured bookings (insurer and member number, checked against the consultant's list) and
 * their pre-authorisation.
 */
class InsuranceTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var Provider */
    private $jubilee;
    /** @var Provider */
    private $aar;

    protected function _before()
    {
        $this->createParticipants();
        Yii::$app->params['commitmentFee']['enabled'] = false; // asked whatever the fee
        $this->jubilee = new Provider(['provider' => 'Jubilee']);
        $this->jubilee->save(false);
        $this->aar = new Provider(['provider' => 'AAR']);
        $this->aar->save(false);
        Consultant::updateAll(['covers_supported' => (string) $this->jubilee->id], ['id' => $this->consultant->id]);
    }

    protected function _after()
    {
        Yii::$app->user->logout();
    }

    private function bookAs($time, array $answers)
    {
        Yii::$app->user->login($this->client);
        $appointment = $this->newBooking($time);
        FeeRules::load($appointment, $answers);
        $ok = Yii::createObject(BookingService::class)->book($appointment);

        return [$ok, $appointment];
    }

    private function queued($contains)
    {
        return (int) (new Query())->from('{{%queue}}')->where(['like', 'job', 'SendEmailMessageJob'])->andWhere(['like', 'job', $contains])->count();
    }

    public function testAnInsuredBookingKeepsTheInsurerAndStartsPending()
    {
        [$ok, $a] = $this->bookAs('10:00', ['payment_mode' => 'insurance', 'insurer_id' => $this->jubilee->id, 'member_number' => ' JUB-123 ']);
        $this->assertTrue($ok, json_encode($a->errors));
        $a->refresh();
        $this->assertSame((int) $this->jubilee->id, (int) $a->insurer_id);
        $this->assertSame('JUB-123', $a->member_number);
        $this->assertSame(Insurance::PREAUTH_PENDING, $a->preauth_status);
        $this->assertSame(1, (int) $a->insurer_listed);
        $this->assertSame('Jubilee · member JUB-123', Insurance::summary($a));
    }

    public function testAnInsurerTheConsultantDoesNotListIsFlaggedNotRefused()
    {
        [$ok, $a] = $this->bookAs('10:00', ['payment_mode' => 'insurance', 'insurer_id' => $this->aar->id, 'member_number' => 'AAR-9']);
        $this->assertTrue($ok, json_encode($a->errors));
        $a->refresh();
        $this->assertSame(0, (int) $a->insurer_listed);
        $this->assertSame(Insurance::PREAUTH_PENDING, $a->preauth_status);
    }

    public function testInsuranceNeedsTheInsurerAndMemberNumber()
    {
        [$ok, $a] = $this->bookAs('10:00', ['payment_mode' => 'insurance', 'insurer_id' => '', 'member_number' => '']);
        $this->assertFalse($ok);
        $this->assertStringContainsString('insurer and the member number', json_encode($a->errors));
    }

    public function testCashHasNoInsurerOrPreauth()
    {
        [$ok, $a] = $this->bookAs('10:00', ['payment_mode' => 'cash', 'insurer_id' => $this->jubilee->id, 'member_number' => 'X']);
        $this->assertTrue($ok);
        $a->refresh();
        $this->assertNull($a->insurer_id);
        $this->assertNull($a->preauth_status);
    }

    public function testTheLastInsuredBookingFillsInTheNext()
    {
        $this->assertSame([null, null], Insurance::lastFor($this->client->id));
        $this->bookAs('10:00', ['payment_mode' => 'insurance', 'insurer_id' => $this->jubilee->id, 'member_number' => 'JUB-1']);
        $this->assertSame([(int) $this->jubilee->id, 'JUB-1'], Insurance::lastFor($this->client->id));
    }

    public function testADeclineIsSentToThePatientAnApprovalIsNot()
    {
        [, $a] = $this->bookAs('10:00', ['payment_mode' => 'insurance', 'insurer_id' => $this->jubilee->id, 'member_number' => 'JUB-1']);
        Yii::$app->user->login($this->consultantUser);
        $a->refresh();
        $this->assertTrue(Insurance::canRecord($a));

        $this->assertNull(Insurance::record($a, Insurance::PREAUTH_APPROVED, 'SL-778'));
        $this->assertSame('SL-778', Appointments::findOne($a->id)->preauth_reference);
        $this->assertSame(0, $this->queued('did not approve'));

        $this->assertNull(Insurance::record($a, Insurance::PREAUTH_DECLINED, '', 'Benefit exhausted'));
        $this->assertSame(1, $this->queued('Jubilee did not approve your visit to Dr. Test'));
        $this->assertSame(1, $this->queued('Benefit exhausted'));
        $this->assertNotNull(Insurance::record($a, 'maybe'), 'unknown status refused');
    }

    public function testThePatientCannotRecordIt()
    {
        [, $a] = $this->bookAs('10:00', ['payment_mode' => 'insurance', 'insurer_id' => $this->jubilee->id, 'member_number' => 'JUB-1']);
        $a->refresh();
        $this->assertFalse(Insurance::canRecord($a), 'signed in as the patient');
    }
}
