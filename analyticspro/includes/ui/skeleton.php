<?php
$width = (string) ($width ?? '100%');
$height = (string) ($height ?? '1rem');
$classes = trim((string) ($classes ?? ''));
?>
<span class="ap-skeleton <?= analyticspro_h($classes) ?>" style="width:<?= analyticspro_h($width) ?>;height:<?= analyticspro_h($height) ?>;"></span>
