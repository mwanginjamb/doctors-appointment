<?php

use frontend\services\Insurance;
use yii\bootstrap5\Html;

/**
 * F18: an insured booking's insurer, member number and pre-authorisation on the appointment page;
 * for those who may record it, the form to record approved, declined or pending.
 *
 * @var yii\web\View $this
 * @var frontend\models\Appointments $model
 */

$summary = Insurance::summary($model);
if ($summary === null) {
    return;
}
$status = $model->preauth_status;
$badge = ['pending' => 'text-bg-warning', 'approved' => 'text-bg-success', 'declined' => 'text-bg-danger'][$status] ?? 'text-bg-light';
$canRecord = Insurance::canRecord($model);
$by = $model->preauth_by ? \common\models\User::findOne($model->preauth_by) : null;
?>
<div class="card border-0 shadow-sm mb-3" id="appointment-insurance">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <strong><?= Yii::t('app', 'Insurance') ?>:</strong> <?= Html::encode($summary) ?>
            <?php if ($status): ?>
                <span class="badge <?= $badge ?>" id="preauth-status"><?= Html::encode(Insurance::preauthLabels()[$status] ?? $status) ?></span>
            <?php endif; ?>
            <?php if ((int) $model->insurer_listed === 0 && $model->insurer_listed !== null): ?>
                <span class="badge text-bg-light border" id="insurer-not-listed"><?= Yii::t('app', 'Insurer not listed by the consultant') ?></span>
            <?php endif; ?>
        </div>
        <?php if ($model->preauth_reference || $model->preauth_note || $model->preauth_at): ?>
            <div class="small text-muted mt-1">
                <?= $model->preauth_reference ? Html::encode(Yii::t('app', 'Reference {ref}', ['ref' => $model->preauth_reference])) . ' · ' : '' ?>
                <?= $model->preauth_note ? Html::encode($model->preauth_note) . ' · ' : '' ?>
                <?= $model->preauth_at ? Html::encode(Yii::t('app', 'recorded {when}{by}', ['when' => date('j M Y H:i', $model->preauth_at),
                    'by' => $by ? ' ' . Yii::t('app', 'by {name}', ['name' => $by->full_name ?: $by->username]) : ''])) : '' ?>
            </div>
        <?php endif; ?>
        <?php if ($canRecord): ?>
            <?= Html::beginForm(['/appointments/preauth', 'id' => $model->id], 'post', ['class' => 'row g-2 align-items-end mt-2', 'id' => 'preauth-form']) ?>
                <div class="col-sm-3">
                    <label class="form-label small mb-1" for="preauth-status-input"><?= Yii::t('app', 'Pre-authorisation') ?></label>
                    <?= Html::dropDownList('preauth_status', $status, [
                        Insurance::PREAUTH_APPROVED => Yii::t('app', 'Approved'),
                        Insurance::PREAUTH_DECLINED => Yii::t('app', 'Declined'),
                        Insurance::PREAUTH_PENDING => Yii::t('app', 'Pending'),
                    ], ['class' => 'form-select form-select-sm', 'id' => 'preauth-status-input']) ?>
                </div>
                <div class="col-sm-3">
                    <label class="form-label small mb-1" for="preauth-reference"><?= Yii::t('app', 'Reference (approved)') ?></label>
                    <input type="text" name="preauth_reference" id="preauth-reference" class="form-control form-control-sm" maxlength="64" value="<?= Html::encode($model->preauth_reference) ?>">
                </div>
                <div class="col-sm-4">
                    <label class="form-label small mb-1" for="preauth-note"><?= Yii::t('app', 'Note (sent to the patient if declined)') ?></label>
                    <input type="text" name="preauth_note" id="preauth-note" class="form-control form-control-sm" maxlength="255" value="<?= Html::encode($model->preauth_note) ?>">
                </div>
                <div class="col-sm-2"><?= Html::submitButton(Yii::t('app', 'Record'), ['class' => 'btn btn-sm btn-primary w-100']) ?></div>
            <?= Html::endForm() ?>
        <?php endif; ?>
    </div>
</div>
