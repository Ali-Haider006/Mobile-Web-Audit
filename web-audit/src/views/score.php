<?php
/**
 * Score badge: colour + number + word, so identity is never colour alone.
 * @var int|null $score
 */
use Wva\Helpers;

$band = Helpers::scoreBand($score === null ? null : (int) $score);
?>
<span class="score <?= $band ?>" title="<?= Helpers::h(Helpers::scoreLabel($score === null ? null : (int) $score)) ?>">
    <span class="dot"></span>
    <span class="n"><?= $score === null ? '—' : (int) $score ?></span>
    <span class="word"><?= Helpers::h(Helpers::scoreLabel($score === null ? null : (int) $score)) ?></span>
</span>
