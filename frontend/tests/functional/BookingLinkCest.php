<?php

namespace frontend\tests\functional;

use common\models\User;
use frontend\components\FrontDesk;
use frontend\models\Appointments;
use frontend\models\BookingLink;
use frontend\models\Consultant;
use frontend\models\FrontDeskAssignment;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;
use yii\db\Query;

class BookingLinkCest
{
    use BookingDataTrait;

    /** @var User */
    private $frontDesk;

    public function _before(FunctionalTester $I)
    {
        FrontDesk::reset();
        \Yii::$app->cache->flush(); // the public page's per-IP rate limit
        $this->createParticipants();
        $this->frontDesk = $this->createUser('front.desk', FrontDesk::ROLE);
        FrontDeskAssignment::replaceFor($this->frontDesk->id, [$this->consultant->id]);
    }

    /**
     * A link on the fixture Monday from $this->sender (default: the consultant).
     *
     * @return array [BookingLink, string token]
     */
    private function issue(array $overrides = [], ?User $sender = null)
    {
        \Yii::$app->user->setIdentity($sender ?? $this->consultantUser);
        $issued = BookingLink::issue($overrides + [
            'consultant_id' => $this->consultant->id,
            'recipient_name' => 'Client',
            'recipient_phone' => '0712345678',
            'from_date' => $this->date,
            'to_date' => $this->date,
            'day_start' => '14:00:00',
            'day_end' => '15:00:00',
        ]);
        \Yii::$app->user->setIdentity(null);

        return $issued;
    }

    private function queued($jobClass)
    {
        return (int) (new Query())->from('{{%queue}}')->where(['like', 'job', $jobClass])->count();
    }

    public function consultantSendsALinkByWhatsappAndEmail(FunctionalTester $I)
    {
        $before = [$this->queued('SendWhatsappTextJob'), $this->queued('SendEmailMessageJob')];
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('booking-link/create');
        $I->submitForm('#booking-link-form', [
            'BookingLinkForm[recipient_name]' => 'Achieng Remote',
            'BookingLinkForm[recipient_phone]' => '0733 222 111',
            'BookingLinkForm[recipient_email]' => 'achieng@example.com',
            'BookingLinkForm[from_date]' => $this->date,
            'BookingLinkForm[to_date]' => $this->date,
        ]);

        $I->see('Booking link sent');
        $I->seeInSource('/book/');
        $link = BookingLink::findOne(['recipient_name' => 'Achieng Remote']);
        $I->assertSame('0733222111', $link->recipient_phone);
        $I->assertSame($this->consultantUser->id, (int) $link->created_by);
        $I->assertEqualsWithDelta(time() + 20 * 60, $link->expires_at, 5, 'expires 20 minutes after it is created');
        $I->assertSame($before[0] + 1, $this->queued('SendWhatsappTextJob'));
        $I->assertSame($before[1] + 1, $this->queued('SendEmailMessageJob'));
    }

    public function aLinkNeedsAPhoneOrEmailAndAtMostFiveDays(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('booking-link/create');
        $I->submitForm('#booking-link-form', [
            'BookingLinkForm[recipient_name]' => 'No Contact',
            'BookingLinkForm[from_date]' => $this->date,
            'BookingLinkForm[to_date]' => date('Y-m-d', strtotime($this->date . ' +5 days')),
        ]);

        $I->see('Enter a phone number for WhatsApp, an email address, or both.');
        $I->see('Offer at most 5 days.');
        $I->assertFalse(BookingLink::find()->exists());
    }

    public function frontDeskSendsLinksOnlyForTheirConsultants(FunctionalTester $I)
    {
        $otherDoctor = $this->createUser('other.doctor', 'consultant');
        $other = new Consultant(['user_id' => $otherDoctor->id, 'names' => 'Dr. Other']);
        $other->save(false);

        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('booking-link/create', ['cid' => $this->consultant->id]);
        $I->seeResponseCodeIs(200);
        $I->amOnRoute('booking-link/create', ['cid' => $other->id]);
        $I->seeResponseCodeIs(403);

        $I->amLoggedInAs($this->client);
        $I->amOnRoute('booking-link/create');
        $I->seeResponseCodeIs(403);
    }

    public function theTokenIsNeverStored(FunctionalTester $I)
    {
        [$link, $token] = $this->issue();

        $I->assertSame(hash('sha256', $token), $link->token_hash);
        $I->assertFalse((new Query())->from('{{%booking_link}}')->where(['like', 'token_hash', $token])->exists());
    }

    public function thePublicPageOffersOnlyTheFreeSlotsInTheRange(FunctionalTester $I)
    {
        $this->book('14:00'); // taken
        [, $token] = $this->issue();

        $I->amOnPage('/book/' . $token);
        $I->see('Book with Dr. Test');
        $I->seeInSource('"time":"14:30"');
        $I->dontSeeInSource('"time":"14:00"');
        $I->dontSeeInSource('"time":"10:00"'); // outside the link's hours
    }

    public function aRemoteWalkInBooksForTheAddressedPatient(FunctionalTester $I)
    {
        [$link, $token] = $this->issue();
        $whatsappBefore = $this->queued('SendWhatsappTextJob');

        $I->amOnPage('/book/' . $token);
        $I->submitForm('#remote-walk-in-form', [
            'RemoteWalkInForm[date]' => $this->date,
            'RemoteWalkInForm[time]' => '14:30',
            'RemoteWalkInForm[consent_data]' => '1',
            'RemoteWalkInForm[consent_reminders]' => '1',
            'RemoteWalkInForm[full_name]' => 'Client',
            'RemoteWalkInForm[phone]' => '0700000000', // ignored: the link's number is fixed
            'RemoteWalkInForm[reason]' => 'Follow-up',
        ]);

        $I->see('Your appointment is booked');
        // Fixture patients share 0712345678; the one whose name matches is chosen
        $appointment = Appointments::findOne(['booking_link_id' => $link->id]);
        $I->assertSame($this->client->id, (int) $appointment->patient_id);
        $I->assertSame($this->consultantUser->id, (int) $appointment->created_by, 'booked on behalf of the sender');
        $I->assertSame('14:30:00', $appointment->time);
        $I->assertNotNull(BookingLink::findOne($link->id)->used_at);
        $I->assertSame($whatsappBefore + 1, $this->queued('SendWhatsappTextJob'), 'WhatsApp confirmation');
    }

    public function anUnknownContactBecomesANewWalkInPatient(FunctionalTester $I)
    {
        [$link, $token] = $this->issue(['recipient_name' => 'New Person', 'recipient_phone' => '0799123456']);

        $I->amOnPage('/book/' . $token);
        $I->submitForm('#remote-walk-in-form', [
            'RemoteWalkInForm[date]' => $this->date,
            'RemoteWalkInForm[time]' => '14:00',
            'RemoteWalkInForm[consent_data]' => '1',
            'RemoteWalkInForm[consent_reminders]' => '1',
            'RemoteWalkInForm[full_name]' => 'New Person',
        ]);

        $I->see('Your appointment is booked');
        $patient = User::findOne(Appointments::findOne(['booking_link_id' => $link->id])->patient_id);
        $I->assertSame('client', $patient->role);
        $I->assertSame('0799123456', $patient->phone_number);
        $I->assertNull($patient->email);
    }

    public function aLinkBooksOnce(FunctionalTester $I)
    {
        [, $token] = $this->issue();
        $I->amOnPage('/book/' . $token);
        $I->submitForm('#remote-walk-in-form', [
            'RemoteWalkInForm[date]' => $this->date,
            'RemoteWalkInForm[time]' => '14:00',
            'RemoteWalkInForm[consent_data]' => '1',
            'RemoteWalkInForm[consent_reminders]' => '1',
            'RemoteWalkInForm[full_name]' => 'Client',
        ]);
        $I->see('Your appointment is booked');

        $I->amOnPage('/book/' . $token);
        $I->see('This booking link has already been used');
    }

    public function expiredRevokedAndUnknownLinksDoNotWork(FunctionalTester $I)
    {
        [$expired, $expiredToken] = $this->issue();
        BookingLink::updateAll(['expires_at' => time() - 1], ['id' => $expired->id]);
        [$revoked, $revokedToken] = $this->issue();
        BookingLink::updateAll(['revoked_at' => time()], ['id' => $revoked->id]);

        $I->amOnPage('/book/' . $expiredToken);
        $I->see('This booking link has expired');
        $I->amOnPage('/book/' . $revokedToken);
        $I->see('This booking link is no longer valid');
        $I->amOnPage('/book/' . str_repeat('x', 43));
        $I->see('This booking link is not valid');
    }

    public function aSlotTakenMeanwhileIsRefused(FunctionalTester $I)
    {
        [$link, $token] = $this->issue();
        $I->amOnPage('/book/' . $token);
        $this->book('14:30', $this->otherClient); // someone else takes it while the page is open

        $I->submitForm('#remote-walk-in-form', [
            'RemoteWalkInForm[date]' => $this->date,
            'RemoteWalkInForm[time]' => '14:30',
            'RemoteWalkInForm[consent_data]' => '1',
            'RemoteWalkInForm[consent_reminders]' => '1',
            'RemoteWalkInForm[full_name]' => 'Client',
        ]);

        $I->see('That time is no longer free');
        $I->assertNull(BookingLink::findOne($link->id)->used_at);
    }

    public function staffSeeAndRevokeTheirLinksAndTheBoardShowsTheSource(FunctionalTester $I)
    {
        [$link, $token] = $this->issue([], $this->frontDesk);
        $I->amOnPage('/book/' . $token);
        $I->submitForm('#remote-walk-in-form', [
            'RemoteWalkInForm[date]' => $this->date,
            'RemoteWalkInForm[time]' => '14:00',
            'RemoteWalkInForm[consent_data]' => '1',
            'RemoteWalkInForm[consent_reminders]' => '1',
            'RemoteWalkInForm[full_name]' => 'Client',
        ]);
        [$open] = $this->issue([], $this->frontDesk);

        $I->amLoggedInAs($this->frontDesk);
        $I->amOnRoute('board/index', ['from' => $this->date]);
        $I->see('Remote walk-in (link from Front Desk)', 'table');

        $I->amOnRoute('booking-link/index');
        $I->see('Booked', '.badge');
        $I->postAsLink(['/booking-link/revoke', 'id' => $open->id]);
        $I->assertNotNull(BookingLink::findOne($open->id)->revoked_at);
    }
}
