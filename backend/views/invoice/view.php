<?php

use frontend\models\ConsultantInvoice;
use frontend\models\ConsultantInvoiceLine;
use yii\bootstrap5\Html;

/**
 * F25: one consultant invoice, its lines, and what a platform admin can do with it.
 *
 * @var yii\web\View $this
 * @var ConsultantInvoice $invoice
 * @var ConsultantInvoiceLine[] $lines
 */

$this->title = $invoice->number;
$this->params['breadcrumbs'][] = ['label' => 'Invoices', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$f = Yii::$app->formatter;
?>
<div class="invoice-view py-4">
    <h1 class="h3"><?= Html::encode($invoice->number) ?>: Ksh <span id="invoice-amount"><?= number_format($invoice->amount) ?></span></h1>
    <p class="text-muted">
        <?= Html::encode($invoice->consultant->names ?? '') ?> · <?= Html::encode($invoice->getPeriodLabel()) ?>
        · <?= $invoice->isStatement() ? 'pilot statement (reminders only, never pauses booking)' : 'invoice' ?>
        · issued <?= $f->asDate($invoice->issued_at, 'php:j M Y') ?>, due <?= Html::encode(date('j M Y', strtotime($invoice->due_on))) ?>
    </p>
    <p id="invoice-state">
        <strong><?= Html::encode(ConsultantInvoice::statusLabels()[$invoice->status] ?? $invoice->status) ?></strong>
        <?php if ($invoice->status === ConsultantInvoice::STATUS_PAID): ?>
            <?= $f->asDate($invoice->paid_at, 'php:j M Y') ?> · <code><?= Html::encode($invoice->reference) ?></code>
        <?php elseif ($invoice->status === ConsultantInvoice::STATUS_VOID): ?>
            · <?= Html::encode($invoice->void_reason) ?>
        <?php elseif ($invoice->isOverdue()): ?>
            <span class="text-danger">· overdue</span>
        <?php endif; ?>
        <?php if ($invoice->isOpen()): ?>
            · reminders sent: <?= (int) $invoice->reminders_sent ?>
            <?php if (($pausesOn = \frontend\services\Invoices::pausesOn($invoice)) !== null): ?>
                · <?= $pausesOn <= date('Y-m-d') ? 'pauses online booking' : 'pauses online booking from ' . Html::encode(date('j M Y', strtotime($pausesOn))) ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($invoice->consultant && $invoice->consultant->isOnlineBookingPaused()): ?>
            <span class="badge text-bg-warning" id="consultant-paused">online booking paused since <?= $f->asDate($invoice->consultant->online_booking_paused_at, 'php:j M Y') ?></span>
        <?php endif; ?>
        · <?= Html::a('The consultant\'s page ↗', \common\helpers\AdminLinks::mainSite(ltrim($invoice->url(), '/')), ['class' => 'small']) ?>
    </p>

    <table class="table table-sm align-middle" id="lines">
        <thead><tr><th>Date</th><th>Charge</th><th>Facility</th><th class="text-end">Ksh</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lines as $line): ?>
            <tr id="line-<?= (int) $line->id ?>" class="<?= $line->waived ? 'text-muted' : '' ?>">
                <td class="text-nowrap"><?= Html::encode(date('j M', strtotime($line->line_date))) ?></td>
                <td>
                    <div class="small text-muted"><?= Html::encode(ConsultantInvoiceLine::typeLabels()[$line->type] ?? $line->type) ?></div>
                    <span class="<?= $line->waived ? 'text-decoration-line-through' : '' ?>"><?= Html::encode($line->description) ?></span>
                    <?= $line->waived ? '<div class="small">Waived: ' . Html::encode($line->waive_reason) . '</div>' : '' ?>
                </td>
                <td class="small"><?= Html::encode($line->facility->name ?? '—') ?></td>
                <td class="text-end"><?= number_format($line->amount) ?></td>
                <td style="min-width: 16rem">
                    <?php if ($invoice->isOpen() && !$line->waived): ?>
                        <?= Html::beginForm(['waive', 'id' => $line->id], 'post', ['class' => 'd-flex gap-1'])
                            . Html::textInput('reason', '', ['class' => 'form-control form-control-sm', 'placeholder' => 'Why waived'])
                            . Html::submitButton('Waive', ['class' => 'btn btn-sm btn-outline-secondary'])
                            . Html::endForm() ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($invoice->isOpen()): ?>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card"><div class="card-body">
                    <h2 class="h6">Resend the invoice email</h2>
                    <?php if ($invoice->pay_note): ?>
                        <p class="small text-danger mb-2" id="pay-note">Last M-Pesa prompt: <?= Html::encode($invoice->pay_note) ?></p>
                    <?php endif; ?>
                    <?= Html::beginForm(['resend', 'id' => $invoice->id], 'post', ['id' => 'resend-form'])
                        . Html::submitButton('Resend the invoice email', ['class' => 'btn btn-sm btn-success'])
                        . Html::endForm() ?>
                    <p class="small text-muted mt-2 mb-0">The consultant gets the email again with its Pay via M-Pesa button, and their front desk a reminder to have them pay. Once they enter their PIN, the invoice marks itself paid with the receipt.</p>
                </div></div>
            </div>
            <div class="col-md-6">
                <div class="card"><div class="card-body">
                    <h2 class="h6">Record a payment</h2>
                    <?= Html::beginForm(['record', 'id' => $invoice->id], 'post', ['class' => 'd-flex gap-2', 'id' => 'record-form'])
                        . Html::textInput('reference', '', ['class' => 'form-control form-control-sm', 'placeholder' => 'M-Pesa or bank reference'])
                        . Html::submitButton('Record paid', ['class' => 'btn btn-sm btn-primary'])
                        . Html::endForm() ?>
                </div></div>
            </div>
            <div class="col-md-6">
                <div class="card border-danger-subtle"><div class="card-body">
                    <h2 class="h6">Void</h2>
                    <p class="small text-muted">For an invoice made in error: every line is waived and nothing is due. To correct one charge, waive that line instead.</p>
                    <?= Html::beginForm(['void', 'id' => $invoice->id], 'post', ['class' => 'd-flex gap-2', 'id' => 'void-form'])
                        . Html::textInput('reason', '', ['class' => 'form-control form-control-sm', 'placeholder' => 'Why'])
                        . Html::submitButton('Void', ['class' => 'btn btn-sm btn-outline-danger', 'data-confirm' => "Void {$invoice->number}?"])
                        . Html::endForm() ?>
                </div></div>
            </div>
        </div>
    <?php endif; ?>
</div>
