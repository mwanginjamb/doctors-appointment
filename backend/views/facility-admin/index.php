<?php

use frontend\models\FacilityAdminClaim;
use yii\helpers\Html;

/**
 * Consultants who run a facility (its facility admin as well).
 *
 * @var yii\web\View $this
 * @var FacilityAdminClaim[] $pending
 * @var common\models\User[] $admins consultants who run a facility now
 * @var FacilityAdminClaim[] $recent
 */

$this->title = 'Facility admins';
$name = function ($user) {
    return $user ? Html::encode($user->full_name ?: $user->username) : '<span class="text-muted">Deleted account</span>';
};
?>
<h1 class="h3"><?= Html::encode($this->title) ?></h1>
<p class="text-muted">
    Consultants who say they run a facility. Once approved they are its facility admin as well: they
    manage its front desk, closed days and contact details, and see every consultant's bookings there,
    switching from their own practice in their account menu. Check with the facility before approving.
    A consultant who asked for a new facility and runs it becomes its admin when the facility is listed.
</p>

<h2 class="h5 mt-4">Waiting for an answer (<?= count($pending) ?>)</h2>
<?php if (!$pending): ?>
    <p class="text-muted" id="claims-none">None.</p>
<?php else: ?>
    <table class="table align-middle" id="claims-pending">
        <thead><tr><th>Asked</th><th>Consultant</th><th>Facility</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $claim): ?>
            <tr>
                <td class="small"><?= date('j M Y', $claim->created_at) ?></td>
                <td><?= $name($claim->user) ?><div class="small text-muted"><?= Html::encode($claim->user->email ?? '') ?></div></td>
                <td>
                    <?= Html::encode($claim->facilityName()) ?>
                    <?php if (!$claim->facility_id): ?>
                        <div class="small text-muted">Asked to be listed; granted when the facility request is approved</div>
                    <?php endif; ?>
                </td>
                <td style="min-width: 18rem">
                    <?php if ($claim->facility_id): ?>
                        <?= Html::beginForm(['approve', 'id' => $claim->id], 'post', ['class' => 'd-inline claim-approve-form']) ?>
                            <?= Html::submitButton('Approve', ['class' => 'btn btn-sm btn-success me-1']) ?>
                        <?= Html::endForm() ?>
                    <?php endif; ?>
                    <?= Html::beginForm(['decline', 'id' => $claim->id], 'post', ['class' => 'd-inline-flex gap-1 claim-decline-form']) ?>
                        <?= Html::textInput('reason', '', ['class' => 'form-control form-control-sm', 'placeholder' => 'Reason (sent to them)', 'maxlength' => 500]) ?>
                        <?= Html::submitButton('Decline', ['class' => 'btn btn-sm btn-outline-danger']) ?>
                    <?= Html::endForm() ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2 class="h5 mt-4">Consultants who run a facility (<?= count($admins) ?>)</h2>
<?php if (!$admins): ?>
    <p class="text-muted">None.</p>
<?php else: ?>
    <table class="table align-middle" id="consultant-admins">
        <thead><tr><th>Consultant</th><th>Runs</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($admins as $user): ?>
            <tr>
                <td><?= $name($user) ?></td>
                <td><?= Html::encode(\frontend\models\Facility::findOne($user->facility_id)->name ?? '') ?></td>
                <td>
                    <?= Html::beginForm(['revoke', 'id' => $user->id], 'post', ['class' => 'd-inline-flex gap-1 revoke-form']) ?>
                        <?= Html::textInput('reason', '', ['class' => 'form-control form-control-sm', 'placeholder' => 'Reason (sent to them)', 'maxlength' => 500]) ?>
                        <?= Html::submitButton('Revoke', [
                            'class' => 'btn btn-sm btn-outline-danger',
                            'data' => ['confirm' => 'Take the facility admin role back? They stay a consultant.'],
                        ]) ?>
                    <?= Html::endForm() ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2 class="h5 mt-4">Recent answers</h2>
<?php if (!$recent): ?>
    <p class="text-muted">None.</p>
<?php else: ?>
    <table class="table table-sm small" id="claims-recent">
        <thead><tr><th>Answered</th><th>Consultant</th><th>Facility</th><th>Outcome</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $claim): ?>
            <tr>
                <td><?= $claim->decided_at ? date('j M Y', $claim->decided_at) : '' ?></td>
                <td><?= $name($claim->user) ?></td>
                <td><?= Html::encode($claim->facilityName()) ?></td>
                <td>
                    <?= Html::encode(ucfirst($claim->status)) ?>
                    <?php if ($claim->decision_note): ?>
                        <div class="text-muted"><?= Html::encode($claim->decision_note) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= $claim->decider ? $name($claim->decider) : '<span class="text-muted">automatic</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
