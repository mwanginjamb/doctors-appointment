<?php

use yii\helpers\Html;
use yii\grid\GridView;

/** @var yii\web\View $this */
/** @var yii\data\ArrayDataProvider $dataProvider */

$this->title = 'Permissions';
$this->params['breadcrumbs'][] = 'RBAC';
$this->params['breadcrumbs'][] = $this->title;

$badges = function (array $names, $class) {
    if (!$names) {
        return '<span class="text-muted">—</span>';
    }
    return implode(' ', array_map(function ($n) use ($class) {
        return Html::tag('span', Html::encode($n), ['class' => "badge $class"]);
    }, $names));
};
$corePermissions = array_keys(\common\components\RbacHelper::definitions()['permissions']);
?>
<div class="rbac-permissions">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0"><?= Html::encode($this->title) ?></h1>
        <?= Html::a('<span class="material-symbols-outlined align-middle">add</span> Create Permission', ['create-permission'], ['class' => 'btn btn-primary']) ?>
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
                        'value' => function ($row) use ($corePermissions) {
                            return Html::tag('span', Html::encode($row['name']), ['class' => 'fw-semibold'])
                                . (in_array($row['name'], $corePermissions, true) ? ' ' . Html::tag('span', 'core', ['class' => 'badge text-bg-secondary']) : '');
                        },
                    ],
                    'description',
                    [
                        'label' => 'Parent permissions',
                        'format' => 'raw',
                        'value' => function ($row) use ($badges) {
                            return $badges($row['parents'], 'text-bg-light border');
                        },
                    ],
                    [
                        'label' => 'Granted to roles',
                        'format' => 'raw',
                        'value' => function ($row) use ($badges) {
                            return $badges($row['roles'], 'text-bg-primary');
                        },
                    ],
                    [
                        'label' => '',
                        'format' => 'raw',
                        'contentOptions' => ['class' => 'text-end text-nowrap'],
                        'value' => function ($row) use ($corePermissions) {
                            $buttons = Html::a('Edit', ['update-permission', 'name' => $row['name']], ['class' => 'btn btn-sm btn-outline-primary'])
                                . ' ' . Html::a('Children', ['manage-hierarchy', 'name' => $row['name']], ['class' => 'btn btn-sm btn-outline-secondary']);
                            if (!in_array($row['name'], $corePermissions, true)) {
                                $buttons .= ' ' . Html::a('Delete', ['delete-permission', 'name' => $row['name']], [
                                    'class' => 'btn btn-sm btn-outline-danger',
                                    'data-method' => 'post',
                                    'data-confirm' => "Delete permission '{$row['name']}'?",
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
