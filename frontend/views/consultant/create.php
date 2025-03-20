<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\Consultant $model */

$this->title = Yii::t('app', 'Add Consultant');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Consultants'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="consultant-create">

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