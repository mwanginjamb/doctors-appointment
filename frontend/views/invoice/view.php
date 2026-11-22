<?php

use frontend\models\ConsultantInvoice;
use frontend\models\ConsultantInvoiceLine;
use yii\bootstrap5\Html;

/**
 * F25: a consultant's monthly invoice.
 *
 * @var yii\web\View $this
 * @var ConsultantInvoice $invoice
 * @var ConsultantInvoiceLine[] $lines waived ones included
 */

$kind = $invoice->isStatement() ? Yii::t('app', 'Statement') : Yii::t('app', 'Invoice');
$this->title = $kind . ' ' . $invoice->number;
$f = Yii::$app->formatter;
?>
<div class="invoice-view container my-4" style="max-width: 48rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        <?= Html::encode($invoice->consultant->names ?? '') ?> · <?= Html::encode($invoice->getPeriodLabel()) ?>
        · <?= Yii::t('app', 'issued {date}', ['date' => $f->asDate($invoice->issued_at, 'php:j M Y')]) ?>
    </p>
    <?php if ($invoice->isStatement()): ?>
        <p class="small"><?= Yii::t('app', 'During your pilot this is a statement of the commitment fees you chose to absorb for insured patients. No plan charge applies yet.') ?></p>
    <?php endif; ?>

    <table class="table align-middle" id="invoice-lines">
        <thead><tr><th><?= Yii::t('app', 'Date') ?></th><th><?= Yii::t('app', 'Charge') ?></th><th class="text-end">Ksh</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $line): ?>
            <tr class="<?= $line->waived ? 'text-muted text-decoration-line-through' : '' ?>">
                <td class="text-nowrap small"><?= Html::encode(date('j M', strtotime($line->line_date))) ?></td>
                <td>
                    <div class="small text-muted"><?= Html::encode(ConsultantInvoiceLine::typeLabels()[$line->type] ?? $line->type) ?></div>
                    <?= Html::encode($line->description) ?>
                    <?= $line->waived ? '<div class="small">' . Html::encode(Yii::t('app', 'Waived: {reason}', ['reason' => $line->waive_reason])) . '</div>' : '' ?>
                </td>
                <td class="text-end"><?= number_format($line->amount) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="fw-bold"><td></td><td><?= Yii::t('app', 'Total') ?></td><td class="text-end" id="invoice-total"><?= number_format($invoice->amount) ?></td></tr>
        </tfoot>
    </table>

    <div class="card border-0 shadow-sm" id="invoice-status">
        <div class="card-body">
            <?php if ($invoice->status === ConsultantInvoice::STATUS_PAID): ?>
                <strong class="text-success"><?= Yii::t('app', 'Paid') ?></strong>
                <?= Html::encode(Yii::t('app', 'on {date} ({reference}). Thank you.', ['date' => $f->asDate($invoice->paid_at, 'php:j M Y'), 'reference' => $invoice->reference])) ?>
            <?php elseif ($invoice->status === ConsultantInvoice::STATUS_VOID): ?>
                <strong><?= Yii::t('app', 'Cancelled') ?></strong>: <?= Html::encode($invoice->void_reason) ?>. <?= Yii::t('app', 'Nothing is due.') ?>
            <?php else: ?>
                <strong><?= Html::encode(Yii::t('app', 'Ksh {amount} due by {date}', ['amount' => number_format($invoice->amount), 'date' => $f->asDate($invoice->due_on, 'php:j M Y')])) ?></strong>
                <p class="small text-muted mb-0 mt-1"><?= Yii::t('app', 'Pay by M-Pesa as arranged with the platform, quoting {number}; your payment is recorded here once received.', ['number' => Html::encode($invoice->number)]) ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
