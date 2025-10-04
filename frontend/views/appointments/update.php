<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\Appointments $model */

$this->title = Yii::t('app', 'Update Appointments: {name}', [
    'name' => '# ' . $model->id . ' for: ' . $model?->patient?->full_name,
]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Appointments'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->id, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = Yii::t('app', 'Update');
?>
<div class="appointments-update card">
    <div class="card-body">
        <h1 class="card-title fw-bold mb-3 mt-3"><?= Html::encode($this->title) ?></h1>

        <?= $this->render('_form', [
            'model' => $model,
        ]) ?>

    </div>

</div>