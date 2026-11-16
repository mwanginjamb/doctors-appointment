<?php

use frontend\services\Pilot;
use frontend\services\StandardTerms;
use yii\bootstrap5\Html;

/**
 * A warning on every page for a facility's consultants, front desk and facility admin while its
 * standard terms wait for the facility to confirm them (from day 91), and for platform admins
 * while confirmed terms wait for their approval; with a link to the terms page.
 *
 * @var yii\web\View $this
 */

if (Yii::$app->user->isGuest) {
    return;
}
$f = Yii::$app->formatter;
foreach (StandardTerms::warningsFor(Yii::$app->user->identity) as $warning):
    $facility = $warning['facility'];
?>
    <div class="alert alert-warning my-3 standard-terms-warning" role="alert">
        <strong><?= Yii::t('app', 'Standard terms') ?>:</strong>
        <?php if ($warning['status'] === Pilot::TERMS_AWAITING_APPROVAL): ?>
            <?= Html::encode(Yii::t('app', '{facility} confirmed them and is waiting for your approval.', ['facility' => $facility->name])) ?>
        <?php else: ?>
            <?= Html::encode($warning['status'] === Pilot::TERMS_GRACE
                ? Yii::t('app', 'the pilot at {facility} has ended. Please confirm the standard terms by {date}.', ['facility' => $facility->name, 'date' => $f->asDate($warning['graceEnds'], 'long')])
                : Yii::t('app', 'the standard terms at {facility} are still not confirmed.', ['facility' => $facility->name])) ?>
        <?php endif; ?>
        <?= Html::a(Yii::t('app', 'Review the terms'), ['/terms/view', 'facility' => $facility->id], ['class' => 'alert-link ms-1']) ?>
    </div>
<?php endforeach; ?>
