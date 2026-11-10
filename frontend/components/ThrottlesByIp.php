<?php

namespace frontend\components;

use Yii;
use yii\web\TooManyRequestsHttpException;

/**
 * Per-IP request limit for public pages reached through links (booking links, self-service links),
 * against guessing tokens or flooding bookings. Uses the application cache.
 */
trait ThrottlesByIp
{
    /**
     * @param string $bucket separates the limits of different pages
     * @param int $limit requests allowed per window
     * @param int $window seconds
     * @throws TooManyRequestsHttpException
     */
    protected function throttle($bucket, $limit = 40, $window = 600)
    {
        $key = "throttle:$bucket:" . Yii::$app->request->userIP;
        $cache = Yii::$app->cache;
        $count = (int) $cache->get($key) + 1;
        $cache->set($key, $count, $window);
        if ($count > $limit) {
            throw new TooManyRequestsHttpException(Yii::t('app', 'Too many requests. Please wait a few minutes and try again.'));
        }
    }
}
