<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\UserProfile $model */

$this->title = Yii::t('app', 'Create User Profile');
$this->params['breadcrumbs'][] = ['label' => Yii::t('app', 'User Profiles'), 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$model->phone = Yii::$app->user->identity->phone_number;
$model->email = Yii::$app->user->identity->email;
$model->user_id = Yii::$app->user->id;
?>
<div class="card border-0 shadow-sm p-3 mb-5 bg-body rounded">

    <h1 class="card-title fw-bold mb-3 mt-3"><?= Html::encode($this->title) ?></h1>

    <?= $this->render('_form', [
        'model' => $model,
        'gender' => $gender,
        'providers' => $providers,
    ]) ?>

</div>