<?php

use yii\helpers\Html;
use yii\grid\GridView;
use yii\helpers\ArrayHelper;

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $dataProvider */
/** @var array<int, string[]> $rolesByUser */
/** @var yii\rbac\Role[] $allRoles */
/** @var string|null $q */
/** @var string|null $role */

$this->title = 'User Role Assignment';
$this->params['breadcrumbs'][] = 'RBAC';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="rbac-user-roles">
    <h1 class="h3 mb-4"><?= Html::encode($this->title) ?></h1>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <?= Html::beginForm(['user-roles'], 'get', ['class' => 'row g-2 align-items-end']) ?>
            <div class="col-md-6">
                <?= Html::label('Search', 'rbac-q', ['class' => 'form-label']) ?>
                <?= Html::textInput('q', $q, ['id' => 'rbac-q', 'class' => 'form-control', 'placeholder' => 'Username, email or name']) ?>
            </div>
            <div class="col-md-4">
                <?= Html::label('RBAC role', 'rbac-role', ['class' => 'form-label']) ?>
                <?= Html::dropDownList('role', $role, ArrayHelper::map($allRoles, 'name', 'name'), ['id' => 'rbac-role', 'class' => 'form-select', 'prompt' => 'Any role']) ?>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <?= Html::submitButton('Filter', ['class' => 'btn btn-primary flex-fill']) ?>
                <?= Html::a('Reset', ['user-roles'], ['class' => 'btn btn-outline-secondary']) ?>
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
                    'email:email',
                    'full_name',
                    [
                        'attribute' => 'role',
                        'label' => 'Account type',
                    ],
                    [
                        'label' => 'RBAC roles',
                        'format' => 'raw',
                        'value' => function ($user) use ($rolesByUser) {
                            $roles = $rolesByUser[$user->id] ?? [];
                            if (!$roles) {
                                return '<span class="text-muted">none</span>';
                            }
                            return implode(' ', array_map(function ($r) {
                                $class = $r === \common\components\RbacHelper::ROLE_ADMIN ? 'text-bg-primary' : 'text-bg-light border';
                                return Html::tag('span', Html::encode($r), ['class' => "badge $class"]);
                            }, $roles));
                        },
                    ],
                    [
                        'label' => '',
                        'format' => 'raw',
                        'contentOptions' => ['class' => 'text-end'],
                        'value' => function ($user) {
                            return Html::a('Assign roles', ['assign-role', 'userId' => $user->id], ['class' => 'btn btn-sm btn-outline-primary']);
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>
</div>
