<?php

use yii\bootstrap5\Html;

/**
 * F26: the 90-day pilot report in /admin, a facility at a time.
 *
 * @var yii\web\View $this
 * @var frontend\models\Facility[] $facilities with a pilot started
 * @var array|null $report PilotReport::forFacility()
 */

$this->title = 'Pilot report';
$this->params['breadcrumbs'][] = ['label' => 'Pilots', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="pilot-report-admin py-4">
    <h1 class="h3"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        Each consultant's month 1 (days 1–30, no fees: the no-show baseline) against months 2 and 3, counted from their own first
        booking and by the date of each appointment; then added up for the facility. The no-show rate is of appointments whose
        outcome was recorded.
    </p>

    <?php if (!$report): ?>
        <div class="alert alert-light border">No pilot has started yet: a consultant's pilot starts with their first booking.</div>
    <?php else: ?>
        <div class="d-flex flex-wrap align-items-end gap-2 mb-3 no-print">
            <?= Html::beginForm(['report'], 'get', ['class' => 'd-flex gap-2 align-items-end']) ?>
                <div>
                    <label class="form-label small mb-1" for="report-facility">Facility</label>
                    <?= Html::dropDownList('facility', $report['facility']->id, \yii\helpers\ArrayHelper::map($facilities, 'id', 'name'),
                        ['class' => 'form-select form-select-sm', 'id' => 'report-facility', 'onchange' => 'this.form.submit()']) ?>
                </div>
            <?= Html::endForm() ?>
            <?= Html::a('Download CSV', ['report', 'facility' => $report['facility']->id, 'format' => 'csv'], ['class' => 'btn btn-sm btn-outline-secondary', 'id' => 'report-csv']) ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print or save as PDF</button>
        </div>
        <h2 class="h5"><?= Html::encode($report['facility']->name) ?></h2>
        <?= $this->render('@frontend/views/pilot-report/_report', ['report' => $report]) ?>
    <?php endif; ?>
</div>
