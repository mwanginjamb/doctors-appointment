<?php

/** @var yii\web\View $this */
/** @var yii\bootstrap5\ActiveForm $form */
/** @var \frontend\models\SignupForm $model */

use yii\bootstrap5\Html;
use yii\bootstrap5\ActiveForm;

$this->title = 'Signup';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="site-signup">
    <!-- <h1><?= Html::encode($this->title) ?></h1> -->
    <!-- 
    <p>Please fill out the following fields to signup:</p> -->

    <div class="row">
        <div class="col-12 col-md-12">
            <?php $form = ActiveForm::begin(['id' => 'form-signup']); ?>

            <?= $form->errorSummary($model, ['header' => '<p class="fw-semibold mb-1">Please fix the following:</p>']) ?>

            <?= $form->field($model, 'username')->textInput(['autofocus' => true]) ?>
            <?= $form->field($model, 'email') ?>
            <?= $form->field($model, 'full_name') ?>
            <?= $form->field($model, 'phone_number') ?>
            <?= $form->field($model, 'password')->passwordInput() ?>
            <?= $form->field($model, 'confirmPassword')->passwordInput() ?>
            <?= $form->field($model, 'role')->textInput(['readonly' => true]) ?>

            <?php if ($model->asksConsent()): ?>
                <!-- F11: consent, recorded with the account -->
                <p class="mb-2"><?= Html::a('Read the privacy notice', ['/site/privacy'], ['target' => '_blank']) ?> (opens in a new tab)</p>
                <?= $form->field($model, 'consent_data')->checkbox() ?>
                <?= $form->field($model, 'consent_reminders')->radioList(\frontend\models\ConsentForm::reminderChoices())
                    ->hint('Emails are sent either way; you can change this later.') ?>
            <?php endif; ?>

            <div class="form-group">
                <?= Html::submitButton('Signup', ['class' => 'btn btn-primary', 'name' => 'signup-button']) ?>
            </div>

            <?php ActiveForm::end(); ?>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <div class="d-flex gap-2 gap-md-4 flex-column flex-md-row justify-content-md-between mt-5">
                <?= Html::a('Back To Login', ['site/login'], ['class' => 'link-secondary text-decoration-none']) ?>
                <?= Html::a('Forgot password', ['site/request-password-reset'], ['class' => 'link-secondary text-decoration-none']) ?>
            </div>
        </div>
    </div>
</div>