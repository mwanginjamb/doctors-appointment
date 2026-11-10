<?php

use frontend\models\Appointments;
use yii\helpers\Html;

/**
 * "Attended" / "No-show" buttons for an appointment that has started. The recorded outcome is shown
 * filled in; within the correction window the other button changes it.
 * Render only when Appointments::canRecordOutcome() and FrontDesk::canRecordOutcome() allow it.
 *
 * @var Appointments $model
 * @var string $size Bootstrap button size class, e.g. 'btn-sm'
 */

$size = $size ?? 'btn-sm';
$button = function ($outcome, $label, $style) use ($model, $size) {
    $current = $model->status === $outcome;
    return Html::a(Html::encode($label), ['/appointments/outcome', 'id' => $model->id], [
        'class' => "btn $size " . ($current ? "btn-$style" : "btn-outline-$style") . ' outcome-' . str_replace('_', '-', $outcome),
        'aria-pressed' => $current ? 'true' : 'false',
        'data' => ['method' => 'post', 'params' => ['outcome' => $outcome]],
    ]);
};
?>
<span class="outcome-buttons text-nowrap">
    <?= $button(Appointments::STATUS_COMPLETED, Yii::t('app', 'Attended'), 'success') ?>
    <?= $button(Appointments::STATUS_NO_SHOW, Yii::t('app', 'No-show'), 'warning') ?>
</span>
