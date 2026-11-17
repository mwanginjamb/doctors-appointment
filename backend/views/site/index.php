<?php

use yii\bootstrap5\Html;

/**
 * The admin home: what waits for a platform admin.
 *
 * @var yii\web\View $this
 * @var int $refunds refund requests to decide
 * @var int $payoutsReady weekly payouts to send
 * @var int $payoutsHeld payouts held for a missing phone number
 */

$this->title = 'Admin';
?>
<div class="site-index py-4">
    <h1 class="h3 mb-4">Waiting for you</h1>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Refund requests</h2>
                    <p class="display-6 mb-2" id="count-refunds"><?= $refunds ?></p>
                    <?= Html::a('Review refunds', ['/refund/index'], ['class' => 'btn btn-outline-primary btn-sm']) ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Front-desk payouts to send</h2>
                    <p class="display-6 mb-2" id="count-payouts"><?= $payoutsReady ?></p>
                    <?php if ($payoutsHeld): ?>
                        <p class="small text-warning"><?= $payoutsHeld ?> held: no M-Pesa number on the account</p>
                    <?php endif; ?>
                    <?= Html::a('Weekly payouts', ['/payout/index'], ['class' => 'btn btn-outline-primary btn-sm']) ?>
                </div>
            </div>
        </div>
    </div>
</div>
