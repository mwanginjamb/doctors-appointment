<?php

use frontend\models\FacilityRequest;
use yii\bootstrap5\Html;

/**
 * Facilities consultants asked for. Each waiting request can only be created (checked and listed,
 * with the consultant added there) or declined (with an optional reason); either way the
 * consultant is told by email and WhatsApp.
 *
 * @var yii\web\View $this
 * @var FacilityRequest[] $pending oldest first
 * @var FacilityRequest[] $answered answered in the last 30 days, newest first
 */

$this->title = 'Facility Requests';
$this->params['breadcrumbs'][] = ['label' => 'Facilities', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
$f = Yii::$app->formatter;
?>
<div class="facility-requests">

    <h1><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        Facilities consultants work at that are not listed yet. <strong>Create</strong> lets you check the
        details, lists the facility and adds it to the consultant's facilities; <strong>Decline</strong> sends
        them your reason. Either way they are told by email and WhatsApp.
    </p>

    <?php if (!$pending): ?>
        <p class="alert alert-light border">No requests are waiting.</p>
    <?php endif; ?>

    <?php foreach ($pending as $request): ?>
        <div class="card shadow-sm mb-3 facility-request" data-id="<?= (int) $request->id ?>">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <div class="fw-semibold fs-5"><?= Html::encode($request->name) ?></div>
                        <div class="small text-muted">
                            Asked by <?= Html::encode($request->consultant->names ?? 'a consultant') ?>
                            on <?= Html::encode($f->asDatetime($request->created_at, 'php:D j M Y, H:i')) ?>
                        </div>
                        <dl class="row small mb-0 mt-2">
                            <?php foreach (['address', 'phone', 'map_url', 'note'] as $attribute): ?>
                                <?php if ($request->$attribute): ?>
                                    <dt class="col-sm-3"><?= Html::encode($request->getAttributeLabel($attribute)) ?></dt>
                                    <dd class="col-sm-9 mb-1"><?= Html::encode($request->$attribute) ?></dd>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </dl>
                    </div>
                    <div class="text-end" style="min-width: 16rem">
                        <?= Html::a('Create', ['create-from-request', 'id' => $request->id], ['class' => 'btn btn-success btn-sm mb-2']) ?>
                        <?= Html::beginForm(['decline', 'id' => $request->id], 'post', ['class' => 'decline-form']) ?>
                            <div class="input-group input-group-sm">
                                <?= Html::textInput('reason', '', ['class' => 'form-control', 'maxlength' => 500, 'placeholder' => 'Reason (optional, sent to them)', 'aria-label' => 'Reason']) ?>
                                <?= Html::submitButton('Decline', [
                                    'class' => 'btn btn-outline-danger',
                                    'data-confirm' => 'Decline the request for ' . $request->name . '?',
                                ]) ?>
                            </div>
                        <?= Html::endForm() ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($answered): ?>
        <h2 class="h5 fw-bold mt-4">Answered in the last 30 days</h2>
        <table class="table table-sm align-middle">
            <thead>
                <tr><th>Facility</th><th>Asked by</th><th>Answer</th><th>When</th></tr>
            </thead>
            <tbody>
                <?php foreach ($answered as $request): ?>
                    <tr>
                        <td><?= Html::encode($request->facility->name ?? $request->name) ?></td>
                        <td><?= Html::encode($request->consultant->names ?? '') ?></td>
                        <td>
                            <?php if ($request->status === FacilityRequest::STATUS_CREATED): ?>
                                <span class="badge text-bg-success">Created</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Declined</span>
                                <span class="small text-muted"><?= Html::encode($request->decision_note ?? '') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= Html::encode($f->asDatetime($request->decided_at, 'php:j M, H:i')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
