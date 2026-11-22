<?php

use yii\bootstrap5\Html;

/**
 * F29: the card alone, one side per 85 × 55 mm page: the browser's "Save as PDF" gives a file a
 * print shop can use. Rendered without a layout; opens the print dialog.
 *
 * @var yii\web\View $this
 * @var frontend\models\Consultant $consultant
 * @var frontend\models\Facility[] $facilities
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::encode($consultant->displayName() . ' · business card') ?></title>
    <style>
        @page { size: 85mm 55mm; margin: 0; }
        html, body { margin: 0; padding: 0; background: #e9edf1; }
        .bc-sides { display: flex; flex-direction: column; align-items: center; gap: 8mm; padding: 10mm 0; }
        .bc-hint { font: 13px "Segoe UI", Roboto, Arial, sans-serif; text-align: center; color: #444; padding: 12px 16px 0; }
        @media print {
            html, body { background: #fff; }
            .bc-hint { display: none; }
            .bc-sides { display: block; padding: 0; }
            .bc-card { border-radius: 0 !important; box-shadow: none !important; page-break-after: always; break-after: page; }
            .bc-card:last-child { page-break-after: auto; break-after: auto; }
        }
    </style>
</head>
<body>
    <p class="bc-hint">Side A, then side B, one per page (85 × 55 mm). In the print dialog choose "Save as PDF" for a print shop, with margins "None" and "Background graphics" on.</p>
    <?= $this->render('@frontend/views/business-card/_card', ['consultant' => $consultant, 'facilities' => $facilities]) ?>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
