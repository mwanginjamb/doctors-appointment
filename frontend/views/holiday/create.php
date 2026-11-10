<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\PublicHoliday $model */

$this->title = 'Add Public Holiday';
$this->params['breadcrumbs'][] = ['label' => 'Public Holidays', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="holiday-create">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', ['model' => $model]) ?>

</div>
