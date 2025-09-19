<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\UserProfile $model */

$this->title = Yii::t('app', 'Update User Profile: {name}', [
    'name' => $model->id,
]);
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'User Profiles'), 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => $model->id, 'url' => ['view', 'id' => $model->id]];
$this->params['breadcrumbs'][] = Yii::t('app', 'Update');
?>
<div class="user-profile-update">

    <h1><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
        'gender' => $gender,
        'providers' => $providers,
    ]) ?>

</div>