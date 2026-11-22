<?php

use yii\bootstrap5\Html;

/**
 * The admin home: what waits for a platform admin.
 *
 * @var yii\web\View $this
 * @var int $refunds refund requests to decide
 * @var int $payoutsReady weekly payouts to send
 * @var int $payoutsHeld payouts held for a missing phone number
 * @var int $overdueInvoices consultant invoices unpaid past their due date
 * @var frontend\models\Facility[] $toApprove facilities whose standard terms wait for approval
 * @var frontend\models\Facility[] $unsigned facilities taking bookings with no agreement recorded
 */

$this->title = 'Admin';
?>
<div class="site-index py-4">
    <h1 class="h3 mb-4">Waiting for you</h1>
    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Standard terms to approve</h2>
                    <p class="display-6 mb-2" id="count-approvals"><?= count($toApprove) ?></p>
                    <?php foreach ($toApprove as $facility): ?>
                        <div class="small"><?= Html::a(Html::encode($facility->name), ['/pilot/facility', 'id' => $facility->id]) ?></div>
                    <?php endforeach; ?>
                    <?= Html::a('Pilots', ['/pilot/index'], ['class' => 'btn btn-outline-primary btn-sm mt-2']) ?>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Facilities without an agreement</h2>
                    <p class="display-6 mb-2" id="count-unsigned"><?= count($unsigned) ?></p>
                    <?php foreach ($unsigned as $facility): ?>
                        <div class="small"><?= Html::a(Html::encode($facility->name), ['/pilot/facility', 'id' => $facility->id]) ?></div>
                    <?php endforeach; ?>
                    <?php if ($unsigned): ?>
                        <p class="small text-muted mt-1 mb-0">Taking bookings with consultants there; record the signed agreement.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Overdue consultant invoices</h2>
                    <p class="display-6 mb-2" id="count-overdue"><?= $overdueInvoices ?></p>
                    <?= Html::a('Invoices', ['/invoice/index', 'status' => 'overdue'], ['class' => 'btn btn-outline-primary btn-sm']) ?>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Refund requests</h2>
                    <p class="display-6 mb-2" id="count-refunds"><?= $refunds ?></p>
                    <?= Html::a('Review refunds', ['/refund/index'], ['class' => 'btn btn-outline-primary btn-sm']) ?>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
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
