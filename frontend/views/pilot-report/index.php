<?php

use yii\bootstrap5\Html;

/**
 * F26: the pilot report for a facility admin (their facility) or a consultant (their own figures).
 *
 * @var yii\web\View $this
 * @var array[] $reports PilotReport::forFacility(), one per facility
 * @var bool $mine a consultant's own figures only
 */

$this->title = Yii::t('app', 'Pilot report');
?>
<div class="pilot-report-page container my-4" style="max-width: 64rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        <?= $mine
            ? Yii::t('app', 'Your month 1 (days 1–30 of your pilot, no fees: the no-show baseline) against months 2 and 3, by the date of each appointment, at each facility you work at.')
            : Yii::t('app', 'Each consultant\'s month 1 (days 1–30 of their pilot, no fees: the no-show baseline) against months 2 and 3, by the date of each appointment, and the facility\'s total. The move to standard terms in month 4 is decided on these figures.') ?>
    </p>
    <?php if (!$reports): ?>
        <div class="alert alert-light border" id="pilot-report-none"><?= Yii::t('app', 'No pilot has started yet: it starts with the first booking.') ?></div>
    <?php endif; ?>
    <?php foreach ($reports as $report): ?>
        <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mt-4">
            <h2 class="h5 mb-0"><?= Html::encode($report['facility']->name) ?></h2>
            <div class="no-print">
                <?= Html::a(Yii::t('app', 'Download CSV'), ['index', 'facility' => $report['facility']->id, 'format' => 'csv'], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><?= Yii::t('app', 'Print or save as PDF') ?></button>
            </div>
        </div>
        <?= $this->render('_report', ['report' => $report, 'showTotal' => !$mine]) ?>
    <?php endforeach; ?>
</div>
