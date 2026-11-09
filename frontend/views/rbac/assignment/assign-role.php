<?php

use yii\helpers\Html;
use yii\bootstrap5\ActiveForm;

/** @var yii\web\View $this */
/** @var frontend\models\UserRoleForm $model */
/** @var frontend\models\User $user */
/** @var yii\rbac\Role[] $allRoles */
/** @var string $nativeRole */

$this->title = 'Assign Roles: ' . $user->username;
$this->params['breadcrumbs'][] = 'RBAC';
$this->params['breadcrumbs'][] = ['label' => 'User Role Assignment', 'url' => ['user-roles']];
$this->params['breadcrumbs'][] = $user->username;

$items = [];
foreach ($allRoles as $name => $role) {
    $items[$name] = Html::encode($name)
        . ($role->description ? ' <small class="text-muted">— ' . Html::encode($role->description) . '</small>' : '')
        . ($name === $nativeRole ? ' <span class="badge text-bg-info">default for account type</span>' : '');
}
?>
<div class="rbac-assign-role">
    <h1 class="h3 mb-4"><?= Html::encode($this->title) ?></h1>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 text-muted text-uppercase">User</h2>
                    <dl class="mb-0">
                        <dt>Name</dt><dd><?= Html::encode($user->full_name ?: '—') ?></dd>
                        <dt>Email</dt><dd><?= Html::encode($user->email) ?></dd>
                        <dt>Account type</dt><dd><?= Html::encode($user->role ?: '—') ?></dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <?php $form = ActiveForm::begin(); ?>
                    <?= $form->errorSummary($model) ?>
                    <?= $form->field($model, 'roles')->checkboxList($items, ['encode' => false]) ?>
                    <div class="d-flex gap-2">
                        <?= Html::submitButton('Save roles', ['class' => 'btn btn-primary']) ?>
                        <?= Html::a('Cancel', ['user-roles'], ['class' => 'btn btn-outline-secondary']) ?>
                    </div>
                    <?php ActiveForm::end(); ?>
                </div>
            </div>
        </div>
    </div>
</div>
