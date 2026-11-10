<?php

namespace common\jobs;

use Yii;
use yii\base\BaseObject;
use yii\helpers\Html;

/**
 * Sends one plain email through the configured SMTP mailer (booking links, booking confirmations).
 * The HTML part is the text, escaped, with line breaks and the optional link made clickable.
 */
class SendEmailMessageJob extends BaseObject implements \yii\queue\JobInterface
{
    public $to;
    public $toName;
    public $subject;
    public $text;
    /** @var string|null shown as a button under the text */
    public $linkUrl;
    public $linkLabel;

    public function execute($queue)
    {
        $html = nl2br(Html::encode($this->text));
        if ($this->linkUrl) {
            $html .= '<p style="margin:24px 0"><a href="' . Html::encode($this->linkUrl) . '" style="background:#0d6efd;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">'
                . Html::encode($this->linkLabel ?: 'Open') . '</a></p>';
        }

        $sent = Yii::$app->mailer->compose()
            ->setTo($this->toName ? [$this->to => $this->toName] : $this->to)
            ->setFrom([Yii::$app->params['supportEmail'] => Yii::$app->name])
            ->setSubject($this->subject)
            ->setTextBody($this->text . ($this->linkUrl ? "\n\n" . $this->linkUrl : ''))
            ->setHtmlBody($html)
            ->send();

        if (!$sent) {
            Yii::error('Email to ' . $this->to . ' failed: ' . $this->subject, 'notifications');
            throw new \RuntimeException('Email could not be sent.');
        }
    }
}
