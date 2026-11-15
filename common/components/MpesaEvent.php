<?php

namespace common\components;

use common\models\MpesaTransaction;
use yii\base\Event;

/**
 * Raised by {@see Mpesa} when a transaction reaches its final status (succeeded, failed, cancelled
 * or timed out). Features that ask for payments listen for it, for example:
 *
 *     Event::on(Mpesa::class, Mpesa::EVENT_SETTLED, function (MpesaEvent $e) { ... $e->transaction ... });
 */
class MpesaEvent extends Event
{
    /** @var MpesaTransaction */
    public $transaction;
}
