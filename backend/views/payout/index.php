<?php

use frontend\models\IncentivePayout;
use yii\bootstrap5\Html;
use yii\grid\GridView;

/**
 * F24: the weekly front-desk payouts.
 *
 * @var yii\web\View $this
 * @var yii\data\ActiveDataProvider $dataProvider
 * @var string $status
 * @var bool $b2c payouts can be sent by M-Pesa B2C
 */

$this->title = 'Weekly payouts';
?>
<div class="payout-index py-4">
    <div class="d-flex justify-content-between align-items-start">
        <h1 class="h3"><?= Html::encode($this->title) ?></h1>
        <?= Html::beginForm(['build'], 'post') . Html::submitButton('Build last week now', ['class' => 'btn btn-sm btn-outline-secondary']) . Html::endForm() ?>
    </div>
    <p class="text-muted">
        Each front-desk user's incentives earned Monday to Sunday, built every Monday morning.
        <?= $b2c ? 'Send pays it by M-Pesa B2C.' : 'B2C is off (MPESA_B2C_PAYOUTS_ENABLED=0): pay by M-Pesa by hand, then record the reference.' ?>
    </p>
    <ul class="nav nav-pills mb-3">
        <?php foreach (['' => 'All', IncentivePayout::STATUS_READY => 'To pay', IncentivePayout::STATUS_HELD => 'Held', IncentivePayout::STATUS_PAID => 'Paid'] as $value => $label): ?>
            <li class="nav-item"><?= Html::a($label, ['index', 'status' => $value], ['class' => 'nav-link' . ($status === $value ? ' active' : '')]) ?></li>
        <?php endforeach; ?>
    </ul>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'tableOptions' => ['class' => 'table table-sm align-middle'],
        'emptyText' => 'No payouts yet.',
        'columns' => [
            ['label' => 'Week', 'value' => function (IncentivePayout $p) { return date('j M', strtotime($p->period_start)) . ' – ' . date('j M Y', strtotime($p->period_end)); }],
            [
                'label' => 'Front desk',
                'format' => 'raw',
                'value' => function (IncentivePayout $p) {
                    $name = $p->frontDesk ? ($p->frontDesk->full_name ?: $p->frontDesk->username) : "#{$p->front_desk_id}";
                    return Html::a(Html::encode($name), ['view', 'id' => $p->id]);
                },
            ],
            ['label' => 'Bookings', 'attribute' => 'incentives'],
            ['label' => 'Amount', 'value' => function (IncentivePayout $p) { return 'Ksh ' . number_format($p->amount); }],
            ['attribute' => 'phone', 'label' => 'To'],
            [
                'label' => 'Status',
                'format' => 'raw',
                'value' => function (IncentivePayout $p) {
                    return Html::encode(IncentivePayout::statusLabels()[$p->status] ?? $p->status)
                        . ($p->reference ? ' <code>' . Html::encode($p->reference) . '</code>' : '')
                        . ($p->note ? '<div class="small text-muted">' . Html::encode($p->note) . '</div>' : '');
                },
            ],
            [
                'label' => '',
                'format' => 'raw',
                'contentOptions' => ['style' => 'min-width: 18rem'],
                'value' => function (IncentivePayout $p) use ($b2c) {
                    $html = '';
                    if ($p->status === IncentivePayout::STATUS_READY && $b2c) {
                        $html .= Html::beginForm(['send', 'id' => $p->id], 'post', ['class' => 'd-inline'])
                            . Html::submitButton('Send by M-Pesa', ['class' => 'btn btn-sm btn-success me-1', 'data-confirm' => "Send Ksh {$p->amount} to {$p->phone}?"])
                            . Html::endForm();
                    }
                    if ($p->status === IncentivePayout::STATUS_HELD) {
                        $html .= Html::beginForm(['recheck', 'id' => $p->id], 'post', ['class' => 'd-inline'])
                            . Html::submitButton('Check the phone again', ['class' => 'btn btn-sm btn-outline-secondary me-1'])
                            . Html::endForm();
                    }
                    if (in_array($p->status, [IncentivePayout::STATUS_READY, IncentivePayout::STATUS_HELD], true)) {
                        $html .= Html::beginForm(['record', 'id' => $p->id], 'post', ['class' => 'd-inline-flex gap-1 my-1'])
                            . Html::textInput('reference', '', ['class' => 'form-control form-control-sm', 'placeholder' => 'M-Pesa ref', 'style' => 'width: 8rem'])
                            . Html::submitButton('Record paid', ['class' => 'btn btn-sm btn-outline-primary'])
                            . Html::endForm();
                    }

                    return $html;
                },
            ],
        ],
    ]) ?>
</div>
