<?php
declare(strict_types=1);

function normalize_status(?string $status, ?string $attendance): string {
    $s = strtolower(trim((string)$status));
    if ($s === '' || $s === 'booked') {
        $a = strtolower(trim((string)$attendance));
        if ($a === 'attended') return 'visited';
        if ($a === 'missed') return 'not attended';
        if ($a === 'canceled' || $a === 'cancelled') return 'canceled';
        return 'booked';
    }
    if ($s === 'cancelled') return 'canceled';
    return $s;
}
