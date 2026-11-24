<?php

use yii\bootstrap5\Html;

/**
 * F12: the patient's data, to read, print or save as PDF.
 *
 * @var yii\web\View $this
 * @var array $data PatientData::export()
 */

$this->title = 'Your data';
$this->params['breadcrumbs'][] = ['label' => 'My data', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="my-data-view container my-4" style="max-width: 70rem">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 d-print-none">
        <h1 class="h3 fw-bold mb-0"><?= Html::encode($this->title) ?></h1>
        <div>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print or save as PDF</button>
            <?= Html::a('Download as a file (JSON)', ['download'], ['class' => 'btn btn-outline-primary btn-sm', 'id' => 'data-download']) ?>
        </div>
    </div>
    <h1 class="h4 d-none d-print-block">Your data</h1>
    <?= $this->render('_report', ['data' => $data]) ?>
</div>
