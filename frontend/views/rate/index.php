<?php

use yii\bootstrap5\Html;

/**
 * F30: rate the consultant for one visit: 1–5 stars and an optional private comment.
 *
 * @var yii\web\View $this
 * @var frontend\models\Appointments $appointment attended
 * @var frontend\models\ConsultantRating|null $rating given before (or with errors)
 * @var bool $open still within the days the link is open
 */

$consultant = $appointment->consultant;
$doctor = $consultant ? $consultant->displayName() : Yii::t('app', 'your doctor');
$this->title = Yii::t('app', 'Rate your visit');
$stars = $rating && $rating->stars ? (int) $rating->stars : 0;
$labels = [1 => Yii::t('app', 'Poor'), 2 => Yii::t('app', 'Fair'), 3 => Yii::t('app', 'Good'), 4 => Yii::t('app', 'Very good'), 5 => Yii::t('app', 'Excellent')];
?>
<div class="rate-visit container my-4" style="max-width: 34rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        <?= Html::encode($doctor) ?> · <?= Html::encode(date('l j M Y', strtotime($appointment->date))) ?>
        <?= $appointment->facility ? ' · ' . Html::encode($appointment->facility->name) : '' ?>
    </p>

    <?php if (!$open): ?>
        <div class="alert alert-light border" id="rate-closed">
            <?= $rating && !$rating->hasErrors() && !$rating->withdrawn
                ? Html::encode(Yii::t('app', 'You rated this visit {n} out of 5. Thank you.', ['n' => $rating->stars]))
                : Yii::t('app', 'This visit can no longer be rated.') ?>
        </div>
    <?php else: ?>
        <?php if ($rating && !$rating->isNewRecord && !$rating->hasErrors()): ?>
            <p class="small" id="rate-given"><?= Html::encode(Yii::t('app', 'You rated this visit {n} out of 5. You can change it below.', ['n' => $rating->stars])) ?></p>
        <?php endif; ?>
        <?= Html::beginForm(['index', 'id' => $appointment->id, 'sig' => \frontend\services\Ratings::signature($appointment)], 'post', ['id' => 'rate-form']) ?>
            <fieldset class="mb-3">
                <legend class="fs-6 fw-semibold"><?= Html::encode(Yii::t('app', 'How was your visit to {doctor}?', ['doctor' => $doctor])) ?></legend>
                <div class="d-flex flex-wrap gap-2" role="radiogroup">
                    <?php foreach ($labels as $n => $label): ?>
                        <input type="radio" class="btn-check" name="stars" id="stars-<?= $n ?>" value="<?= $n ?>" <?= $stars === $n ? 'checked' : '' ?> autocomplete="off">
                        <label class="btn btn-outline-warning text-dark" for="stars-<?= $n ?>" title="<?= Html::encode($label) ?>">
                            <?= str_repeat('★', $n) ?><span class="d-block small"><?= Html::encode($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if ($rating && $rating->hasErrors('stars')): ?>
                    <div class="text-danger small mt-1" id="rate-error"><?= Html::encode($rating->getFirstError('stars')) ?></div>
                <?php endif; ?>
            </fieldset>
            <label class="form-label" for="rate-comment"><?= Yii::t('app', 'Anything to add? (optional)') ?></label>
            <textarea name="comment" id="rate-comment" class="form-control mb-1" rows="3" maxlength="1000"><?= Html::encode($rating->comment ?? '') ?></textarea>
            <p class="form-text mb-3"><?= Yii::t('app', 'Only the doctor and the platform read comments; they are never published. The doctor does not see your name.') ?></p>
            <?= Html::submitButton(Yii::t('app', 'Send my rating'), ['class' => 'btn btn-primary', 'id' => 'rate-submit']) ?>
        <?= Html::endForm() ?>
    <?php endif; ?>
</div>
