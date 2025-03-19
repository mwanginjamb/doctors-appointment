<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\Consultant $model */

$this->title = Yii::t('app', 'Create Consultant');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Consultants'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="consultant-create">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
