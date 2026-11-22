<?php

use yii\bootstrap5\Html;

/**
 * F29: a front desk working for several consultants picks whose business card to open.
 *
 * @var yii\web\View $this
 * @var frontend\models\Consultant[] $consultants
 */

$this->title = Yii::t('app', 'Business cards');
?>
<div class="container my-4" style="max-width: 36rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p class="text-muted"><?= Yii::t('app', 'Print a consultant\'s card for the reception desk, or share their profile link.') ?></p>
    <div class="list-group" id="card-consultants">
        <?php foreach ($consultants as $consultant): ?>
            <?= Html::a(Html::encode($consultant->displayName()), ['index', 'cid' => $consultant->id], ['class' => 'list-group-item list-group-item-action']) ?>
        <?php endforeach; ?>
    </div>
</div>
