<?php

use frontend\models\FrontdeskIncentive;
use frontend\models\IncentivePayout;
use yii\bootstrap5\Html;

/**
 * F24: the bookings one payout pays for.
 *
 * @var yii\web\View $this
 * @var IncentivePayout $payout
 * @var FrontdeskIncentive[] $items
 */

$name = $payout->frontDesk ? ($payout->frontDesk->full_name ?: $payout->frontDesk->username) : "#{$payout->front_desk_id}";
$this->title = 'Payout #' . $payout->id;
$this->params['breadcrumbs'][] = ['label' => 'Weekly payouts', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="payout-view py-4">
    <h1 class="h3"><?= Html::encode($name) ?>: Ksh <?= number_format($payout->amount) ?></h1>
    <p class="text-muted">
        <?= Html::encode(date('j M', strtotime($payout->period_start)) . ' – ' . date('j M Y', strtotime($payout->period_end))) ?> ·
        <?= Html::encode(IncentivePayout::statusLabels()[$payout->status] ?? $payout->status) ?>
        <?= $payout->reference ? '· <code>' . Html::encode($payout->reference) . '</code>' : '' ?>
    </p>
    <table class="table table-sm">
        <thead><tr><th>Appointment</th><th>Consultant</th><th>Date</th><th>Amount</th><th>State</th></tr></thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>#<?= (int) $item->appointment_id ?></td>
                    <td><?= Html::encode($item->appointment->consultant->names ?? '') ?></td>
                    <td><?= Html::encode($item->appointment ? $item->appointment->date . ' ' . substr((string) $item->appointment->time, 0, 5) : '') ?></td>
                    <td>Ksh <?= number_format($item->amount) ?></td>
                    <td><?= Html::encode(FrontdeskIncentive::stateLabels()[$item->state] ?? $item->state) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
