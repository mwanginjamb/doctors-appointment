<?php

use yii\helpers\Html;
use yii\grid\GridView;
use common\models\User as Identity;

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var string|null $q */
/** @var string|null $type */

$this->title = 'System Users';
$this->params['breadcrumbs'][] = $this->title;

$statuses = [
    Identity::STATUS_ACTIVE => ['Active', 'text-bg-success'],
    Identity::STATUS_INACTIVE => ['Unverified', 'text-bg-warning'],
    Identity::STATUS_DELETED => ['Deactivated', 'text-bg-secondary'],
];
$canAssign = Yii::$app->user->can(\common\components\RbacHelper::PERM_MANAGE_RBAC);
?>
<div class="site-users">
    <h1 class="h3 mb-4"><?= Html::encode($this->title) ?></h1>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <?= Html::beginForm(['users'], 'get', ['class' => 'row g-2 align-items-end']) ?>
            <div class="col-md-6">
                <?= Html::label('Search', 'users-q', ['class' => 'form-label']) ?>
                <?= Html::textInput('q', $q, ['id' => 'users-q', 'class' => 'form-control', 'placeholder' => 'Username, email, name or phone']) ?>
            </div>
            <div class="col-md-4">
                <?= Html::label('Account type', 'users-type', ['class' => 'form-label']) ?>
                <?= Html::dropDownList('type', $type, ['client' => 'Client / patient', 'consultant' => 'Consultant', 'admin' => 'Admin'], ['id' => 'users-type', 'class' => 'form-select', 'prompt' => 'All']) ?>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <?= Html::submitButton('Filter', ['class' => 'btn btn-primary flex-fill']) ?>
                <?= Html::a('Reset', ['users'], ['class' => 'btn btn-outline-secondary']) ?>
            </div>
            <?= Html::endForm() ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'tableOptions' => ['class' => 'table table-hover align-middle mb-0'],
                'layout' => "<div class=\"table-responsive\">{items}</div>\n<div class=\"d-flex justify-content-between align-items-center mt-3\">{summary}{pager}</div>",
                'columns' => [
                    'id',
                    'username',
                    'full_name',
                    'email:email',
                    'phone_number',
                    [
                        'attribute' => 'role',
                        'label' => 'Account type',
                    ],
                    [
                        'attribute' => 'status',
                        'format' => 'raw',
                        'value' => function ($user) use ($statuses) {
                            [$label, $class] = $statuses[$user->status] ?? [$user->status, 'text-bg-light'];
                            return Html::tag('span', $label, ['class' => "badge $class"]);
                        },
                    ],
                    [
                        'attribute' => 'auth_provider',
                        'label' => 'Sign-in',
                        'value' => function ($user) {
                            return $user->auth_provider ?: 'password';
                        },
                    ],
                    'created_at:date',
                    [
                        'label' => '',
                        'format' => 'raw',
                        'contentOptions' => ['class' => 'text-end text-nowrap'],
                        'value' => function ($user) use ($canAssign) {
                            $buttons = $canAssign
                                ? Html::a('Roles', ['/rbac/assign-role', 'userId' => $user->id], ['class' => 'btn btn-sm btn-outline-primary'])
                                : '';
                            if ((int) $user->id === (int) Yii::$app->user->id) {
                                return $buttons;
                            }
                            $name = $user->full_name ?: $user->username; // Html::a() escapes attribute values
                            if ((int) $user->status === Identity::STATUS_DELETED) {
                                return $buttons . ' ' . Html::a('Reactivate', ['/site/reactivate-user', 'id' => $user->id], [
                                    'class' => 'btn btn-sm btn-outline-success',
                                    'data' => ['method' => 'post', 'confirm' => "Reactivate $name? They can sign in again" . ($user->isConsultantAccount() ? ' and be booked.' : '.')],
                                ]);
                            }
                            return $buttons . ' ' . Html::a('Deactivate', ['/site/deactivate-user', 'id' => $user->id], [
                                'class' => 'btn btn-sm btn-outline-danger',
                                'data' => ['method' => 'post', 'confirm' => "Deactivate $name? They will be signed out and cannot sign in" . ($user->isConsultantAccount() ? ', and patients can no longer book them. Their booked appointments are kept.' : '.')],
                            ]);
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>
</div>
