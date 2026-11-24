<?php

use frontend\services\FacilityAdmins;
use yii\helpers\Html;

/**
 * A consultant who runs a facility: while managing it, a bar on every page with the way back to
 * their own practice; otherwise nothing (the switch is in the account menu).
 *
 * @var yii\web\View $this
 */

if (Yii::$app->user->isGuest || !FacilityAdmins::managing()) {
    return;
}
$facility = \frontend\models\Facility::findOne(FacilityAdmins::runsFacilityId(Yii::$app->user->identity));
?>
<div class="alert alert-primary d-flex flex-wrap align-items-center gap-2 py-2" id="facility-mode-bar">
    <span class="me-auto">
        <?= Yii::t('app', 'Managing {facility}', ['facility' => '<strong>' . Html::encode($facility->name ?? '') . '</strong>']) ?>
    </span>
    <?= Html::beginForm(['/facility-mode/practice'], 'post', ['class' => 'd-inline']) ?>
        <?= Html::submitButton(Yii::t('app', 'Back to my practice'), ['class' => 'btn btn-sm btn-outline-primary', 'id' => 'facility-mode-practice']) ?>
    <?= Html::endForm() ?>
</div>
