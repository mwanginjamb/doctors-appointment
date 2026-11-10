<?php

use common\models\User;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var frontend\models\Consultant[] $consultants */
/** @var array $upcoming consultant user id => upcoming active appointments */

$this->title = 'Consultants';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="manage-consultants-index">

    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">Edit any consultant's profile, working hours and time off, or deactivate a consultant who has left. Deactivated consultants keep their history but cannot be booked.</p>

    <p><?= Html::a('Add consultant', ['create'], ['class' => 'btn btn-success']) ?></p>

    <table class="table table-sm align-middle">
        <thead>
            <tr><th>Consultant</th><th>Account</th><th>Working hours</th><th class="text-end">Upcoming</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($consultants as $consultant): ?>
                <?php
                $user = $consultant->user;
                $active = $consultant->isTakingBookings();
                ?>
                <tr class="<?= $active ? '' : 'text-muted' ?>">
                    <td>
                        <div class="fw-semibold"><?= Html::encode($consultant->names ?: '(no name)') ?></div>
                        <div class="small text-muted"><?= Html::encode(implode(' · ', array_filter([$consultant->practice_name, $user->email ?? null]))) ?></div>
                    </td>
                    <td>
                        <?php if ($user === null): ?>
                            <span class="badge text-bg-light">No account</span>
                        <?php elseif ($active): ?>
                            <span class="badge text-bg-success">Active</span>
                        <?php elseif ((int) $user->status === User::STATUS_DELETED): ?>
                            <span class="badge text-bg-secondary">Deactivated</span>
                        <?php else: ?>
                            <span class="badge text-bg-warning">Unverified</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= Html::encode($consultant->getHoursSummary() ?? 'Not taking bookings') ?></td>
                    <td class="text-end"><?= (int) ($upcoming[$consultant->user_id] ?? 0) ?></td>
                    <td class="text-end text-nowrap">
                        <?= Html::a('Profile', ['/consultant/update', 'id' => $consultant->id], ['class' => 'btn btn-sm btn-outline-primary']) ?>
                        <?= Html::a('Availability', ['/availability/index', 'cid' => $consultant->id], ['class' => 'btn btn-sm btn-outline-primary']) ?>
                        <?= Html::a('Calendar', ['/appointments/calendar', 'cid' => $consultant->id], ['class' => 'btn btn-sm btn-outline-secondary']) ?>
                        <?php if ($user !== null): ?>
                            <?php if ((int) $user->status === User::STATUS_DELETED): ?>
                                <?= Html::a('Reactivate', ['/site/reactivate-user', 'id' => $user->id], [
                                    'class' => 'btn btn-sm btn-outline-success',
                                    'data' => ['method' => 'post', 'confirm' => 'Reactivate ' . ($consultant->names ?: 'this consultant') . '? They can sign in and be booked again.'],
                                ]) ?>
                            <?php else: ?>
                                <?= Html::a('Deactivate', ['/site/deactivate-user', 'id' => $user->id], [
                                    'class' => 'btn btn-sm btn-outline-danger',
                                    'data' => ['method' => 'post', 'confirm' => 'Deactivate ' . ($consultant->names ?: 'this consultant') . '? They will be signed out, cannot sign in, and patients can no longer book them. Their booked appointments are kept.'],
                                ]) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$consultants): ?>
                <tr><td colspan="5" class="text-muted">No consultants yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

</div>
