<?php

use common\components\Mpesa;
use frontend\models\ConsultantInvoice;
use yii\bootstrap5\Html;

/**
 * F25: the "Pay via M-Pesa" page, the button in the invoice emails. Opening it sends nothing; one
 * tap sends the M-Pesa prompt to the consultant's number (or another), then it follows the payment.
 *
 * @var yii\web\View $this
 * @var ConsultantInvoice $invoice open, something due, M-Pesa set up
 * @var array $state Invoices::statusOf()
 */

$kind = $invoice->isStatement() ? Yii::t('app', 'statement') : Yii::t('app', 'invoice');
$this->title = Yii::t('app', 'Pay {number}', ['number' => $invoice->number]);
$consultant = $invoice->consultant;
$masked = null;
try {
    $n = Mpesa::normalisePhone((string) ($consultant->consultant_phone_number ?? ''));
    $masked = '0' . substr($n, 3, 3) . ' *** ' . substr($n, -3);
} catch (\InvalidArgumentException $e) {
    // no usable number on file: the number field is shown open
}
?>
<div class="invoice-pay container my-4" style="max-width: 32rem">
    <h1 class="h3 fw-bold"><?= Html::encode(Yii::t('app', 'Pay via M-Pesa')) ?></h1>
    <p class="text-muted mb-3">
        <?= Html::encode($consultant->names ?? '') ?> · <?= Html::encode(ucfirst($kind) . ' ' . $invoice->number) ?>
        · <?= Html::encode($invoice->getPeriodLabel()) ?>
    </p>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="fs-4 fw-bold" id="pay-amount">Ksh <?= number_format($invoice->amount) ?></div>
            <div class="small text-muted mb-3"><?= Html::encode(Yii::t('app', 'due by {date}', ['date' => Yii::$app->formatter->asDate($invoice->due_on, 'php:j M Y')])) ?></div>

            <div id="pay-waiting" class="alert alert-info small <?= $state['promptPending'] ? '' : 'd-none' ?>">
                <?= Yii::t('app', 'Check your phone: enter your M-Pesa PIN to pay. This page updates when the payment arrives.') ?>
            </div>
            <p id="pay-note" class="small text-danger <?= !empty($state['note']) ? '' : 'd-none' ?>"><?= Html::encode($state['note'] ?? '') ?></p>

            <?= Html::beginForm($invoice->payUrl(), 'post', ['id' => 'pay-form']) ?>
                <?php if ($masked !== null): ?>
                    <p class="mb-2" id="pay-to"><?= Html::encode(Yii::t('app', 'The prompt goes to {phone}.', ['phone' => $masked])) ?></p>
                    <details class="mb-3">
                        <summary class="small"><?= Yii::t('app', 'Use another number') ?></summary>
                        <?= Html::textInput('phone', '', ['id' => 'pay-phone', 'class' => 'form-control mt-2', 'inputmode' => 'tel',
                            'autocomplete' => 'tel', 'placeholder' => Yii::t('app', 'e.g. 0712 345 678')]) ?>
                    </details>
                <?php else: ?>
                    <label class="form-label small mb-1" for="pay-phone"><?= Yii::t('app', 'M-Pesa number') ?></label>
                    <?= Html::textInput('phone', '', ['id' => 'pay-phone', 'class' => 'form-control mb-3', 'inputmode' => 'tel',
                        'autocomplete' => 'tel', 'required' => true, 'placeholder' => Yii::t('app', 'e.g. 0712 345 678')]) ?>
                <?php endif; ?>
                <button type="submit" class="btn btn-success w-100">
                    <?= Html::encode($state['promptPending'] ? Yii::t('app', 'Send the prompt again') : Yii::t('app', 'Send the M-Pesa prompt')) ?>
                </button>
            <?= Html::endForm() ?>
        </div>
    </div>
    <p class="small mt-3"><?= Html::a(Yii::t('app', 'See each charge on the {kind}', ['kind' => $kind]), $invoice->url()) ?></p>
</div>
<?php
if ($state['promptPending']) {
    $statusUrl = json_encode($invoice->statusUrl());
    // Follow the prompt; reload once it is settled (paid: the invoice page; or why it did not go through)
    $this->registerJs(<<<JS
(function () {
    var url = $statusUrl;
    function poll() {
        fetch(url, {headers: {'Accept': 'application/json'}}).then(function (r) { return r.json(); }).then(function (s) {
            if (s.status !== 'issued' || !s.promptPending) { window.location.reload(); return; }
            setTimeout(poll, 4000);
        }).catch(function () { setTimeout(poll, 8000); });
    }
    setTimeout(poll, 4000);
})();
JS);
}
