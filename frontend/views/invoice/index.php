<?php

use frontend\models\ConsultantInvoice;
use yii\bootstrap5\Html;

/**
 * F25: a consultant's invoices.
 *
 * @var yii\web\View $this
 * @var ConsultantInvoice[] $invoices
 * @var bool $paused F25 part 2: patients cannot book them online until an overdue invoice is paid
 */

$this->title = Yii::t('app', 'My invoices');
?>
<div class="invoice-index container my-4" style="max-width: 48rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <?php if ($paused): ?>
        <div class="alert alert-warning" id="booking-paused">
            <?= Yii::t('app', 'Patients cannot book you online until your overdue invoice is paid. Your front desk and booking links keep working.') ?>
        </div>
    <?php endif; ?>
    <p class="text-muted"><?= Yii::t('app', 'One a month, for all your facilities: the commitment fees you absorbed for insured patients and, after your pilot, your plan and any cancellation charges.') ?></p>
    <table class="table align-middle" id="invoices">
        <thead><tr><th><?= Yii::t('app', 'Month') ?></th><th><?= Yii::t('app', 'Number') ?></th><th class="text-end">Ksh</th><th><?= Yii::t('app', 'Status') ?></th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $invoice): ?>
            <tr>
                <td><?= Html::encode($invoice->getPeriodLabel()) ?></td>
                <td><?= Html::a(Html::encode($invoice->number), $invoice->url()) ?></td>
                <td class="text-end"><?= number_format($invoice->amount) ?></td>
                <td>
                    <?= Html::encode(ConsultantInvoice::statusLabels()[$invoice->status] ?? $invoice->status) ?>
                    <?php if ($invoice->isOpen()): ?>
                        <span class="small <?= $invoice->isOverdue() ? 'text-danger' : 'text-muted' ?>">· <?= Yii::t('app', 'due {date}', ['date' => Yii::$app->formatter->asDate($invoice->due_on, 'php:j M Y')]) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$invoices): ?>
            <tr><td colspan="4" class="text-muted"><?= Yii::t('app', 'No invoices yet.') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
