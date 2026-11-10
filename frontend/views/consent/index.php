<?php

use frontend\models\ConsentForm;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/**
 * The consent page a signed-in patient is held on until they agree to the current privacy notice.
 *
 * @var yii\web\View $this
 * @var ConsentForm $form
 * @var string $status Consent::status()
 * @var common\models\AccountClosureRequest|null $closure
 */

$this->title = 'Your privacy';
?>
<div class="consent-index container my-4" style="max-width: 44rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>

    <?php if ($closure): ?>
        <div class="alert alert-warning" id="closure-pending">
            You withdrew your consent on <?= date('j F Y', $closure->created_at) ?> and asked us to close your
            account. The clinic will contact you. If you have changed your mind, agree below and your request
            is cancelled.
        </div>
    <?php elseif ($status === 'old_notice'): ?>
        <p class="lead">We have updated our privacy notice. Please read it and confirm your choices to continue.</p>
    <?php else: ?>
        <p class="lead">Before you book, please read how we use your information and confirm your choices.</p>
    <?php endif; ?>

    <p>
        <?= Html::a('Read the privacy notice', ['/site/privacy'], ['target' => '_blank', 'class' => 'fw-semibold']) ?>
        (opens in a new tab). It explains what we collect, why, who sees it, how long it is kept and your rights.
    </p>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php $f = ActiveForm::begin(['id' => 'consent-form']); ?>
            <?= $f->field($form, 'data')->checkbox() ?>
            <?= $f->field($form, 'reminders')->radioList(ConsentForm::reminderChoices())
                ->hint('Reminders and notices about your appointments. Emails are sent either way; you can change this later.') ?>
            <?= Html::submitButton('Continue', ['class' => 'btn btn-primary']) ?>
            <?php ActiveForm::end(); ?>
        </div>
    </div>

    <p class="small text-muted mt-4">
        If you do not agree, you cannot book through this service.
        <?php if (!$closure): ?>
            You can sign out, or ask us to close your account:
        <?php endif; ?>
    </p>
    <div class="d-flex gap-2">
        <?= Html::beginForm(['/site/logout'], 'post') ?>
        <?= Html::submitButton('Sign out', ['class' => 'btn btn-outline-secondary btn-sm']) ?>
        <?= Html::endForm() ?>
        <?php if (!$closure): ?>
            <?= Html::beginForm(['withdraw'], 'post', ['id' => 'decline-form']) ?>
            <?= Html::submitButton('Close my account', [
                'class' => 'btn btn-outline-danger btn-sm',
                'data' => ['confirm' => 'Ask the clinic to close your account? Upcoming appointments will be cancelled.'],
            ]) ?>
            <?= Html::endForm() ?>
        <?php endif; ?>
    </div>
</div>
