<?php

use yii\bootstrap5\Html;

/** @var yii\web\View $this */

$this->title = 'Link not valid';
?>
<div class="waitlist-invalid container my-5" style="max-width: 40rem">
    <h1 class="h3 fw-bold"><?= Html::encode($this->title) ?></h1>
    <p>This waiting-list link is not valid. Please use the link from your latest message, or contact the clinic.</p>
</div>
