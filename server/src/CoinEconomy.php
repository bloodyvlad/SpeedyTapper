<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class CoinEconomy
{
    /** @return array{coins: int, debt: int, debtPaid: int} */
    public static function applyCredit(int $coins, int $debt, int $grossCredit): array
    {
        if ($coins < 0 || $debt < 0 || $grossCredit < 0 || ($coins > 0 && $debt > 0)) {
            throw new \InvalidArgumentException('Coin wallet state is invalid.');
        }
        $debtPaid = min($debt, $grossCredit);
        return [
            'coins' => $coins + $grossCredit - $debtPaid,
            'debt' => $debt - $debtPaid,
            'debtPaid' => $debtPaid,
        ];
    }

    /** @return array{coins: int, debt: int} */
    public static function fromNet(int $netCoins): array
    {
        return [
            'coins' => max(0, $netCoins),
            'debt' => max(0, -$netCoins),
        ];
    }

    public static function net(int $coins, int $debt): int
    {
        if ($coins < 0 || $debt < 0 || ($coins > 0 && $debt > 0)) {
            throw new \InvalidArgumentException('Coin wallet state is invalid.');
        }
        return $coins - $debt;
    }
}
