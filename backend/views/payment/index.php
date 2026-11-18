<?php

use common\helpers\Contact;
use frontend\models\CommitmentFee;
use yii\bootstrap5\Html;
use yii\grid\GridView;

/**
 * F28: commitment fees (F22, F23), read-only.
 *
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var array[] $totals state, n, ksh
 * @var array $filters state, facility, consultant, from, to
 * @var string[] $facilities id => name
 * @var string[] $consultants user id => name
 * @var bool $feesOn
 */

$this->title = 'Payments';
$f = Yii::$app->formatter;
$labels = CommitmentFee::stateLabels();
?>
<div class="payment-index py-4">
    <h1 class="h3"><?= Html::encode($this->title) ?></h1>
    <?= $this->render('_tabs', ['active' => 'index']) ?>
    <p class="text-muted">
        Every commitment fee, newest first: who paid it (the patient by M-Pesa, or the consultant absorbing it for an insured patient) and what became of it.
        <?= $feesOn ? '' : 'Commitment fees are off (COMMITMENT_FEE_ENABLED=0).' ?>
    </p>

    <?= Html::beginForm(['index'], 'get', ['class' => 'row g-2 align-items-end mb-3']) ?>
        <div class="col-sm-6 col-lg-2">
            <label class="form-label small mb-0" for="f-state">State</label>
            <?= Html::dropDownList('state', $filters['state'], $labels, ['prompt' => 'Any', 'class' => 'form-select form-select-sm', 'id' => 'f-state']) ?>
        </div>
        <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-0" for="f-facility">Facility</label>
            <?= Html::dropDownList('facility', $filters['facility'], $facilities, ['prompt' => 'Any', 'class' => 'form-select form-select-sm', 'id' => 'f-facility']) ?>
        </div>
        <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-0" for="f-consultant">Consultant</label>
            <?= Html::dropDownList('consultant', $filters['consultant'], $consultants, ['prompt' => 'Any', 'class' => 'form-select form-select-sm', 'id' => 'f-consultant']) ?>
        </div>
        <div class="col-6 col-lg-1">
            <label class="form-label small mb-0" for="f-from">From</label>
            <?= Html::input('date', 'from', $filters['from'], ['class' => 'form-control form-control-sm', 'id' => 'f-from']) ?>
        </div>
        <div class="col-6 col-lg-1">
            <label class="form-label small mb-0" for="f-to">To</label>
            <?= Html::input('date', 'to', $filters['to'], ['class' => 'form-control form-control-sm', 'id' => 'f-to']) ?>
        </div>
        <div class="col-lg-2 d-flex gap-2">
            <?= Html::submitButton('Filter', ['class' => 'btn btn-sm btn-primary']) ?>
            <?= Html::a('Clear', ['index'], ['class' => 'btn btn-sm btn-link']) ?>
        </div>
    <?= Html::endForm() ?>

    <?php if ($totals): ?>
        <div class="d-flex flex-wrap gap-3 mb-3 small" id="totals">
            <?php foreach ($totals as $row): ?>
                <span class="border rounded px-2 py-1"><?= Html::encode($labels[$row['state']] ?? $row['state']) ?>: <?= (int) $row['n'] ?> · Ksh <?= number_format((int) $row['ksh']) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'tableOptions' => ['class' => 'table table-sm align-middle'],
        'emptyText' => 'No commitment fees.',
        'columns' => [
            ['attribute' => 'id', 'label' => '#'],
            ['label' => 'Date', 'value' => function (CommitmentFee $fee) {
                return $fee->fee_date . ($fee->appointment ? ' ' . substr((string) $fee->appointment->time, 0, 5) : '');
            }],
            [
                'label' => 'Patient',
                'format' => 'raw',
                'value' => function (CommitmentFee $fee) {
                    $patient = $fee->patient;
                    return Html::encode($patient ? ($patient->full_name ?: $patient->username) : "#{$fee->patient_id}")
                        . ($fee->phone ? '<div class="small text-muted">' . Html::encode(Contact::phone($fee->phone)) . '</div>' : '');
                },
            ],
            ['label' => 'Consultant', 'value' => function (CommitmentFee $fee) { return $fee->consultant->names ?? "#{$fee->consultant_id}"; }],
            ['label' => 'Facility', 'value' => function (CommitmentFee $fee) { return $fee->facility->name ?? ''; }],
            ['label' => 'Ksh', 'value' => function (CommitmentFee $fee) { return number_format($fee->amount); }],
            ['label' => 'Paid by', 'value' => function (CommitmentFee $fee) { return $fee->payer === CommitmentFee::PAYER_CONSULTANT ? 'Consultant (absorbed)' : 'Patient'; }],
            [
                'label' => 'State',
                'format' => 'raw',
                'value' => function (CommitmentFee $fee) use ($labels) {
                    return Html::encode($labels[$fee->state] ?? $fee->state)
                        . ($fee->note ? '<div class="small text-muted">' . Html::encode($fee->note) . '</div>' : '');
                },
            ],
            [
                'label' => 'M-Pesa',
                'format' => 'raw',
                'value' => function (CommitmentFee $fee) use ($f) {
                    $receipt = $fee->transaction->receipt ?? null;
                    return ($receipt ? '<code>' . Html::encode($receipt) . '</code>' : '')
                        . ($fee->paid_at ? '<div class="small text-muted">' . $f->asDatetime($fee->paid_at, 'php:j M H:i') . '</div>' : '');
                },
            ],
        ],
    ]) ?>
</div>
