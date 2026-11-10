<?php

namespace common\components;

use Yii;
use yii\db\Query;

/**
 * The health checks (T2): the failures that otherwise only show up when patients complain.
 *
 *  - database: MySQL answers;
 *  - queue: jobs are being picked up (the queue worker is running and not stuck);
 *  - scheduler: due reminders are being queued (the reminder scheduler timer is running);
 *  - delivery: messages are not failing (SMTP, WhatsApp, SMS);
 *  - whatsapp: the WAHA session is logged in.
 *
 * Thresholds come from params (health.*), overridable in .env.
 */
class HealthCheck
{
    /** @var int|null the time to check against; null for now (tests fix it) */
    public $now;

    /** @var callable|null returns the WAHA session status (WahaClient::sessionStatus by default) */
    public $whatsappStatus;

    public static function labels()
    {
        return [
            'database' => 'Database',
            'queue' => 'Queue worker',
            'scheduler' => 'Reminder scheduler',
            'delivery' => 'Message delivery',
            'whatsapp' => 'WhatsApp (WAHA)',
        ];
    }

    /**
     * Runs every check. A check that throws counts as failing, with the error as its message.
     *
     * @return array key => ['ok' => bool, 'message' => string]
     */
    public function run()
    {
        $results = [];
        foreach (array_keys(self::labels()) as $key) {
            try {
                $results[$key] = $this->{'check' . ucfirst($key)}();
            } catch (\Throwable $e) {
                $results[$key] = self::fail(get_class($e) . ': ' . $e->getMessage());
            }
            if ($key === 'database' && !$results[$key]['ok']) {
                // Nothing else can be checked without the database
                foreach (array_slice(array_keys(self::labels()), 1) as $skipped) {
                    $results[$skipped] = self::ok('Not checked: the database is down.');
                }
                break;
            }
        }
        return $results;
    }

    public function checkDatabase()
    {
        Yii::$app->db->createCommand('SELECT 1')->queryScalar();
        return self::ok('Answering.');
    }

    /**
     * The oldest job waiting to be picked up, and jobs running far longer than their time limit.
     */
    public function checkQueue()
    {
        $now = $this->now();
        $table = Yii::$app->queue->tableName ?? '{{%queue}}';

        $oldest = (new Query())->from($table)
            ->where(['reserved_at' => null, 'done_at' => null])
            ->andWhere(new \yii\db\Expression('[[pushed_at]] + [[delay]] <= :now', [':now' => $now]))
            ->min(new \yii\db\Expression('[[pushed_at]] + [[delay]]'));
        $maxWait = $this->param('health.queueMaxWaitMinutes', 5);
        if ($oldest !== null && $oldest !== false && $now - (int) $oldest > $maxWait * 60) {
            $waiting = (new Query())->from($table)->where(['reserved_at' => null, 'done_at' => null])
                ->andWhere(new \yii\db\Expression('[[pushed_at]] + [[delay]] <= :now', [':now' => $now]))->count();
            return self::fail(sprintf(
                '%d job(s) waiting, the oldest for %d minutes. The queue worker may be stopped: systemctl status doctors-appointment-queue.',
                $waiting,
                intdiv($now - (int) $oldest, 60)
            ));
        }

        $stuck = (new Query())->from($table)
            ->where(['done_at' => null])
            ->andWhere(['not', ['reserved_at' => null]])
            ->andWhere(new \yii\db\Expression('[[reserved_at]] + [[ttr]] + 60 < :now', [':now' => $now]))
            ->count();
        if ($stuck > 0) {
            return self::fail("$stuck job(s) running far past their time limit. The queue worker may be stuck: restart doctors-appointment-queue.");
        }

        return self::ok('Jobs are being picked up.');
    }

    /**
     * Reminders whose time has come but are still pending: the scheduler is not running.
     */
    public function checkScheduler()
    {
        $late = $this->param('health.reminderMaxLateMinutes', 10);
        $count = (new Query())->from('{{%appointment_notifications}}')
            ->where(['status' => 'pending'])
            ->andWhere(['<', 'scheduled_time', date('Y-m-d H:i:s', $this->now() - $late * 60)])
            ->count();
        if ($count > 0) {
            return self::fail("$count reminder(s) more than $late minutes overdue. The reminder scheduler may be stopped: systemctl list-timers doctors-appointment-scheduler.timer.");
        }
        return self::ok('Due reminders are being queued.');
    }

    /**
     * Reminders that failed to send in the last hour.
     */
    public function checkDelivery()
    {
        $threshold = $this->param('health.failuresPerHour', 3);
        $failed = (new Query())->from('{{%appointment_notifications}}')
            ->where(['status' => 'failed'])
            ->andWhere(['>=', 'updated_at', $this->now() - 3600])
            ->count();
        if ($failed >= $threshold) {
            $types = (new Query())->select(['notification_type', 'n' => 'COUNT(*)'])->from('{{%appointment_notifications}}')
                ->where(['status' => 'failed'])->andWhere(['>=', 'updated_at', $this->now() - 3600])
                ->groupBy('notification_type')->all();
            $byType = implode(', ', array_map(function ($row) {
                return $row['n'] . ' ' . $row['notification_type'];
            }, $types));
            return self::fail("$failed reminder(s) failed to send in the last hour ($byType). Check runtime/logs/jobs.log and mail.log.");
        }
        return self::ok($failed ? "$failed failure(s) in the last hour." : 'No failures in the last hour.');
    }

    public function checkWhatsapp()
    {
        $status = call_user_func($this->whatsappStatus ?? [WahaClient::class, 'sessionStatus']);
        if ($status === null) {
            return self::ok('Not configured.');
        }
        if ($status === 'WORKING') {
            return self::ok('Logged in.');
        }
        $advice = $status === 'SCAN_QR_CODE'
            ? 'It is logged out: open the WAHA dashboard and scan the QR code with the clinic phone.'
            : 'Messages cannot be sent: check the WAHA dashboard.';
        return self::fail("The WhatsApp session is $status. $advice");
    }

    private function now()
    {
        return $this->now ?? time();
    }

    private function param($name, $default)
    {
        return max(1, (int) (Yii::$app->params[$name] ?? $default));
    }

    private static function ok($message)
    {
        return ['ok' => true, 'message' => $message];
    }

    private static function fail($message)
    {
        return ['ok' => false, 'message' => mb_substr($message, 0, 500)];
    }
}
