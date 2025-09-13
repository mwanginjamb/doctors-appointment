<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var app\models\Provider $model */

$this->title = 'Create Provider';
$this->params['breadcrumbs'][] = ['label' => 'Providers', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="provider-create">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
    ]) ?>

</div>
