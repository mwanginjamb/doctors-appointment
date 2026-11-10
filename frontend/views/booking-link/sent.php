<?php

use yii\bootstrap5\Html;

/** @var yii\web\View $this */
/** @var frontend\models\BookingLinkForm $form */

$this->title = 'Booking link sent';
$this->params['breadcrumbs'][] = ['label' => 'Booking links', 'url' => ['index']];
$this->params['breadcrumbs'][] = $this->title;
?>
<div class="booking-link-sent container my-4" style="max-width: 44rem">

    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p>
        Sent to <strong><?= Html::encode($form->recipient_name) ?></strong>
        for <?= Html::encode($form->consultant->names) ?>.
        It works until <strong><?= date('H:i', $form->link->expires_at) ?></strong>.
    </p>

    <label class="form-label" for="booking-link-url">The link (shown only now; copy it if you also want to send it yourself)</label>
    <div class="input-group mb-3">
        <input type="text" id="booking-link-url" class="form-control" readonly value="<?= Html::encode($form->url) ?>">
        <button class="btn btn-outline-primary" type="button" id="copy-booking-link">Copy</button>
    </div>

    <p>
        <?= Html::a('Send another', ['create', 'cid' => $form->consultant->id], ['class' => 'btn btn-primary']) ?>
        <?= Html::a('All my links', ['index'], ['class' => 'btn btn-link']) ?>
    </p>
</div>
<?php
$this->registerJs(<<<'JS'
document.getElementById('copy-booking-link').addEventListener('click', function () {
    var input = document.getElementById('booking-link-url');
    input.select();
    (navigator.clipboard ? navigator.clipboard.writeText(input.value) : Promise.resolve(document.execCommand('copy')))
        .then(function () { document.getElementById('copy-booking-link').textContent = 'Copied'; });
});
JS);
