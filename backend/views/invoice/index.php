<?php

use frontend\models\ConsultantInvoice;
use yii\bootstrap5\Html;
use yii\grid\GridView;

/**
 * F25: consultants' monthly invoices.
 *
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var string $status issued, paid, void, overdue or ''
 * @var string $month Y-m or ''
 * @var bool $enabled INVOICES_ENABLED
 */

$this->title = 'Invoices';
?>
<div class="invoice-index py-4">
    <div class="d-flex justify-content-between align-items-start">
        <h1 class="h3"><?= Html::encode($this->title) ?></h1>
        <?= Html::beginForm(['issue'], 'post')
            . Html::submitButton('Issue last month\'s now', ['class' => 'btn btn-sm btn-outline-secondary', 'data-confirm' => 'Issue and send last month\'s invoices now? Consultants already invoiced for it are skipped.'])
            . Html::endForm() ?>
    </div>
    <p class="text-muted">
        One a month per consultant, issued on the 1st and due 14 days later: absorbed commitment fees, the plan charge once on
        standard terms (prorated in the month they start), and Ksh 100 cancellation charges.
        <?= $enabled ? '' : '<strong>Invoices are off (INVOICES_ENABLED=0):</strong> nothing is charged or issued.' ?>
    </p>

    <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
        <ul class="nav nav-pills">
            <?php foreach (['' => 'All', ConsultantInvoice::STATUS_ISSUED => 'Unpaid', 'overdue' => 'Overdue', ConsultantInvoice::STATUS_PAID => 'Paid', ConsultantInvoice::STATUS_VOID => 'Void'] as $value => $label): ?>
                <li class="nav-item"><?= Html::a($label, ['index', 'status' => $value, 'month' => $month], ['class' => 'nav-link py-1' . ((string) $status === (string) $value ? ' active' : '')]) ?></li>
            <?php endforeach; ?>
        </ul>
        <?= Html::beginForm(['index'], 'get', ['class' => 'd-flex gap-2'])
            . Html::hiddenInput('status', $status)
            . Html::input('month', 'month', $month, ['class' => 'form-control form-control-sm', 'aria-label' => 'Month'])
            . Html::submitButton('Show', ['class' => 'btn btn-sm btn-outline-primary'])
            . Html::endForm() ?>
    </div>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'tableOptions' => ['class' => 'table table-sm align-middle'],
        'emptyText' => 'No invoices.',
        'columns' => [
            ['label' => 'Number', 'format' => 'raw', 'value' => function (ConsultantInvoice $i) { return Html::a(Html::encode($i->number), ['view', 'id' => $i->id]); }],
            ['label' => 'Consultant', 'value' => function (ConsultantInvoice $i) { return $i->consultant->names ?? "#{$i->consultant_id}"; }],
            ['label' => 'Month', 'value' => function (ConsultantInvoice $i) { return $i->getPeriodLabel(); }],
            ['label' => 'Kind', 'value' => function (ConsultantInvoice $i) { return $i->isStatement() ? 'Pilot statement' : 'Invoice'; }],
            ['label' => 'Ksh', 'value' => function (ConsultantInvoice $i) { return number_format($i->amount); }],
            [
                'label' => 'Status',
                'format' => 'raw',
                'value' => function (ConsultantInvoice $i) {
                    return Html::encode(ConsultantInvoice::statusLabels()[$i->status] ?? $i->status)
                        . ($i->isOpen() ? ' <span class="small ' . ($i->isOverdue() ? 'text-danger' : 'text-muted') . '">due ' . Html::encode(date('j M', strtotime($i->due_on))) . '</span>' : '')
                        . ($i->reference ? ' <code>' . Html::encode($i->reference) . '</code>' : '');
                },
            ],
        ],
    ]) ?>
</div>
