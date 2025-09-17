<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\Specialization $model */

$this->title = Yii::t('app', 'Manage Areas of Specializations');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'Specializations'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="specialization-create">

    <!-- <h1><?= Html::encode($this->title) ?></h1> -->

    <?= $this->render('_form', [
        'model' => $model,
        'specializations' => $specializations
    ]) ?>

</div>