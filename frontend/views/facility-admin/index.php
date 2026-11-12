<?php

use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var common\models\User[] $admins */
/** @var array $facilities facility id => name */

$this->title = 'Facility Admins';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="facility-admin-index">

    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        A facility admin runs one facility: its appointments board and numbers, its front-desk staff, its
        closed days, its address, phone and map link, and its audit log. They see every consultant's
        appointments at that facility, and nothing at other facilities. They do not book.
    </p>

    <p><?= Html::a('Add facility admin', ['create'], ['class' => 'btn btn-success']) ?></p>

    <table class="table table-sm align-middle">
        <thead>
            <tr><th>Name</th><th>Signs in with</th><th>Runs</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($admins as $user): ?>
                <tr>
                    <td><?= Html::encode($user->full_name ?: $user->username) ?></td>
                    <td><?= Html::encode($user->username) ?></td>
                    <td>
                        <?php if ($user->facility_id && isset($facilities[$user->facility_id])): ?>
                            <?= Html::encode($facilities[$user->facility_id]) ?>
                        <?php else: ?>
                            <span class="text-warning">Not set: sees nothing. Edit to choose.</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= Html::a('Edit', ['update', 'id' => $user->id], ['class' => 'btn btn-sm btn-outline-primary']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$admins): ?>
                <tr><td colspan="4" class="text-muted">No facility admins yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

</div>
