<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\Facility $model */

$this->title = 'Edit Facility: ' . $model->name . '';
$this->params['breadcrumbs'][] = ['label' => 'Facilities', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="facility-update">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', ['model' => $model]) ?>

</div>
