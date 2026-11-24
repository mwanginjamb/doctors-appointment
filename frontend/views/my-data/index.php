<?php

use yii\bootstrap5\Html;

/**
 * F12: "My data": see or download it, or ask for it to be deleted.
 *
 * @var yii\web\View $this
 * @var common\models\AccountClosureRequest|null $request open request to close the account
 * @var string[] $problems money the clinic still owes the patient (PatientData::deletionProblems())
 */

$this->title = 'My data';
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="my-data container my-4" style="max-width: 44rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p><?= Html::a('Read the privacy notice', ['/site/privacy']) ?></p>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 fw-bold">See or download your data</h2>
            <p>
                Everything the clinic holds about you: your account, appointments, reminders sent,
                payments and credits, waiting list, ratings, consents and sign-ins.
            </p>
            <?= Html::a('See my data', ['view'], ['class' => 'btn btn-primary btn-sm me-2', 'id' => 'data-view']) ?>
            <?= Html::a('Download as a file (JSON)', ['download'], ['class' => 'btn btn-outline-primary btn-sm', 'id' => 'data-download']) ?>
        </div>
    </div>

    <div class="card shadow-sm border-danger-subtle">
        <div class="card-body">
            <h2 class="h5 fw-bold">Delete my data</h2>
            <?php if ($request && $request->delete_data): ?>
                <p class="mb-0" id="deletion-pending">
                    You asked us to delete your data on <?= date('j F Y', $request->created_at) ?>.
                    The clinic will do it and close your account; they may contact you first.
                </p>
            <?php else: ?>
                <p>
                    Your name, phone number, email, date of birth, symptoms and insurance details are
                    removed and your account is closed: you will not be able to sign in or book. Your
                    upcoming appointments are cancelled now and your doctor is told.
                </p>
                <p class="small text-muted">
                    The law requires us to keep some records without your name: the dates of your visits
                    and the doctor, and payments with their M-Pesa receipts.
                </p>
                <?php if ($problems): ?>
                    <div class="alert alert-warning small" id="deletion-money">
                        The clinic still owes you money (a refundable credit or a refund on its way). Ask for
                        your refund first; the clinic settles it before deleting your data.
                    </div>
                <?php endif; ?>
                <?php if ($request): ?>
                    <p class="small">You have already asked us to close your account; this asks for your data to be deleted as well.</p>
                <?php endif; ?>
                <?= Html::beginForm(['delete'], 'post', ['id' => 'delete-data-form']) ?>
                <label class="form-label" for="delete-reason">Reason (optional)</label>
                <textarea id="delete-reason" name="reason" class="form-control mb-2" rows="2" maxlength="500"></textarea>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="confirm" value="1" id="delete-confirm">
                    <label class="form-check-label" for="delete-confirm">I understand my account will be closed and this cannot be undone.</label>
                </div>
                <?= Html::submitButton('Ask the clinic to delete my data', ['class' => 'btn btn-outline-danger', 'id' => 'delete-data-submit']) ?>
                <?= Html::endForm() ?>
            <?php endif; ?>
        </div>
    </div>
</div>
