<?php

use frontend\components\FrontDesk;
use yii\bootstrap5\ActiveForm;
use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var common\models\User $user */
/** @var frontend\models\AccountForm $account */
/** @var frontend\models\ChangePasswordForm $password */
/** @var frontend\models\Consultant[] $consultants front desk: the consultants they book for */

$this->title = Yii::t('app', 'My account');
$this->params['breadcrumbs'][] = $this->title;

$roleLabels = [
    FrontDesk::ROLE => 'Front desk',
    'admin' => 'Administrator',
    'consultant' => 'Consultant',
    'client' => 'Patient',
];
?>
<div class="account-index container my-4" style="max-width: 48rem">

    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">Signs in with</dt>
                <dd class="col-sm-8"><?= Html::encode($user->username) ?></dd>
                <dt class="col-sm-4">Email</dt>
                <dd class="col-sm-8"><?= Html::encode($user->email ?: '—') ?></dd>
                <dt class="col-sm-4">Account type</dt>
                <dd class="col-sm-8"><?= Html::encode($roleLabels[$user->role] ?? $user->role) ?></dd>
                <?php if ($user->role === FrontDesk::ROLE): ?>
                    <dt class="col-sm-4">Books for</dt>
                    <dd class="col-sm-8">
                        <?php if ($consultants): ?>
                            <?= Html::encode(implode(', ', array_map(function ($c) {
                                return $c->names;
                            }, $consultants))) ?>
                        <?php else: ?>
                            <span class="text-warning">No consultants yet; ask an administrator.</span>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>
            </dl>
            <p class="small text-muted mt-3 mb-0">An administrator changes your email or the consultants you book for.</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 fw-bold mb-3">Your details</h2>
                    <?php $f = ActiveForm::begin(['id' => 'account-form', 'action' => ['account/index']]); ?>
                    <?= $f->field($account, 'full_name')->textInput(['maxlength' => 150]) ?>
                    <?= $f->field($account, 'phone')->input('tel', ['maxlength' => 15]) ?>
                    <?= Html::submitButton('Save', ['class' => 'btn btn-primary']) ?>
                    <?php ActiveForm::end(); ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 fw-bold mb-3">Change password</h2>
                    <?php if ($user->auth_provider): ?>
                        <p class="text-muted mb-0">You sign in with <?= Html::encode(ucfirst($user->auth_provider)) ?>, so there is no password to change here.</p>
                    <?php else: ?>
                        <?php $p = ActiveForm::begin(['id' => 'password-form', 'action' => ['account/password']]); ?>
                        <?= $p->field($password, 'current_password')->passwordInput(['autocomplete' => 'current-password']) ?>
                        <?= $p->field($password, 'new_password')->passwordInput(['autocomplete' => 'new-password']) ?>
                        <?= $p->field($password, 'confirm_password')->passwordInput(['autocomplete' => 'new-password']) ?>
                        <?= Html::submitButton('Change password', ['class' => 'btn btn-outline-primary']) ?>
                        <?php ActiveForm::end(); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
