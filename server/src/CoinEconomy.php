<?php

declare(strict_types=1);

namespace SpeedyTapper;

final class CoinEconomy
{
    /**
     * Apply a gameplay or achievement credit. Refund debt is cleared first,
     * then earned-value moderation debt, before any remainder is spendable.
     *
     * @return array{
     *   earnedCoins: int,
     *   purchasedCoins: int,
     *   earnedDebt: int,
     *   refundDebt: int,
     *   earnedDebtPaid: int,
     *   refundDebtPaid: int
     * }
     */
    public static function applyEarnedCredit(
        int $earnedCoins,
        int $purchasedCoins,
        int $earnedDebt,
        int $refundDebt,
        int $grossCredit,
    ): array {
        self::assertBuckets($earnedCoins, $purchasedCoins, $earnedDebt, $refundDebt);
        self::assertNonNegative($grossCredit, 'Coin credit');

        $refundDebtPaid = min($refundDebt, $grossCredit);
        $afterRefundDebt = $grossCredit - $refundDebtPaid;
        $earnedDebtPaid = min($earnedDebt, $afterRefundDebt);

        return [
            'earnedCoins' => $earnedCoins + $afterRefundDebt - $earnedDebtPaid,
            'purchasedCoins' => $purchasedCoins,
            'earnedDebt' => $earnedDebt - $earnedDebtPaid,
            'refundDebt' => $refundDebt - $refundDebtPaid,
            'earnedDebtPaid' => $earnedDebtPaid,
            'refundDebtPaid' => $refundDebtPaid,
        ];
    }

    /**
     * Apply a verified purchase or refund-reversal credit without repaying earned debt.
     *
     * @return array{
     *   earnedCoins: int,
     *   purchasedCoins: int,
     *   earnedDebt: int,
     *   refundDebt: int,
     *   refundDebtPaid: int
     * }
     */
    public static function applyPurchasedCredit(
        int $earnedCoins,
        int $purchasedCoins,
        int $earnedDebt,
        int $refundDebt,
        int $grossCredit,
    ): array {
        self::assertBuckets($earnedCoins, $purchasedCoins, $earnedDebt, $refundDebt);
        self::assertNonNegative($grossCredit, 'Coin credit');

        $refundDebtPaid = min($refundDebt, $grossCredit);

        return [
            'earnedCoins' => $earnedCoins,
            'purchasedCoins' => $purchasedCoins + $grossCredit - $refundDebtPaid,
            'earnedDebt' => $earnedDebt,
            'refundDebt' => $refundDebt - $refundDebtPaid,
            'refundDebtPaid' => $refundDebtPaid,
        ];
    }

    /**
     * Spend earned coins first and purchased coins only for any remainder.
     *
     * @return array{
     *   earnedCoins: int,
     *   purchasedCoins: int,
     *   earnedDebt: int,
     *   refundDebt: int,
     *   earnedSpent: int,
     *   purchasedSpent: int
     * }
     */
    public static function spendEarnedFirst(
        int $earnedCoins,
        int $purchasedCoins,
        int $earnedDebt,
        int $refundDebt,
        int $amount,
    ): array {
        self::assertBuckets($earnedCoins, $purchasedCoins, $earnedDebt, $refundDebt);
        self::assertNonNegative($amount, 'Coin spend');

        if ($amount > self::totalCoins($earnedCoins, $purchasedCoins)) {
            throw new \InvalidArgumentException('Coin spend exceeds the available balance.');
        }

        $earnedSpent = min($earnedCoins, $amount);
        $purchasedSpent = $amount - $earnedSpent;

        return [
            'earnedCoins' => $earnedCoins - $earnedSpent,
            'purchasedCoins' => $purchasedCoins - $purchasedSpent,
            'earnedDebt' => $earnedDebt,
            'refundDebt' => $refundDebt,
            'earnedSpent' => $earnedSpent,
            'purchasedSpent' => $purchasedSpent,
        ];
    }

    /**
     * Reverse purchased value without ever consuming earned coins.
     *
     * Any amount no longer present in the purchased bucket becomes refund debt.
     * A later verified purchased credit can repay that debt.
     *
     * @return array{
     *   earnedCoins: int,
     *   purchasedCoins: int,
     *   earnedDebt: int,
     *   refundDebt: int,
     *   purchasedCoinsReversed: int,
     *   refundDebtAdded: int
     * }
     */
    public static function applyPurchasedReversal(
        int $earnedCoins,
        int $purchasedCoins,
        int $earnedDebt,
        int $refundDebt,
        int $grossReversal,
    ): array {
        self::assertBuckets($earnedCoins, $purchasedCoins, $earnedDebt, $refundDebt);
        self::assertNonNegative($grossReversal, 'Purchased coin reversal');

        $purchasedCoinsReversed = min($purchasedCoins, $grossReversal);
        $refundDebtAdded = $grossReversal - $purchasedCoinsReversed;

        return [
            'earnedCoins' => $earnedCoins,
            'purchasedCoins' => $purchasedCoins - $purchasedCoinsReversed,
            'earnedDebt' => $earnedDebt,
            'refundDebt' => $refundDebt + $refundDebtAdded,
            'purchasedCoinsReversed' => $purchasedCoinsReversed,
            'refundDebtAdded' => $refundDebtAdded,
        ];
    }

    /**
     * Build a four-bucket state from an earned-only net entitlement.
     *
     * @return array{earnedCoins: int, purchasedCoins: int, earnedDebt: int, refundDebt: int}
     */
    public static function fromEarnedNet(
        int $netEarnedCoins,
        int $purchasedCoins = 0,
        int $refundDebt = 0,
    ): array {
        self::assertNonNegative($purchasedCoins, 'Purchased coin balance');
        self::assertNonNegative($refundDebt, 'Refund debt');
        return [
            'earnedCoins' => max(0, $netEarnedCoins),
            'purchasedCoins' => $purchasedCoins,
            'earnedDebt' => max(0, -$netEarnedCoins),
            'refundDebt' => $refundDebt,
        ];
    }

    /**
     * Return provenance buckets together with aggregate compatibility fields.
     *
     * The aggregate `coins` and `debt` values may both be positive because debt
     * from one provenance must not consume value from the other provenance.
     *
     * @return array{
     *   earnedCoins: int,
     *   purchasedCoins: int,
     *   earnedDebt: int,
     *   refundDebt: int,
     *   coins: int,
     *   debt: int,
     *   net: int
     * }
     */
    public static function summary(
        int $earnedCoins,
        int $purchasedCoins,
        int $earnedDebt,
        int $refundDebt,
    ): array {
        self::assertBuckets($earnedCoins, $purchasedCoins, $earnedDebt, $refundDebt);

        $coins = self::totalCoins($earnedCoins, $purchasedCoins);
        $debt = self::totalDebt($earnedDebt, $refundDebt);

        return [
            'earnedCoins' => $earnedCoins,
            'purchasedCoins' => $purchasedCoins,
            'earnedDebt' => $earnedDebt,
            'refundDebt' => $refundDebt,
            'coins' => $coins,
            'debt' => $debt,
            'net' => $coins - $debt,
        ];
    }

    public static function totalCoins(int $earnedCoins, int $purchasedCoins): int
    {
        self::assertNonNegative($earnedCoins, 'Earned coin balance');
        self::assertNonNegative($purchasedCoins, 'Purchased coin balance');

        return $earnedCoins + $purchasedCoins;
    }

    public static function totalDebt(int $earnedDebt, int $refundDebt): int
    {
        self::assertNonNegative($earnedDebt, 'Earned coin debt');
        self::assertNonNegative($refundDebt, 'Refund debt');

        return $earnedDebt + $refundDebt;
    }

    public static function bucketNet(
        int $earnedCoins,
        int $purchasedCoins,
        int $earnedDebt,
        int $refundDebt,
    ): int {
        $summary = self::summary($earnedCoins, $purchasedCoins, $earnedDebt, $refundDebt);

        return $summary['net'];
    }

    /**
     * Legacy aggregate credit helper retained while database services migrate.
     *
     * @return array{coins: int, debt: int, debtPaid: int}
     */
    public static function applyCredit(int $coins, int $debt, int $grossCredit): array
    {
        self::assertLegacyWallet($coins, $debt);
        $wallet = self::applyEarnedCredit($coins, 0, $debt, 0, $grossCredit);

        return [
            'coins' => $wallet['earnedCoins'],
            'debt' => $wallet['earnedDebt'],
            'debtPaid' => $wallet['earnedDebtPaid'],
        ];
    }

    /** @return array{coins: int, debt: int} */
    public static function fromNet(int $netCoins): array
    {
        $wallet = self::fromEarnedNet($netCoins);

        return [
            'coins' => $wallet['earnedCoins'],
            'debt' => $wallet['earnedDebt'],
        ];
    }

    public static function net(int $coins, int $debt): int
    {
        self::assertLegacyWallet($coins, $debt);

        return $coins - $debt;
    }

    private static function assertBuckets(
        int $earnedCoins,
        int $purchasedCoins,
        int $earnedDebt,
        int $refundDebt,
    ): void {
        self::assertNonNegative($earnedCoins, 'Earned coin balance');
        self::assertNonNegative($purchasedCoins, 'Purchased coin balance');
        self::assertNonNegative($earnedDebt, 'Earned coin debt');
        self::assertNonNegative($refundDebt, 'Refund debt');

        if ($earnedCoins > 0 && $earnedDebt > 0) {
            throw new \InvalidArgumentException('Earned coins and earned debt cannot both be positive.');
        }
        // Refund debt is transaction-specific and must not erase unrelated
        // purchased lots. Both may therefore remain visible at the same time;
        // spending is blocked until later paid credits clear the debt.
    }

    private static function assertLegacyWallet(int $coins, int $debt): void
    {
        self::assertNonNegative($coins, 'Coin balance');
        self::assertNonNegative($debt, 'Coin debt');
        if ($coins > 0 && $debt > 0) {
            throw new \InvalidArgumentException('Coin wallet state is invalid.');
        }
    }

    private static function assertNonNegative(int $amount, string $label): void
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException($label . ' cannot be negative.');
        }
    }
}
