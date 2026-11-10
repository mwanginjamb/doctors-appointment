<?php

namespace common\components;

use common\models\HealthState;
use Yii;

/**
 * Turns health-check results into alerts (T2): one email when a check starts failing, a reminder
 * every health.realertHours while it keeps failing, and one when it is resolved. State is kept in
 * health_state; when the database itself is down, in the cache instead.
 *
 * Alerts are emailed directly, never through the queue: the queue may be what is broken.
 */
class HealthMonitor
{
    const PROBLEM = 'problem';
    const STILL_FAILING = 'still failing';
    const RESOLVED = 'resolved';

    /** @var int|null the time to use; null for now (tests fix it) */
    public $now;

    /**
     * Records the results and works out which alerts are due.
     *
     * @param array $results HealthCheck::run()
     * @return array[] each ['type' => self::*, 'key' => string, 'message' => string, 'since' => int|null]
     */
    public function process(array $results)
    {
        $now = $this->now ?? time();
        $realert = max(1, (int) (Yii::$app->params['health.realertHours'] ?? 6)) * 3600;
        $alerts = [];

        foreach ($results as $key => $result) {
            $state = $this->load($key);
            $wasFailing = $state !== null && $state['failing'];

            if (!$result['ok']) {
                $since = $wasFailing ? $state['since'] : $now;
                $alertedAt = $state['alerted_at'] ?? null;
                if (!$wasFailing) {
                    $alerts[] = ['type' => self::PROBLEM, 'key' => $key, 'message' => $result['message'], 'since' => $since];
                    $alertedAt = $now;
                } elseif ($alertedAt === null || $now - $alertedAt >= $realert) {
                    $alerts[] = ['type' => self::STILL_FAILING, 'key' => $key, 'message' => $result['message'], 'since' => $since];
                    $alertedAt = $now;
                }
                $this->save($key, ['failing' => true, 'message' => $result['message'], 'since' => $since, 'checked_at' => $now, 'alerted_at' => $alertedAt]);
            } else {
                if ($wasFailing) {
                    $alerts[] = ['type' => self::RESOLVED, 'key' => $key, 'message' => $result['message'], 'since' => $state['since']];
                }
                $this->save($key, ['failing' => false, 'message' => $result['message'], 'since' => null, 'checked_at' => $now, 'alerted_at' => null]);
            }
        }

        return $alerts;
    }

    /**
     * Emails the alerts to health.alertEmails, all in one message.
     *
     * @return bool whether an email was sent
     */
    public function send(array $alerts)
    {
        if (!$alerts) {
            return false;
        }
        $to = array_filter(array_map('trim', explode(',', (string) (Yii::$app->params['health.alertEmails'] ?? ''))));
        if (!$to) {
            Yii::warning('Health alerts are due but ALERT_EMAILS is not set: ' . json_encode($alerts), __METHOD__);
            return false;
        }

        $labels = HealthCheck::labels();
        $problems = array_filter($alerts, function ($a) {
            return $a['type'] !== self::RESOLVED;
        });
        $names = implode(', ', array_map(function ($a) use ($labels) {
            return $labels[$a['key']] ?? $a['key'];
        }, $problems ?: $alerts));
        $app = Yii::$app->name ?: 'Appointments';
        $subject = $problems
            ? "[$app] Problem: $names"
            : "[$app] Resolved: $names";

        $lines = [];
        foreach ($alerts as $alert) {
            $label = $labels[$alert['key']] ?? $alert['key'];
            $when = $alert['since'] ? ' (since ' . date('D j M H:i', $alert['since']) . ')' : '';
            $lines[] = strtoupper($alert['type']) . " - $label$when\n  " . $alert['message'];
        }
        $text = implode("\n\n", $lines)
            . "\n\nChecked at " . date('Y-m-d H:i:s', $this->now ?? time()) . ' on ' . gethostname() . '.'
            . "\nCurrent state: php yii health/status, or System health on the admin dashboard.";

        try {
            $from = Yii::$app->params['senderEmail'] ?? Yii::$app->params['supportEmail'] ?? null;
            $message = Yii::$app->mailer->compose()->setTo($to)->setSubject($subject)->setTextBody($text);
            if ($from) {
                $message->setFrom([$from => $app . ' monitoring']);
            }
            return (bool) $message->send();
        } catch (\Throwable $e) {
            Yii::error('Could not email health alerts: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Pings the heartbeat URL (healthchecks.io style: /fail when something is failing). An external
     * service alerts when the pings stop, which covers the whole server being down.
     */
    public function ping($healthy)
    {
        $url = trim((string) (Yii::$app->params['health.pingUrl'] ?? ''));
        if ($url === '') {
            return false;
        }
        $ch = curl_init(rtrim($url, '/') . ($healthy ? '' : '/fail'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
        curl_close($ch);
        if (!$ok) {
            Yii::warning("Heartbeat ping to $url failed.", __METHOD__);
        }
        return $ok;
    }

    private function load($key)
    {
        $cacheKey = 'health_state.' . $key;
        try {
            $state = HealthState::findOne($key);
            // State remembered while the database was down is newer than the table's
            $cached = Yii::$app->cache->get($cacheKey);
            if ($cached !== false) {
                Yii::$app->cache->delete($cacheKey);
                return $cached;
            }
            return $state ? [
                'failing' => (bool) $state->failing,
                'since' => $state->since !== null ? (int) $state->since : null,
                'alerted_at' => $state->alerted_at !== null ? (int) $state->alerted_at : null,
            ] : null;
        } catch (\Throwable $e) {
            // The database is down: remember in the cache meanwhile
            $cached = Yii::$app->cache->get('health_state.' . $key);
            return $cached === false ? null : $cached;
        }
    }

    private function save($key, array $values)
    {
        try {
            $state = HealthState::findOne($key) ?? new HealthState(['check_key' => $key]);
            $state->setAttributes($values, false);
            $state->save(false);
        } catch (\Throwable $e) {
            Yii::$app->cache->set('health_state.' . $key, $values, 7 * 86400);
        }
    }
}
