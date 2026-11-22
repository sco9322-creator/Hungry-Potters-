<?php
declare(strict_types=1);

function password_meets_policy(string $password): bool
{
    $characterCount = preg_match_all('/./us', $password);

    return $characterCount !== false
        && $characterCount >= 12
        && strlen($password) <= 72;
}
