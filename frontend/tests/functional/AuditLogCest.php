<?php

namespace frontend\tests\functional;

use common\models\AuditLog;
use frontend\services\SelfServiceLink;
use frontend\tests\BookingDataTrait;
use frontend\tests\FunctionalTester;

/**
 * T7: the audit log page, an appointment's history, and what sign-ins and links record.
 */
class AuditLogCest
{
    use BookingDataTrait;

    public function _before(FunctionalTester $I)
    {
        \Yii::$app->cache->flush(); // per-IP rate limits
        $this->createParticipants();
    }

    private function latest($action)
    {
        return AuditLog::find()->where(['action' => $action])->orderBy(['id' => SORT_DESC])->one();
    }

    public function onlyAdminsSeeTheAuditLog(FunctionalTester $I)
    {
        foreach ([$this->client, $this->consultantUser] as $user) {
            $I->amLoggedInAs($user);
            $I->amOnRoute('audit/index');
            $I->seeResponseCodeIs(403);
        }

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('audit/index');
        $I->seeResponseCodeIs(200);
        $I->see('Audit Log', 'h1');
        $I->see('kept for 7 years');
    }

    public function signInsAndFailedSignInsAreRecorded(FunctionalTester $I)
    {
        $I->amOnRoute('site/login');
        $I->submitForm('#login-form', ['LoginForm[username]' => 'client', 'LoginForm[password]' => 'wrong-password']);
        $failed = $this->latest(AuditLog::ACTION_LOGIN_FAILED);
        $I->assertSame('username: client', $failed->note);
        $I->assertSame((string) $this->client->id, $failed->entity_id);
        $I->assertStringNotContainsString('wrong-password', (string) $failed->changes . $failed->note);

        $I->submitForm('#login-form', ['LoginForm[username]' => 'client', 'LoginForm[password]' => 'password_0']);
        $login = $this->latest(AuditLog::ACTION_LOGIN);
        $I->assertSame((int) $this->client->id, (int) $login->actor_id);
        $I->assertSame(AuditLog::ACTOR_PATIENT, $login->actor_type);
    }

    public function adminFiltersTheLog(FunctionalTester $I)
    {
        $I->amOnRoute('site/login');
        $I->submitForm('#login-form', ['LoginForm[username]' => 'nobody-like-this', 'LoginForm[password]' => 'x']);

        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('audit/index', ['action' => AuditLog::ACTION_LOGIN_FAILED]);
        $I->see('username: nobody-like-this');
        $I->amOnRoute('audit/index', ['action' => AuditLog::ACTION_LOGOUT]);
        $I->dontSee('username: nobody-like-this');
    }

    public function theClinicSeesAnAppointmentsHistoryButThePatientDoesNot(FunctionalTester $I)
    {
        $appointment = $this->book('10:00');
        $appointment->time = '11:00';
        $appointment->saveBooking();

        $I->amLoggedInAs($this->consultantUser);
        $I->amOnRoute('appointments/view', ['id' => $appointment->id]);
        $I->seeElement('#appointment-history');
        $I->see('10:00:00 → 11:00:00', '#appointment-history');
        $I->dontSee('Headache', '#appointment-history');

        $I->amLoggedInAs($this->client);
        $I->amOnRoute('appointments/view', ['id' => $appointment->id]);
        $I->seeResponseCodeIs(200);
        $I->dontSeeElement('#appointment-history');
    }

    public function changesThroughTheSelfServiceLinkAreTheirs(FunctionalTester $I)
    {
        $day = date('Y-m-d', strtotime('+3 days'));
        if (date('N', strtotime($day)) === '7') {
            $day = date('Y-m-d', strtotime($day . ' +1 day'));
        }
        $appointment = $this->newBooking('10:00', null, $day);
        $appointment->saveBooking();

        $I->amOnRoute('manage/index', ['id' => $appointment->id, 'sig' => SelfServiceLink::signature($appointment)]);
        $I->submitForm('#self-cancel-form', ['reason' => '']);
        $I->see('Your appointment is cancelled.');

        $entry = AuditLog::find()
            ->where(['entity_type' => 'appointment', 'entity_id' => (string) $appointment->id, 'action' => AuditLog::ACTION_UPDATED])
            ->orderBy(['id' => SORT_DESC])->one();
        $I->assertSame(AuditLog::ACTOR_SELF_SERVICE, $entry->actor_type);
        $I->assertSame((int) $this->client->id, (int) $entry->actor_id);
        $I->assertSame('cancelled', $entry->getChangeList()['status'][1]);
    }

    public function roleChangesAreRecorded(FunctionalTester $I)
    {
        $I->amLoggedInAs($this->admin);
        $I->amOnRoute('rbac/assign-role', ['userId' => $this->client->id]);
        $I->submitForm('#assign-role-form', ['UserRoleForm[roles]' => ['patient', 'front-desk']]);

        $entry = $this->latest(AuditLog::ACTION_ROLES_CHANGED);
        $I->assertSame((string) $this->client->id, $entry->entity_id);
        [$before, $after] = $entry->getChangeList()['roles'];
        $I->assertStringNotContainsString('front-desk', (string) $before);
        $I->assertStringContainsString('front-desk', $after);
    }
}
