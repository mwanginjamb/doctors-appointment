<?php

use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var frontend\models\NewConsultantForm $form */
/** @var frontend\models\Facility $facility */
/** @var array $keep route params that keep the facility (consultancy admins) */

$this->title = 'Add a Consultant';
$this->params['breadcrumbs'][] = ['label' => 'Consultants: ' . $facility->name, 'url' => ['index'] + $keep];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="facility-consultants-create" style="max-width: 40rem">

    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        Creates the doctor's sign-in account, working at <?= Html::encode($facility->name) ?> with the default week.
        They complete their profile and set their hours, session length and fee here once they sign in.
    </p>

    <?php $f = ActiveForm::begin(['id' => 'new-consultant-form']); ?>

    <?= $f->field($form, 'full_name')->textInput(['maxlength' => 150]) ?>
    <?= $f->field($form, 'email')->input('email') ?>
    <?= $f->field($form, 'phone')->input('tel', ['maxlength' => 15]) ?>
    <?= $f->field($form, 'practice_name')->textInput(['maxlength' => 255]) ?>
    <?= $f->field($form, 'password')->passwordInput(['autocomplete' => 'new-password'])
        ->hint('Give it to them in person; they can change it with "forgot password".') ?>

    <div class="form-group">
        <?= Html::submitButton('Create consultant', ['class' => 'btn btn-success']) ?>
        <?= Html::a('Back', ['index'] + $keep, ['class' => 'btn btn-link']) ?>
    </div>

    <?php ActiveForm::end(); ?>

</div>
