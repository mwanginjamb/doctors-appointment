<?php

use common\models\Consent;
use yii\bootstrap5\Html;

/**
 * "Privacy & messages": the patient's reminder choice, what they agreed to, and withdrawing consent.
 *
 * @var yii\web\View $this
 * @var bool $remindersOn
 * @var bool $remindersAnswered
 * @var Consent[] $history
 * @var common\models\AccountClosureRequest|null $closure
 */

$this->title = 'Privacy & messages';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="consent-settings container my-4" style="max-width: 44rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p><?= Html::a('Read the privacy notice', ['/site/privacy']) ?></p>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 fw-bold">WhatsApp/SMS reminders</h2>
            <p id="reminders-status">
                <?php if ($remindersOn): ?>
                    <strong>On.</strong> You get reminders and notices by WhatsApp/SMS as well as email.
                <?php else: ?>
                    <strong>Off.</strong> You get reminders and notices by email only.
                <?php endif; ?>
            </p>
            <?= Html::beginForm(['reminders'], 'post', ['id' => 'reminders-form']) ?>
            <?= Html::hiddenInput('reminders', $remindersOn ? '0' : '1') ?>
            <?= Html::submitButton($remindersOn ? 'Turn WhatsApp/SMS reminders off' : 'Turn WhatsApp/SMS reminders on', [
                'class' => 'btn btn-outline-primary btn-sm',
            ]) ?>
            <?= Html::endForm() ?>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 fw-bold">What you agreed to</h2>
            <?php if (!$history): ?>
                <p class="text-muted mb-0">Nothing recorded yet.</p>
            <?php else: ?>
                <ul class="list-unstyled mb-0" id="consent-history">
                    <?php foreach ($history as $consent): ?>
                        <li class="small py-1 border-bottom">
                            <?= date('j M Y H:i', $consent->created_at) ?> ·
                            <?= Html::encode(Consent::purposes()[$consent->purpose] ?? $consent->purpose) ?>:
                            <strong><?= $consent->granted ? 'agreed' : 'withdrawn' ?></strong>
                            <?= Html::encode(Consent::methods()[$consent->method] ?? $consent->method) ?>
                            <span class="text-muted">(notice <?= Html::encode($consent->notice_version) ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm border-danger-subtle">
        <div class="card-body">
            <h2 class="h5 fw-bold">Withdraw consent and close my account</h2>
            <?php if ($closure): ?>
                <p class="mb-0">You asked us to close your account on <?= date('j F Y', $closure->created_at) ?>. The clinic will contact you.</p>
            <?php else: ?>
                <p>
                    The clinic will stop using your information for new appointments and will close your account.
                    Your upcoming appointments are cancelled and your doctor is told. Records the law requires us to
                    keep are kept for that period.
                </p>
                <?= Html::beginForm(['withdraw'], 'post', ['id' => 'withdraw-form']) ?>
                <label class="form-label" for="withdraw-reason">Reason (optional)</label>
                <textarea id="withdraw-reason" name="reason" class="form-control mb-3" rows="2" maxlength="500"></textarea>
                <?= Html::submitButton('Withdraw consent and close my account', [
                    'class' => 'btn btn-outline-danger',
                    'data' => ['confirm' => 'Withdraw your consent? Upcoming appointments will be cancelled and you will not be able to book.'],
                ]) ?>
                <?= Html::endForm() ?>
            <?php endif; ?>
        </div>
    </div>
</div>
