<?php

use yii\bootstrap5\Html;

/**
 * @var yii\web\View $this
 * @var string $active index or credits
 */
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><?= Html::a('Commitment fees', ['index'], ['class' => 'nav-link' . ($active === 'index' ? ' active' : '')]) ?></li>
    <li class="nav-item"><?= Html::a('Patient credits', ['credits'], ['class' => 'nav-link' . ($active === 'credits' ? ' active' : '')]) ?></li>
    <li class="nav-item"><?= Html::a('Refund requests', ['/refund/index'], ['class' => 'nav-link']) ?></li>
</ul>
