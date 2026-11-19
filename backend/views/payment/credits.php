<?php

use frontend\models\PatientCredit;
use yii\bootstrap5\Html;
use yii\grid\GridView;

/**
 * F28: patients' rebooking credits (F23), read-only.
 *
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var string $status
 */

$this->title = 'Patient credits';
$this->params['breadcrumbs'][] = ['label' => 'Payments', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$f = Yii::$app->formatter;
?>
<div class="payment-credits py-4">
    <h1 class="h3">Payments</h1>
    <?= $this->render('_tabs', ['active' => 'credits']) ?>
    <p class="text-muted">A credit pays the fee of the patient's next booking within 90 days. Refundable ones (the clinic cancelled, or the payment came late) can be asked back.</p>

    <ul class="nav nav-pills mb-3">
        <?php foreach (['' => 'All'] + PatientCredit::statusLabels() as $value => $label): ?>
            <li class="nav-item"><?= Html::a(Html::encode($label), ['credits', 'status' => $value], ['class' => 'nav-link py-1' . ((string) $status === (string) $value ? ' active' : '')]) ?></li>
        <?php endforeach; ?>
    </ul>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'tableOptions' => ['class' => 'table table-sm align-middle'],
        'emptyText' => 'No credits.',
        'columns' => [
            ['attribute' => 'id', 'label' => '#'],
            ['label' => 'Patient', 'value' => function (PatientCredit $c) {
                return $c->patient ? ($c->patient->full_name ?: $c->patient->username) : "#{$c->patient_id}";
            }],
            [
                'label' => 'Why',
                'format' => 'raw',
                'value' => function (PatientCredit $c) {
                    $appointment = $c->sourceFee->appointment ?? null;
                    return Html::encode(PatientCredit::reasonLabels()[$c->reason] ?? $c->reason)
                        . ($appointment ? '<div class="small text-muted">' . Html::encode(($appointment->consultant->names ?? '') . ', ' . $appointment->date) . '</div>' : '');
                },
            ],
            ['label' => 'Ksh', 'value' => function (PatientCredit $c) { return number_format($c->amount); }],
            ['label' => 'Refundable', 'value' => function (PatientCredit $c) { return $c->refundable ? 'Yes' : 'No'; }],
            [
                'label' => 'Status',
                'format' => 'raw',
                'value' => function (PatientCredit $c) {
                    return Html::encode(PatientCredit::statusLabels()[$c->status] ?? $c->status)
                        . ($c->refund_reference ? ' <code>' . Html::encode($c->refund_reference) . '</code>' : '');
                },
            ],
            ['label' => 'Expires', 'value' => function (PatientCredit $c) use ($f) { return $c->expires_at ? $f->asDate($c->expires_at, 'php:j M Y') : ''; }],
        ],
    ]) ?>
</div>
