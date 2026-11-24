<?php

use yii\helpers\Html;
use yii\helpers\Inflector;

/**
 * F12: everything held about a patient (PatientData::export()), as tables to read or print.
 * Shared by My data and the admins' Closure Requests.
 *
 * @var yii\web\View $this
 * @var array $data PatientData::export()
 */

$show = function ($value) {
    if ($value === null || $value === '') {
        return '<span class="text-muted">–</span>';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    return nl2br(Html::encode((string) $value));
};
?>
<div class="patient-data-report" id="patient-data-report">
    <p class="small text-muted">
        Prepared <?= Html::encode($data['generated_at']) ?><?= $data['controller'] ? ' by ' . Html::encode($data['controller']) : '' ?>.
    </p>

    <h2 class="h5 mt-4">Your account</h2>
    <table class="table table-sm" id="data-account">
        <tbody>
        <?php foreach ($data['account'] as $key => $value): ?>
            <tr><th class="fw-normal text-muted" style="width: 14rem"><?= Html::encode(Inflector::humanize($key, true)) ?></th><td><?= $show($value) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php foreach ($data['sections'] as $key => $section): ?>
        <h2 class="h5 mt-4"><?= Html::encode($section['title']) ?> <span class="text-muted small">(<?= count($section['rows']) ?>)</span></h2>
        <?php if (!$section['rows']): ?>
            <p class="text-muted small">None.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped small" id="data-<?= Html::encode(str_replace('_', '-', $key)) ?>">
                    <thead>
                    <tr>
                        <?php foreach (array_keys($section['rows'][0]) as $column): ?>
                            <th><?= Html::encode(Inflector::humanize($column, true)) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($section['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($row as $value): ?>
                                <td><?= $show($value) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
