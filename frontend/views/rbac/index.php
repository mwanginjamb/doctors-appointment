<?php

use yii\helpers\Html;
use yii\grid\GridView;

/** @var yii\web\View $this */
/** @var yii\data\ArrayDataProvider $dataProvider */

$this->title = 'Roles';
$this->params['breadcrumbs'][] = 'RBAC';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="rbac-index">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0"><?= Html::encode($this->title) ?></h1>
        <?= Html::a('<span class="material-symbols-outlined align-middle">add</span> Create Role', ['create-role'], ['class' => 'btn btn-primary']) ?>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?= GridView::widget([
                'dataProvider' => $dataProvider,
                'tableOptions' => ['class' => 'table table-hover align-middle mb-0'],
                'layout' => "<div class=\"table-responsive\">{items}</div>\n{pager}",
                'columns' => [
                    [
                        'attribute' => 'name',
                        'format' => 'raw',
                        'value' => function ($row) {
                            return Html::tag('span', Html::encode($row['name']), ['class' => 'fw-semibold'])
                                . ($row['core'] ? ' ' . Html::tag('span', 'core', ['class' => 'badge text-bg-secondary']) : '');
                        },
                    ],
                    'description',
                    [
                        'label' => 'Permissions',
                        'format' => 'raw',
                        'value' => function ($row) {
                            if (!$row['permissions']) {
                                return '<span class="text-muted">—</span>';
                            }
                            return implode(' ', array_map(function ($p) {
                                return Html::tag('span', Html::encode($p), ['class' => 'badge text-bg-light border']);
                            }, $row['permissions']));
                        },
                    ],
                    [
                        'attribute' => 'users',
                        'label' => 'Users',
                        'format' => 'raw',
                        'value' => function ($row) {
                            return Html::a($row['users'], ['user-roles', 'role' => $row['name']]);
                        },
                    ],
                    [
                        'label' => '',
                        'format' => 'raw',
                        'contentOptions' => ['class' => 'text-end text-nowrap'],
                        'value' => function ($row) {
                            $buttons = Html::a('Edit', ['update-role', 'name' => $row['name']], ['class' => 'btn btn-sm btn-outline-primary'])
                                . ' ' . Html::a('Hierarchy', ['manage-hierarchy', 'name' => $row['name']], ['class' => 'btn btn-sm btn-outline-secondary']);
                            if (!$row['core']) {
                                $buttons .= ' ' . Html::a('Delete', ['delete-role', 'name' => $row['name']], [
                                    'class' => 'btn btn-sm btn-outline-danger',
                                    'data-method' => 'post',
                                    'data-confirm' => "Delete role '{$row['name']}'? Users holding it will lose it.",
                                ]);
                            }
                            return $buttons;
                        },
                    ],
                ],
            ]) ?>
        </div>
    </div>
</div>
