<?php
if (isset($covers) && !empty($covers)):
    $coversCollection = explode(', ', $covers);
    foreach ($coversCollection as $c):
        echo '<span class="badge bg-light text-dark fw-normal p-2">' . $c . '</span>';
    endforeach;
endif;