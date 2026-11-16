<?php

use frontend\models\PatientCredit;
use yii\bootstrap5\Html;

/**
 * F23: a patient's rebooking credit, and the refund request where the clinic cancelled.
 *
 * @var yii\web\View $this
 * @var PatientCredit $credit
 * @var string $sig
 */

$this->title = Yii::t('app', 'Your booking credit');
$f = Yii::$app->formatter;
$amount = number_format($credit->amount);
?>
<div class="credit-view container my-4" style="max-width: 40rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>

    <div class="card border-0 shadow-sm my-3">
        <div class="card-body">
            <p class="fs-5 mb-1">Ksh <?= $amount ?></p>
            <p class="text-muted mb-2"><?= Html::encode(PatientCredit::reasonLabels()[$credit->reason] ?? '') ?></p>
            <p class="mb-0" id="credit-status">
                <strong><?= Html::encode(PatientCredit::statusLabels()[$credit->status] ?? $credit->status) ?></strong>
                <?php if ($credit->isUsable()): ?>
                    · <?= Html::encode(Yii::t('app', 'Your next booking with any doctor uses it instead of a new payment, until {date}.', ['date' => $f->asDate($credit->expires_at, 'long')])) ?>
                <?php elseif ($credit->status === PatientCredit::STATUS_REFUND_REQUESTED): ?>
                    · <?= Yii::t('app', 'The refund is waiting for approval.') ?>
                <?php elseif ($credit->status === PatientCredit::STATUS_REFUNDED): ?>
                    · <?= Html::encode(Yii::t('app', 'Refunded by M-Pesa ({reference}).', ['reference' => $credit->refund_reference])) ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <?php if ($credit->canRequestRefund()): ?>
        <h2 class="h5 mt-4"><?= Yii::t('app', 'Get it back instead') ?></h2>
        <p><?= Html::encode(Yii::t('app', 'Because the clinic cancelled, you can have the Ksh {amount} sent back to you by M-Pesa instead of keeping it as a credit.', ['amount' => $amount])) ?></p>
        <?= Html::beginForm(['/credit/refund', 'id' => $credit->id, 'sig' => $sig], 'post', ['id' => 'credit-refund-form']) ?>
        <div class="mb-3" style="max-width: 20rem">
            <label class="form-label" for="credit-refund-phone"><?= Yii::t('app', 'M-Pesa number to refund to') ?></label>
            <input type="tel" class="form-control" id="credit-refund-phone" name="phone" maxlength="20" placeholder="<?= Yii::t('app', 'Leave empty for your own number') ?>">
        </div>
        <button type="submit" class="btn btn-outline-primary"><?= Yii::t('app', 'Refund to M-Pesa') ?></button>
        <?= Html::endForm() ?>
    <?php endif; ?>
</div>
