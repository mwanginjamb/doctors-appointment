<?php

use yii\helpers\Html;

/**
 * F12: a patient's data, for an admin handling their closure request.
 *
 * @var yii\web\View $this
 * @var common\models\AccountClosureRequest $request
 * @var array $data PatientData::export()
 */

$name = $request->user->full_name ?: $request->user->username;
$this->title = "Data held about $name";
$this->params['breadcrumbs'][] = ['label' => 'Account Closure Requests', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="closure-request-data">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 d-print-none">
        <h1><?= Html::encode($this->title) ?></h1>
        <div>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print or save as PDF</button>
            <?= Html::a('Download (JSON)', ['data', 'id' => $request->id, 'format' => 'json'], ['class' => 'btn btn-outline-primary btn-sm']) ?>
        </div>
    </div>
    <p class="text-muted d-print-none">Give it to the patient only once you are sure who you are talking to. Each view and download is in the audit log.</p>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?= $this->render('@frontend/views/my-data/_report', ['data' => $data]) ?>
        </div>
    </div>
</div>
