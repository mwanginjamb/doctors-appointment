<?php

use yii\helpers\Html;

/**
 * Headline numbers: four stat tiles, then the next 7 days per day and the busiest consultants.
 * Plain numbers and tables rather than charts: each figure is a single value or a short ranked list.
 *
 * @var yii\web\View $this
 * @var frontend\models\ClinicStats $stats
 * @var string $scopeLabel e.g. "the whole clinic" or "your consultants"
 */

$f = Yii::$app->formatter;
$cancellations = $stats->getCancellations();
$booked = $stats->getBookedLastSevenDays();
$perDay = $stats->getPerDay();
$busiest = $stats->getBusiest();

/** One stat tile: label, headline number, one line of context. Text colours only; no colour coding. */
$tile = function ($label, $value, $context) {
    return '<div class="col-6 col-lg-3"><div class="card h-100 border-0 shadow-sm stat-tile"><div class="card-body">'
        . '<div class="text-muted small">' . Html::encode($label) . '</div>'
        . '<div class="display-6 fw-semibold my-1">' . Html::encode($value) . '</div>'
        . '<div class="small text-muted">' . Html::encode($context) . '</div>'
        . '</div></div></div>';
};
?>
<section class="clinic-stats mb-4" aria-label="Appointment numbers for <?= Html::encode($scopeLabel) ?>">
    <p class="small text-muted mb-2">Numbers for <?= Html::encode($scopeLabel) ?>. "Active" means scheduled or confirmed.</p>

    <div class="row g-3 mb-3">
        <?= $tile('Today', $stats->getToday(), 'active appointments on ' . $f->asDate(strtotime($stats->today), 'php:D j M')) ?>
        <?= $tile('Next 7 days', $stats->getNextSevenDays(), 'active appointments, today included') ?>
        <?= $tile('Booked this week', $booked['total'], $booked['online'] . ' online by patients · ' . $booked['staff'] . ' by staff') ?>
        <?= $tile(
            'Cancelled (last ' . $stats->cancellationWindowDays . ' days)',
            $cancellations['rate'] === null ? '—' : $cancellations['rate'] . '%',
            $cancellations['total'] > 0
                ? $cancellations['cancelled'] . ' of ' . $cancellations['total'] . ' appointments'
                : 'no appointments in that period'
        ) ?>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 fw-bold">Next 7 days</h2>
                    <table class="table table-sm mb-0 per-day">
                        <thead><tr><th scope="col">Day</th><th scope="col" class="text-end">Active appointments</th></tr></thead>
                        <tbody>
                            <?php foreach ($perDay as $date => $count): ?>
                                <tr>
                                    <td><?= Html::encode($f->asDate(strtotime($date), 'php:D j M')) ?><?= $date === $stats->today ? ' <span class="text-muted">(today)</span>' : '' ?></td>
                                    <td class="text-end"><?= $count ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 fw-bold">Busiest consultants, next 7 days</h2>
                    <?php if (!$busiest): ?>
                        <p class="text-muted mb-0">No active appointments in the next 7 days.</p>
                    <?php else: ?>
                        <table class="table table-sm mb-0 busiest">
                            <thead><tr><th scope="col">Consultant</th><th scope="col" class="text-end">Active appointments</th></tr></thead>
                            <tbody>
                                <?php foreach ($busiest as $row): ?>
                                    <tr>
                                        <td><?= Html::encode($row['consultant']->names ?? '(no profile)') ?></td>
                                        <td class="text-end"><?= $row['count'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
