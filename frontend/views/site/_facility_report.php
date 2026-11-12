<?php

use frontend\models\ConsultantFacility;
use yii\helpers\Html;

/**
 * F8 phase 2: a month's appointments per facility, for settling up with each one. A plain table:
 * the reader compares facilities by reading across a row, and copies the totals.
 *
 * @var yii\web\View $this
 * @var frontend\models\ClinicStats $stats
 * @var string $month Y-m
 * @var frontend\models\Facility|null $facility the dashboard's facility filter
 * @var string|null $route where the month links go; the dashboard by default
 */

$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));
$rows = $stats->getByFacility($from, $to);
$prev = date('Y-m', strtotime($from . ' -1 month'));
$next = date('Y-m', strtotime($from . ' +1 month'));
// Shown on the dashboard (consultancy admins) and on the board (facility admins)
$route = '/' . ($route ?? 'site/settings');
$link = function ($label, $m) use ($facility, $route) {
    return Html::a($label, [$route, 'month' => $m, 'facility' => $facility->id ?? null], ['class' => 'btn btn-sm btn-outline-secondary']);
};
$totals = ['sessions' => 0, 'attended' => 0, 'noShows' => 0, 'cancelled' => 0, 'unmarked' => 0, 'fees' => 0.0, 'noFee' => 0];
foreach ($rows as $row) {
    foreach ($totals as $key => $value) {
        $totals[$key] += $row[$key];
    }
}
$fees = function ($amount) {
    return ConsultantFacility::formatFee($amount) ?? ConsultantFacility::formatFee(0);
};
?>
<section class="card border-0 shadow-sm mb-4 facility-report" aria-labelledby="facility-report-title">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <h2 class="h6 fw-bold mb-0 me-auto" id="facility-report-title">
                Per facility, <?= Html::encode(date('F Y', strtotime($from))) ?>
            </h2>
            <?= $link('‹ ' . date('M', strtotime($prev . '-01')), $prev) ?>
            <?php if ($month !== date('Y-m')): ?>
                <?= $link('This month', date('Y-m')) ?>
            <?php endif; ?>
            <?= $link(date('M', strtotime($next . '-01')) . ' ›', $next) ?>
        </div>
        <p class="small text-muted">
            Sessions are appointments that were not cancelled. Fees due add up the fee recorded on each
            attended appointment, as it was when booked.
        </p>
        <?php if (!$rows): ?>
            <p class="text-muted mb-0">No appointments this month.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Facility</th>
                            <th scope="col" class="text-end">Sessions</th>
                            <th scope="col" class="text-end">Attended</th>
                            <th scope="col" class="text-end">No-shows</th>
                            <th scope="col" class="text-end">Cancelled</th>
                            <th scope="col" class="text-end">No outcome yet</th>
                            <th scope="col" class="text-end">Fees due</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= $row['facility'] ? Html::a(Html::encode($row['facility']->name), ['board/index', 'facility' => $row['facility']->id, 'from' => $from, 'to' => $to]) : '<span class="text-muted">(no facility)</span>' ?></td>
                                <td class="text-end"><?= $row['sessions'] ?></td>
                                <td class="text-end"><?= $row['attended'] ?></td>
                                <td class="text-end"><?= $row['noShows'] ?></td>
                                <td class="text-end"><?= $row['cancelled'] ?></td>
                                <td class="text-end"><?= $row['unmarked'] ?></td>
                                <td class="text-end text-nowrap">
                                    <?= Html::encode($fees($row['fees'])) ?>
                                    <?php if ($row['noFee']): ?>
                                        <div class="small text-muted"><?= $row['noFee'] ?> with no fee set</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if (count($rows) > 1): ?>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td>Total</td>
                                <td class="text-end"><?= $totals['sessions'] ?></td>
                                <td class="text-end"><?= $totals['attended'] ?></td>
                                <td class="text-end"><?= $totals['noShows'] ?></td>
                                <td class="text-end"><?= $totals['cancelled'] ?></td>
                                <td class="text-end"><?= $totals['unmarked'] ?></td>
                                <td class="text-end text-nowrap"><?= Html::encode($fees($totals['fees'])) ?></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
