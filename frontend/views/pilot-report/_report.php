<?php

use yii\bootstrap5\Html;

/**
 * F26: one facility's pilot report: the ROI estimate, the facility's months added up, and each
 * consultant's months. Shared by /admin and the main site (and their print layout).
 *
 * @var yii\web\View $this
 * @var array $report PilotReport::forFacility()
 * @var bool $showTotal false when a consultant sees only their own figures
 */

$showTotal = $showTotal ?? true;
$ksh = function ($v) { return 'Ksh ' . number_format((float) $v); };
$pct = function ($v) { return $v === null ? '—' : number_format(100 * $v, 1) . '%'; };
$statusLabel = function ($m) {
    if ($m['status'] === 'current') {
        return $m['day'] ? Yii::t('app', 'so far (day {day})', ['day' => $m['day']]) : Yii::t('app', 'under way');
    }

    return $m['status'] === 'future' ? Yii::t('app', 'not started') : '';
};
$rows = [
    'bookings' => [Yii::t('app', 'Bookings'), function ($f) { return number_format($f['bookings']); }],
    'attended' => [Yii::t('app', 'Attended'), function ($f) { return number_format($f['attended']); }],
    'noShows' => [Yii::t('app', 'No-shows'), function ($f) { return number_format($f['noShows']); }],
    'noShowRate' => [Yii::t('app', 'No-show rate'), function ($f) use ($pct) { return '<strong>' . $pct($f['noShowRate']) . '</strong>'; }],
    'unmarked' => [Yii::t('app', 'Outcome not recorded'), function ($f) { return number_format($f['unmarked']); }],
    'lateCancels' => [Yii::t('app', 'Late cancellations (patient, under 24 h)'), function ($f) {
        return number_format($f['lateCancels']) . ($f['lateCancelRate'] === null ? '' : ' <span class="text-muted small">(' . number_format($f['lateCancelRate'], 1) . ' per 100)</span>');
    }],
    'ahead' => [Yii::t('app', 'Booked ahead / walk-ins'), function ($f) use ($pct) {
        return number_format($f['ahead']) . ' / ' . number_format($f['walkIns']) . ' <span class="text-muted small">(' . $pct($f['aheadShare']) . ' ahead)</span>';
    }],
    'feesCollected' => [Yii::t('app', 'Commitment fees collected'), function ($f) use ($ksh) { return $ksh($f['feesCollected']); }],
    'feesAbsorbed' => [Yii::t('app', 'Fees absorbed by the consultant'), function ($f) use ($ksh) { return $ksh($f['feesAbsorbed']); }],
    'feesForfeited' => [Yii::t('app', 'Fees forfeited (no-show, late cancel)'), function ($f) use ($ksh) { return $ksh($f['feesForfeited']); }],
    'feesCredited' => [Yii::t('app', 'Fees credited'), function ($f) use ($ksh) { return $ksh($f['feesCredited']); }],
    'feesRefunded' => [Yii::t('app', 'Credits refunded'), function ($f) use ($ksh) { return $ksh($f['feesRefunded']); }],
    'incentives' => [Yii::t('app', 'Front-desk incentives earned'), function ($f) use ($ksh) { return $ksh($f['incentives']); }],
];
$table = function (array $months, $id) use ($rows, $statusLabel) {
    $out = '<table class="table table-sm align-middle pilot-table" id="' . Html::encode($id) . '"><thead><tr><th></th>';
    foreach ([1 => Yii::t('app', 'Month 1 (baseline)'), 2 => Yii::t('app', 'Month 2 (fees on)'), 3 => Yii::t('app', 'Month 3')] as $m => $label) {
        $sub = isset($months[$m]) ? $statusLabel($months[$m]) : '';
        $out .= '<th class="text-end">' . $label . ($sub ? '<div class="small text-muted fw-normal">' . Html::encode($sub) . '</div>' : '') . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $key => [$label, $cell]) {
        $out .= '<tr class="row-' . $key . '"><th class="fw-normal">' . Html::encode($label) . '</th>';
        foreach ([1, 2, 3] as $m) {
            $out .= '<td class="text-end">' . (isset($months[$m]) && $months[$m]['status'] !== 'future' ? $cell($months[$m]) : '—') . '</td>';
        }
        $out .= '</tr>';
    }

    return $out . '</tbody></table>';
};
$roi = $report['roi'];
?>
<div class="pilot-report" id="pilot-report-<?= (int) $report['facility']->id ?>">
    <?php if ($showTotal): ?>
        <div class="card border-0 shadow-sm mb-3" id="pilot-roi">
            <div class="card-body">
                <h3 class="h6 mb-2"><?= Yii::t('app', 'No-show reduction and ROI estimate (months 2–3 against month 1)') ?></h3>
                <?php if ($roi === null): ?>
                    <p class="text-muted small mb-0"><?= Yii::t('app', 'Not enough yet: month 1 and months 2–3 both need appointments whose outcome was recorded.') ?></p>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-4">
                        <div><div class="small text-muted"><?= Yii::t('app', 'No-show rate') ?></div>
                            <div class="fs-5 fw-bold" id="pilot-rate-change"><?= $pct($roi['baselineRate']) ?> → <?= $pct($roi['laterRate']) ?>
                                <span class="fs-6 <?= $roi['points'] <= 0 ? 'text-success' : 'text-danger' ?>">(<?= ($roi['points'] > 0 ? '+' : '') . number_format($roi['points'], 1) ?> <?= Yii::t('app', 'points') ?>)</span></div></div>
                        <div><div class="small text-muted"><?= Yii::t('app', 'Visits kept') ?></div><div class="fs-5 fw-bold"><?= number_format($roi['visitsKept'], 1) ?></div></div>
                        <div><div class="small text-muted"><?= Yii::t('app', 'Estimated value') ?></div><div class="fs-5 fw-bold" id="pilot-roi-total"><?= $ksh($roi['total']) ?></div></div>
                    </div>
                    <p class="small text-muted mt-2 mb-0">
                        <?= Yii::t('app', 'Estimate: visits kept = ({before} − {after}) × {n} visits with a recorded outcome in months 2–3; consultation revenue kept = visits kept × {fee} (the average consultation fee on attended visits) = {revenue}; plus {forfeited} commitment fees forfeited by no-shows and late cancellations.', [
                            'before' => $pct($roi['baselineRate']), 'after' => $pct($roi['laterRate']), 'n' => number_format($roi['outcomes']),
                            'fee' => $roi['averageFee'] === null ? Yii::t('app', 'no fee recorded') : $ksh($roi['averageFee']),
                            'revenue' => $roi['revenueKept'] === null ? '—' : $ksh($roi['revenueKept']), 'forfeited' => $ksh($roi['feesForfeited']),
                        ]) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <h3 class="h6"><?= Yii::t('app', 'All consultants at {facility}', ['facility' => Html::encode($report['facility']->name)]) ?></h3>
        <?php if ($report['total']): ?>
            <?= $table($report['total'], 'pilot-total-' . (int) $report['facility']->id) ?>
        <?php else: ?>
            <p class="text-muted small"><?= Yii::t('app', 'No consultant here has started their pilot yet.') ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php foreach ($report['consultants'] as $c): ?>
        <h3 class="h6 mt-4">
            <?= Html::encode($c['consultant']->names) ?>
            <span class="small text-muted fw-normal">· <?= Html::encode(Yii::t('app', 'pilot from {date}, day {day}', ['date' => date('j M Y', strtotime($c['start'])), 'day' => $c['day']])) ?></span>
        </h3>
        <?= $table($c['months'], 'pilot-consultant-' . (int) $c['consultant']->id) ?>
    <?php endforeach; ?>
</div>
<?php
// Print or "Save as PDF": the report only, without menus and buttons
$this->registerCss('@media print { nav, header, footer, .navbar, .breadcrumb, .no-print, .btn { display: none !important; }'
    . ' body { background: #fff !important; } .pilot-table { font-size: 11px; } .pilot-report h3 { break-after: avoid; } .pilot-table { break-inside: avoid; } }');
