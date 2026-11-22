<?php

namespace App\Support;

/**
 * Splits a whole-rupiah amount across weights, proportionally and by largest remainder, so
 * every share is a whole rupiah and the shares add up to the amount exactly. Ties keep the
 * input order. Used for refunds (sale total over its lines) and for per-product reports.
 */
final class Apportion
{
    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights  non-negative
     * @return array<TKey, int>
     */
    public static function byWeight(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum === 0) {
            return array_map(fn () => 0, $weights);
        }

        $shares = [];
        $remainders = [];
        foreach ($weights as $key => $weight) {
            $exact = $amount * $weight;
            $shares[$key] = intdiv($exact, $sum);
            $remainders[$key] = $exact % $sum;
        }

        $left = $amount - array_sum($shares);
        arsort($remainders);
        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            $shares[$key]++;
            $left--;
        }

        return $shares;
    }
}
