<?php

/** @var yii\web\View $this */
/** @var array $pending ['phone', 'sentAt', 'channel'] */
/** @var string|null $error */
/** @var int $resendIn seconds until a new code may be asked for */
/** @var string[] $channels */

use common\components\PhoneMessenger;
use common\helpers\Phone;
use frontend\controllers\PhoneSignInController;
use yii\bootstrap5\Html;

$this->title = 'Enter your code';
$other = $pending['channel'] === PhoneMessenger::CHANNEL_WHATSAPP ? PhoneMessenger::CHANNEL_SMS : PhoneMessenger::CHANNEL_WHATSAPP;
?>
<div class="phone-sign-in-code">
    <p class="text-muted text-center">
        We sent a code by <strong><?= PhoneSignInController::channelName($pending['channel']) ?></strong>
        to <strong><?= Html::encode(Phone::mask($pending['phone'])) ?></strong>.
    </p>

    <?= Html::beginForm(['phone-sign-in/code'], 'post', ['id' => 'code-form']) ?>
        <div class="mb-3">
            <label class="form-label" for="code">6-digit code</label>
            <?= Html::textInput('code', '', [
                'id' => 'code',
                'class' => 'form-control form-control-lg text-center fs-3' . ($error ? ' is-invalid' : ''),
                'style' => 'letter-spacing: .5em',
                'inputmode' => 'numeric',
                'autocomplete' => 'one-time-code',
                'pattern' => '[0-9 ]{6,7}',
                'maxlength' => 7,
                'required' => true,
                'autofocus' => true,
            ]) ?>
            <?php if ($error): ?>
                <div class="invalid-feedback d-block"><?= Html::encode($error) ?></div>
            <?php endif; ?>
        </div>
        <div class="form-check mb-3">
            <?= Html::checkbox('remember', true, ['id' => 'remember', 'class' => 'form-check-input']) ?>
            <label class="form-check-label" for="remember">Keep me signed in on this device</label>
        </div>
        <?= Html::submitButton('Continue', ['class' => 'btn btn-primary btn-lg w-100']) ?>
    <?= Html::endForm() ?>

    <div class="mt-4" id="resend" data-wait="<?= (int) $resendIn ?>">
        <p class="small text-muted mb-2" id="resend-wait"<?= $resendIn ? '' : ' hidden' ?>>
            No code? You can ask for another in <span id="resend-seconds"><?= (int) $resendIn ?></span> seconds.
        </p>
        <div class="d-flex flex-wrap gap-2" id="resend-buttons"<?= $resendIn ? ' hidden' : '' ?>>
            <?= Html::beginForm(['phone-sign-in/resend'], 'post', ['id' => 'resend-form']) ?>
                <?= Html::hiddenInput('channel', $pending['channel']) ?>
                <?= Html::submitButton('Send again', ['class' => 'btn btn-outline-secondary btn-sm']) ?>
            <?= Html::endForm() ?>
            <?php if (in_array($other, $channels, true)): ?>
                <?= Html::beginForm(['phone-sign-in/resend'], 'post', ['id' => 'resend-other-form']) ?>
                    <?= Html::hiddenInput('channel', $other) ?>
                    <?= Html::submitButton('Send by ' . PhoneSignInController::channelName($other) . ' instead', ['class' => 'btn btn-outline-secondary btn-sm']) ?>
                <?= Html::endForm() ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex justify-content-between mt-5">
        <?= Html::a('Use another number', ['phone-sign-in/index'], ['class' => 'link-secondary text-decoration-none']) ?>
        <?= Html::a('Sign in with email', ['site/login'], ['class' => 'link-secondary text-decoration-none']) ?>
    </div>
</div>
<?php
$this->registerJs(<<<'JS'
(function () {
    var box = document.getElementById('resend');
    var left = parseInt(box.getAttribute('data-wait'), 10) || 0;
    if (left <= 0) { return; }
    var seconds = document.getElementById('resend-seconds');
    var timer = setInterval(function () {
        left -= 1;
        seconds.textContent = left;
        if (left <= 0) {
            clearInterval(timer);
            document.getElementById('resend-wait').hidden = true;
            document.getElementById('resend-buttons').hidden = false;
        }
    }, 1000);
})();
JS);
?>
