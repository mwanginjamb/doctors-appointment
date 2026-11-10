<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var common\models\User[] $staff */
/** @var array $assigned user id => consultant names */

$this->title = 'Front Desk Staff';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="front-desk-index">

    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        Front desk sees, books, moves and cancels appointments only for the consultants assigned to them,
        and can register walk-in patients. They cannot change roles, reference data or other accounts.
    </p>

    <p><?= Html::a('Add front-desk staff', ['create'], ['class' => 'btn btn-success']) ?></p>

    <table class="table table-sm align-middle">
        <thead>
            <tr><th>Name</th><th>Signs in with</th><th>Books for</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($staff as $user): ?>
                <tr>
                    <td><?= Html::encode($user->full_name ?: $user->username) ?></td>
                    <td><?= Html::encode($user->username) ?></td>
                    <td>
                        <?php if (!empty($assigned[$user->id])): ?>
                            <?= Html::encode(implode(', ', $assigned[$user->id])) ?>
                        <?php else: ?>
                            <span class="text-warning">No consultants yet</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= Html::a('Edit', ['update', 'id' => $user->id], ['class' => 'btn btn-sm btn-outline-primary']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$staff): ?>
                <tr><td colspan="4" class="text-muted">No front-desk staff yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

</div>
