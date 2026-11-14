<?php

namespace common\jobs;

use yii\base\BaseObject;

/**
 * F4: a slot with a consultant freed up (an appointment was cancelled or moved): tell the patients
 * waiting for them about the new times. Queued by the waiting list when an appointment changes.
 */
class WaitlistJob extends BaseObject implements \yii\queue\JobInterface
{
    /** @var int appointments.consultant_id */
    public $consultantUserId;

    public function execute($queue)
    {
        \frontend\services\Waitlist::notifyFor((int) $this->consultantUserId);
    }
}
