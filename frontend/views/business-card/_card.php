<?php

use frontend\services\BusinessCard;
use yii\bootstrap5\Html;

/**
 * F29: both sides of a consultant's business card at real size (85 × 55 mm). Shared by the card
 * page, its print page and /admin. Side A: who they are and up to three facilities (no personal
 * phone or email); side B: the QR code for their public profile.
 *
 * @var yii\web\View $this
 * @var frontend\models\Consultant $consultant
 * @var frontend\models\Facility[] $facilities on the card, in order
 */

$name = $consultant->displayName();
$speciality = BusinessCard::specialityLine($consultant);
$profileUrl = $consultant->profileUrl(true);
$shownUrl = preg_replace('#^https?://(www\.)?#', '', $profileUrl);
?>
<style>
    .bc-card { width: 85mm; height: 55mm; box-sizing: border-box; position: relative; overflow: hidden; background: #fff; color: #1d2733;
        border-radius: 3mm; box-shadow: 0 1px 6px rgba(0,0,0,.18); font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .bc-a { padding: 5mm 5mm 4mm 8mm; display: flex; flex-direction: column; }
    .bc-a::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 3mm; background: #0b5c7a; }
    .bc-name { font-size: 13pt; font-weight: 700; line-height: 1.1; margin: 0; }
    .bc-speciality { font-size: 8pt; color: #0b5c7a; font-weight: 600; margin-top: 1mm; line-height: 1.2; }
    .bc-practice { font-size: 7.5pt; margin-top: .8mm; }
    .bc-reg { font-size: 6.5pt; color: #5b6673; margin-top: .6mm; }
    .bc-facilities { margin-top: auto; border-top: .3mm solid #d5dde5; padding-top: 1.6mm; }
    .bc-facility { margin-top: .9mm; line-height: 1.2; }
    .bc-facility:first-child { margin-top: 0; }
    .bc-facility-name { font-size: 6.8pt; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .bc-facility-name span { font-weight: 400; color: #0b5c7a; }
    .bc-facility-address { font-size: 5.8pt; color: #5b6673; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .bc-b { padding: 5mm; display: flex; align-items: center; gap: 4mm; background: #0b5c7a; color: #fff; }
    .bc-qr { flex: 0 0 34mm; height: 34mm; background: #fff; border-radius: 2mm; padding: 1.2mm; box-sizing: border-box; }
    .bc-qr svg { width: 100%; height: 100%; display: block; }
    .bc-qr svg .qr-dark, .bc-qr svg path { fill: #10202c; }
    .bc-scan { font-size: 10pt; font-weight: 700; line-height: 1.15; }
    .bc-b-name { font-size: 7pt; margin-top: 1.5mm; opacity: .9; }
    .bc-url { font-size: 6pt; margin-top: 1.5mm; word-break: break-all; opacity: .85; }
    .bc-app { position: absolute; right: 5mm; bottom: 3.5mm; font-size: 5.5pt; opacity: .7; letter-spacing: .02em; }
</style>
<div class="bc-sides d-flex flex-wrap gap-4 justify-content-center" id="business-card">
    <div class="bc-card bc-a" id="business-card-a">
        <p class="bc-name"><?= Html::encode($name) ?></p>
        <?php if ($speciality): ?>
            <div class="bc-speciality"><?= Html::encode($speciality) ?></div>
        <?php endif; ?>
        <?php if ($consultant->practice_name): ?>
            <div class="bc-practice"><?= Html::encode($consultant->practice_name) ?></div>
        <?php endif; ?>
        <?php if ($consultant->kmpdc_registration_number): ?>
            <div class="bc-reg"><?= Html::encode('KMPDC Reg. No. ' . $consultant->kmpdc_registration_number) ?></div>
        <?php endif; ?>
        <?php if ($facilities): ?>
            <div class="bc-facilities">
                <?php foreach ($facilities as $facility): ?>
                    <div class="bc-facility">
                        <div class="bc-facility-name"><?= Html::encode($facility->name) ?><?= $facility->phone ? ' <span>· ' . Html::encode($facility->phone) . '</span>' : '' ?></div>
                        <?php if ($facility->address): ?>
                            <div class="bc-facility-address"><?= Html::encode(preg_replace('/\s*\R\s*/', ', ', $facility->address)) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="bc-card bc-b" id="business-card-b">
        <div class="bc-qr" id="business-card-qr" data-url="<?= Html::encode($profileUrl) ?>"><?= BusinessCard::qr($profileUrl) ?></div>
        <div>
            <div class="bc-scan"><?= Yii::t('app', 'Scan to book online') ?></div>
            <div class="bc-b-name"><?= Html::encode($name) ?></div>
            <div class="bc-url"><?= Html::encode($shownUrl) ?></div>
        </div>
        <div class="bc-app"><?= Html::encode(Yii::$app->name) ?></div>
    </div>
</div>
