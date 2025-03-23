<?php

/** @var yii\web\View $this */
/** @var yii\bootstrap5\ActiveForm $form */
/** @var \frontend\models\PasswordResetRequestForm $model */

use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

$this->title = 'Request password reset';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="site-request-password-reset">
    <!-- <h1><?= Html::encode($this->title) ?></h1> -->

    <p>Please fill out your email. A link to reset password will be sent there.</p>

    <div class="row">
        <div class="col-12 col-md-12">
            <?php $form = ActiveForm::begin(['id' => 'request-password-reset-form']); ?>

            <?= $form->field($model, 'email')->textInput(['autofocus' => true]) ?>

            <div class="form-group">
                <?= Html::submitButton('Send', ['class' => 'btn btn-primary']) ?>
            </div>

            <?php ActiveForm::end(); ?>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <div class="d-flex gap-2 gap-md-4 flex-column flex-md-row justify-content-md-between mt-5">
                <?= Html::a('Back to Login', ['site/login'], ['class' => 'link-secondary text-decoration-none']) ?>
                <?= Html::a('Resend Verification Email', ['site/resend-verification-email'], ['class' => 'link-secondary text-decoration-none']) ?>
            </div>
        </div>
    </div>
</div>