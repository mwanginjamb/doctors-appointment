<?php

namespace frontend\tests\unit;

use common\components\HealthCheck;
use common\components\HealthMonitor;
use common\log\ErrorTrackerTarget;
use common\models\HealthState;
use frontend\models\AppointmentNotifications;
use frontend\tests\BookingDataTrait;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;
use Yii;

/**
 * T2: each health check, the alert lifecycle (once, a reminder, resolved), and that error reports
 * carry no personal data.
 */
class HealthTest extends \Codeception\Test\Unit
{
    use BookingDataTrait;

    /** @var \frontend\tests\UnitTester */
    protected $tester;

    private $now;

    protected function _before()
    {
        $this->now = time();
        $db = Yii::$app->db;
        // Start from a quiet system (the test transaction rolls this back)
        $db->createCommand()->delete('{{%queue}}')->execute();
        $db->createCommand()->delete('{{%appointment_notifications}}')->execute();
        HealthState::deleteAll();
        Yii::$app->cache->flush();
        $this->createParticipants();
    }

    protected function _after()
    {
        Yii::$app->params['health.alertEmails'] = '';
    }

    private function check()
    {
        $check = new HealthCheck();
        $check->now = $this->now;
        $check->whatsappStatus = function () {
            return 'WORKING';
        };
        return $check;
    }

    private function pushJob($ageMinutes, array $extra = [])
    {
        Yii::$app->db->createCommand()->insert('{{%queue}}', $extra + [
            'channel' => 'default',
            'job' => 'test',
            'pushed_at' => $this->now - $ageMinutes * 60,
            'ttr' => 300,
            'delay' => 0,
            'priority' => 1024,
        ])->execute();
    }

    // ---- Checks ------------------------------------------------------------------------------

    public function testAQuietSystemIsHealthy()
    {
        foreach ($this->check()->run() as $key => $result) {
            $this->assertTrue($result['ok'], "$key: " . $result['message']);
        }
    }

    public function testAJobWaitingTooLongMeansTheWorkerIsStopped()
    {
        $this->pushJob(2);
        $this->assertTrue($this->check()->checkQueue()['ok'], 'two minutes is normal');

        $this->pushJob(12);
        $result = $this->check()->checkQueue();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('2 job(s) waiting, the oldest for 12 minutes', $result['message']);
    }

    public function testAJobRunningFarPastItsLimitMeansTheWorkerIsStuck()
    {
        $this->pushJob(30, ['reserved_at' => $this->now - 20 * 60]); // ttr 300 s
        $result = $this->check()->checkQueue();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('stuck', $result['message']);
    }

    public function testOverdueRemindersMeanTheSchedulerIsStopped()
    {
        $this->book('10:00');
        $this->assertTrue($this->check()->checkScheduler()['ok'], 'reminders for next week are not due');

        AppointmentNotifications::updateAll(['scheduled_time' => date('Y-m-d H:i:s', $this->now - 20 * 60)], ['status' => 'pending']);
        $result = $this->check()->checkScheduler();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('more than 10 minutes overdue', $result['message']);
    }

    public function testRepeatedFailuresAreReported()
    {
        $this->book('10:00');
        $ids = AppointmentNotifications::find()->select('id')->limit(3)->column();

        AppointmentNotifications::updateAll(['status' => 'failed', 'updated_at' => $this->now], ['id' => array_slice($ids, 0, 2)]);
        $this->assertTrue($this->check()->checkDelivery()['ok'], 'two failures is below the threshold');

        AppointmentNotifications::updateAll(['status' => 'failed', 'updated_at' => $this->now], ['id' => $ids]);
        $result = $this->check()->checkDelivery();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('3 reminder(s) failed', $result['message']);

        AppointmentNotifications::updateAll(['updated_at' => $this->now - 2 * 3600], ['id' => $ids]);
        $this->assertTrue($this->check()->checkDelivery()['ok'], 'only the last hour counts');
    }

    public function testWhatsappSessionStatus()
    {
        $check = $this->check();
        $check->whatsappStatus = function () {
            return 'SCAN_QR_CODE';
        };
        $result = $check->checkWhatsapp();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('scan the QR code', $result['message']);

        $check->whatsappStatus = function () {
            return null;
        };
        $this->assertSame('Not configured.', $check->checkWhatsapp()['message']);

        $check->whatsappStatus = function () {
            throw new \RuntimeException('WAHA cannot be reached: timeout');
        };
        $this->assertFalse($check->run()['whatsapp']['ok'], 'an unreachable WAHA is a failure');
    }

    // ---- Alerts ------------------------------------------------------------------------------

    private function monitorAt($minutesLater)
    {
        $monitor = new HealthMonitor();
        $monitor->now = $this->now + $minutesLater * 60;
        return $monitor;
    }

    public function testAProblemAlertsOnceRemindsAndResolves()
    {
        $failing = ['queue' => ['ok' => false, 'message' => 'Stopped.']];
        $fine = ['queue' => ['ok' => true, 'message' => 'Fine.']];

        $alerts = $this->monitorAt(0)->process($failing);
        $this->assertSame([HealthMonitor::PROBLEM], array_column($alerts, 'type'));
        $this->assertSame([], $this->monitorAt(5)->process($failing), 'no repeat 5 minutes later');
        $this->assertSame([], $this->monitorAt(5 * 60)->process($failing), 'nor 5 hours later');
        $this->assertSame([HealthMonitor::STILL_FAILING], array_column($this->monitorAt(6 * 60)->process($failing), 'type'), 'a reminder after 6 hours');

        $resolved = $this->monitorAt(6 * 60 + 5)->process($fine);
        $this->assertSame([HealthMonitor::RESOLVED], array_column($resolved, 'type'));
        $this->assertSame($this->now, $resolved[0]['since'], 'reports how long it lasted');
        $this->assertSame([], $this->monitorAt(6 * 60 + 10)->process($fine));

        $state = HealthState::findOne('queue');
        $this->assertFalse((bool) $state->failing);
    }

    public function testAlertsAreEmailedToTheAdmins()
    {
        Yii::$app->params['health.alertEmails'] = 'ops@example.com, owner@example.com';
        $monitor = $this->monitorAt(0);
        $alerts = $monitor->process(['whatsapp' => ['ok' => false, 'message' => 'The WhatsApp session is SCAN_QR_CODE.']]);

        $this->assertTrue($monitor->send($alerts));
        $mail = $this->tester->grabLastSentEmail();
        $this->assertArrayHasKey('ops@example.com', $mail->getTo());
        $this->assertArrayHasKey('owner@example.com', $mail->getTo());
        $this->assertStringContainsString('Problem: WhatsApp (WAHA)', $mail->getSubject());
        $this->assertStringContainsString('SCAN_QR_CODE', $mail->toString());
    }

    public function testNoAlertEmailWithoutRecipients()
    {
        Yii::$app->params['health.alertEmails'] = '';
        $monitor = $this->monitorAt(0);
        $this->assertFalse($monitor->send($monitor->process(['queue' => ['ok' => false, 'message' => 'Stopped.']])));
    }

    // ---- Error reports carry no personal data ------------------------------------------------

    public function testMaskingRemovesContactsAndLinkTokens()
    {
        $masked = ErrorTrackerTarget::mask(
            'Patient jane.doe@example.co.ke (0712 345 678, +254712345678) opened /manage/12/aB3dE5fG7hJ9 and /book/Zx9_abcdefghijk'
        );

        $this->assertStringNotContainsString('jane.doe', $masked);
        $this->assertStringNotContainsString('345 678', $masked);
        $this->assertStringNotContainsString('254712345678', $masked);
        $this->assertStringNotContainsString('aB3dE5fG7hJ9', $masked);
        $this->assertStringNotContainsString('Zx9_abcdefghijk', $masked);
        $this->assertStringContainsString('/manage/12/[signature]', $masked);
        $this->assertSame('Appointment #42 at 10:00', ErrorTrackerTarget::mask('Appointment #42 at 10:00'), 'ordinary text is kept');
    }

    public function testScrubDropsRequestDataAndKeepsOnlyTheUserId()
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://clinic/book/secret-token', 'data' => ['symptoms_brief' => 'Chest pain']]);
        $event->setExtra(['form' => 'Chest pain']);
        $event->setUser(UserDataBag::createFromUserIdentifier('7')->setEmail('jane@example.com'));
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('Could not send to jane@example.com'))]);

        $scrubbed = ErrorTrackerTarget::scrub($event);

        $this->assertSame([], $scrubbed->getRequest());
        $this->assertSame([], $scrubbed->getExtra());
        $this->assertSame('7', (string) $scrubbed->getUser()->getId());
        $this->assertNull($scrubbed->getUser()->getEmail());
        $this->assertSame('Could not send to [email]', $scrubbed->getExceptions()[0]->getValue());
    }
}
