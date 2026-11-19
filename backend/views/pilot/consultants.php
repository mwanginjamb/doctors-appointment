<?php

use yii\bootstrap5\Html;

/**
 * F28: every consultant's pilot and plan (F22).
 *
 * @var yii\web\View $this
 * @var frontend\models\Consultant[] $consultants
 * @var bool $feesOn
 */

$this->title = 'Consultants and plans';
$this->params['breadcrumbs'][] = ['label' => 'Pilots', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="pilot-consultants py-4">
    <h1 class="h3"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted">
        Each pilot starts at the consultant's first booking. On Plan A, patients pay the commitment fee from day 31;
        untick "insured patients pay" and the consultant absorbs it for insured patients. Plan B charges no fee.
        <?= $feesOn ? '' : 'Commitment fees are off (COMMITMENT_FEE_ENABLED=0): plans are kept but nothing is charged.' ?>
    </p>
    <?= $this->render('_consultant_rows', ['consultants' => $consultants, 'showFacilities' => true]) ?>
</div>
