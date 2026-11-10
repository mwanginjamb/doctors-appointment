<?php

namespace common\jobs;

use common\components\WahaClient;
use Yii;
use yii\base\BaseObject;

/**
 * Sends one WhatsApp text through WAHA (booking links, booking confirmations).
 */
class SendWhatsappTextJob extends BaseObject implements \yii\queue\JobInterface
{
    public $phone;
    public $text;

    public function execute($queue)
    {
        try {
            WahaClient::sendText($this->phone, $this->text);
            Yii::info('WhatsApp text sent to ' . WahaClient::chatId($this->phone), 'notifications');
        } catch (\Throwable $e) {
            Yii::error('WhatsApp text failed: ' . $e->getMessage(), 'notifications');
            throw $e;
        }
    }
}
