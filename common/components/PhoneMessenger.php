<?php

namespace common\components;

use Yii;
use yii\base\Component;

/**
 * Sends a short text to a phone, by WhatsApp (WAHA) or SMS, straight away (not through the queue:
 * a sign-in code is useless a minute late). The 'phoneMessenger' application component.
 *
 * With $fake on (tests), nothing is sent: messages are kept in self::$outbox.
 */
class PhoneMessenger extends Component
{
    const CHANNEL_WHATSAPP = 'whatsapp';
    const CHANNEL_SMS = 'sms';

    /** @var bool keep messages in $outbox instead of sending them */
    public $fake = false;
    /** @var array[] each ['channel' => ..., 'phone' => ..., 'text' => ...] (fake mode). Static, so
     * functional tests see it after the request that sent it. */
    public static $outbox = [];
    /** @var string[] channels that fail in fake mode, to test fallbacks */
    public $failing = [];
    /** @var string[]|null channels available in fake mode (null: both) */
    public $fakeChannels;

    /**
     * The channels that can send, in the order to try them: WhatsApp, then SMS.
     *
     * @return string[]
     */
    public function channels()
    {
        if ($this->fake) {
            return $this->fakeChannels ?? [self::CHANNEL_WHATSAPP, self::CHANNEL_SMS];
        }
        $channels = [];
        if (!empty(Yii::$app->params['whatsapp']['endpoint'])) {
            $channels[] = self::CHANNEL_WHATSAPP;
        }
        if (SmsClient::isConfigured()) {
            $channels[] = self::CHANNEL_SMS;
        }

        return $channels;
    }

    /**
     * @throws \RuntimeException when the channel cannot send
     */
    public function send($channel, $phone, $text)
    {
        if ($this->fake) {
            if (in_array($channel, $this->failing, true) || !in_array($channel, $this->channels(), true)) {
                throw new \RuntimeException("$channel unavailable (fake)");
            }
            self::$outbox[] = ['channel' => $channel, 'phone' => $phone, 'text' => $text];
            return;
        }
        if ($channel === self::CHANNEL_WHATSAPP) {
            WahaClient::sendText($phone, $text);
        } elseif ($channel === self::CHANNEL_SMS) {
            SmsClient::send($phone, $text);
        } else {
            throw new \RuntimeException("Unknown channel $channel");
        }
    }

    /**
     * The last message sent (fake mode).
     */
    public static function last()
    {
        return self::$outbox ? end(self::$outbox) : null;
    }
}
