<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\PublicHoliday $model */

$this->title = 'Edit ' . $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Public Holidays', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="holiday-update">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', ['model' => $model]) ?>

</div>
