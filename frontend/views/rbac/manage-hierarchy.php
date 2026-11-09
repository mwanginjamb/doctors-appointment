<?php

use yii\rbac\Item;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var yii\rbac\Item $item */
/** @var yii\rbac\Item[] $allItems */
/** @var yii\rbac\Item[] $currentChildren */
/** @var array $backRoute */

$isRole = $item->type === Item::TYPE_ROLE;
$this->title = ($isRole ? 'Role' : 'Permission') . ' hierarchy: ' . $item->name;
$this->params['breadcrumbs'][] = 'RBAC';
$this->params['breadcrumbs'][] = ['label' => $isRole ? 'Roles' : 'Permissions', 'url' => $backRoute];
$this->params['breadcrumbs'][] = $item->name;

$groups = [
    'Roles' => array_filter($allItems, function ($i) { return $i->type === Item::TYPE_ROLE; }),
    'Permissions' => array_filter($allItems, function ($i) { return $i->type === Item::TYPE_PERMISSION; }),
];
?>
<div class="rbac-manage-hierarchy">
    <h1 class="h3 mb-2"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted mb-4">
        Anyone holding <strong><?= Html::encode($item->name) ?></strong> also gets every item ticked below
        (and everything those items include).
    </p>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?= Html::beginForm() ?>
            <?php foreach ($groups as $label => $items): ?>
                <?php if (!$items) continue; ?>
                <h2 class="h6 text-uppercase text-muted mt-2"><?= $label ?></h2>
                <div class="row row-cols-1 row-cols-md-2 g-2 mb-3">
                    <?php foreach ($items as $name => $child): ?>
                        <div class="col">
                            <div class="form-check">
                                <?= Html::checkbox('children[]', isset($currentChildren[$name]), [
                                    'value' => $name,
                                    'id' => 'child-' . md5($name),
                                    'class' => 'form-check-input',
                                ]) ?>
                                <label class="form-check-label" for="<?= 'child-' . md5($name) ?>">
                                    <?= Html::encode($name) ?>
                                    <?php if ($child->description): ?>
                                        <small class="text-muted d-block"><?= Html::encode($child->description) ?></small>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <div class="d-flex gap-2">
                <?= Html::submitButton('Save', ['class' => 'btn btn-primary']) ?>
                <?= Html::a('Cancel', $backRoute, ['class' => 'btn btn-outline-secondary']) ?>
            </div>
            <?= Html::endForm() ?>
        </div>
    </div>
</div>
