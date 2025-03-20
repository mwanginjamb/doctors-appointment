<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\Consultant $model */

$this->title = Yii::t('app', 'Update Consultant: {name}', [
    'name' => $model->id,
]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Consultants'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->id, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = Yii::t('app', 'Update');
?>
<div class="consultant-update">

    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><?= Html::encode($this->title) ?></h2>
        </div>
        <div class="card-body">

            <?= $this->render('_form', [
                'model' => $model,
            ]) ?>

        </div>
    </div>

</div>