<?php

use frontend\models\PatientCredit;
use yii\bootstrap5\Html;
use yii\grid\GridView;

/**
 * F23: refund requests for rebooking credits.
 *
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var string $status
 * @var bool $b2c refunds are sent by M-Pesa B2C
 */

$this->title = 'Refunds';
$f = Yii::$app->formatter;
?>
<div class="refund-index py-4">
    <h1 class="h3"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        Patients ask for a credit back when the clinic cancelled, or when their payment arrived after the time was taken.
        <?= $b2c ? 'Approving sends it by M-Pesa B2C.' : 'B2C is off (MPESA_B2C_PAYOUTS_ENABLED=0): approve, pay by M-Pesa by hand, then record the reference.' ?>
    </p>
    <ul class="nav nav-pills mb-3">
        <?php foreach ([PatientCredit::STATUS_REFUND_REQUESTED => 'To decide', PatientCredit::STATUS_REFUNDED => 'Refunded', PatientCredit::STATUS_REFUND_REJECTED => 'Declined', '' => 'All'] as $value => $label): ?>
            <li class="nav-item"><?= Html::a($label, ['index', 'status' => $value], ['class' => 'nav-link' . ($status === $value ? ' active' : '')]) ?></li>
        <?php endforeach; ?>
    </ul>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'tableOptions' => ['class' => 'table table-sm align-middle'],
        'emptyText' => 'Nothing here.',
        'columns' => [
            ['attribute' => 'id', 'label' => '#'],
            [
                'label' => 'Patient',
                'value' => function (PatientCredit $c) {
                    return $c->patient ? ($c->patient->full_name ?: $c->patient->username) : "#{$c->patient_id}";
                },
            ],
            [
                'label' => 'Why',
                'format' => 'raw',
                'value' => function (PatientCredit $c) {
                    $appointment = $c->sourceFee->appointment ?? null;
                    return Html::encode(PatientCredit::reasonLabels()[$c->reason] ?? $c->reason)
                        . ($appointment ? '<div class="small text-muted">' . Html::encode(($appointment->consultant->names ?? '') . ', ' . $appointment->date . ' ' . substr((string) $appointment->time, 0, 5)) . '</div>' : '');
                },
            ],
            ['label' => 'Amount', 'value' => function (PatientCredit $c) { return 'Ksh ' . number_format($c->amount); }],
            ['attribute' => 'refund_phone', 'label' => 'To'],
            ['label' => 'Asked', 'value' => function (PatientCredit $c) use ($f) { return $c->refund_requested_at ? $f->asDatetime($c->refund_requested_at, 'php:j M H:i') : ''; }],
            [
                'label' => 'Status',
                'format' => 'raw',
                'value' => function (PatientCredit $c) {
                    return Html::encode(PatientCredit::statusLabels()[$c->status] ?? $c->status)
                        . ($c->refund_reference ? ' <code>' . Html::encode($c->refund_reference) . '</code>' : '')
                        . ($c->note ? '<div class="small text-muted">' . Html::encode($c->note) . '</div>' : '');
                },
            ],
            [
                'label' => '',
                'format' => 'raw',
                'contentOptions' => ['style' => 'min-width: 22rem'],
                'value' => function (PatientCredit $c) {
                    if ($c->status !== PatientCredit::STATUS_REFUND_REQUESTED) {
                        return '';
                    }
                    $html = '';
                    if (!$c->refund_decided_at) {
                        $html .= Html::beginForm(['approve', 'id' => $c->id], 'post', ['class' => 'd-inline'])
                            . Html::submitButton('Approve', ['class' => 'btn btn-sm btn-success me-1', 'data-confirm' => "Refund Ksh {$c->amount} to {$c->refund_phone}?"])
                            . Html::endForm();
                    }
                    $html .= Html::beginForm(['record', 'id' => $c->id], 'post', ['class' => 'd-inline-flex gap-1 my-1'])
                        . Html::textInput('reference', '', ['class' => 'form-control form-control-sm', 'placeholder' => 'M-Pesa ref', 'style' => 'width: 8rem'])
                        . Html::submitButton('Record paid', ['class' => 'btn btn-sm btn-outline-primary'])
                        . Html::endForm();
                    $html .= Html::beginForm(['reject', 'id' => $c->id], 'post', ['class' => 'd-flex gap-1'])
                        . Html::textInput('reason', '', ['class' => 'form-control form-control-sm', 'placeholder' => 'Why declined'])
                        . Html::submitButton('Decline', ['class' => 'btn btn-sm btn-outline-danger'])
                        . Html::endForm();

                    return $html;
                },
            ],
        ],
    ]) ?>
</div>
