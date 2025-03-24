<?php

/** @var yii\web\View $this */
/** @var yii\bootstrap5\ActiveForm $form */
/** @var \common\models\LoginForm $model */

use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

$this->title = 'Signup Choice';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="site-login">
    <!-- <h1><?= Html::encode($this->title) ?></h1> -->

    <!-- <p>Please fill out the following fields to login:</p> -->

    <div class="row">
        <div class="col-lg-12">
            <div class="d-flex gap-3 justify-content-between align-items-center">
                <?= Html::a('client account', ['site/signup', 'role' => 'client'], ['class' => 'btn btn-lg btn-outline-dark']) ?>
                <?= Html::a('consultant account', ['site/signup', 'role' => 'consultant'], ['class' => 'btn btn-lg btn-outline-dark']) ?>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <div class="d-flex gap-2 gap-md-4 flex-column flex-md-row justify-content-md-between mt-5">

                <?= Html::a('I have an account', ['site/login'], ['class' => 'link-secondary text-decoration-none']) ?>
                <?= Html::a('Forgot password', ['site/request-password-reset'], ['class' => 'link-secondary text-decoration-none']) ?>
            </div>
        </div>
    </div>
</div>