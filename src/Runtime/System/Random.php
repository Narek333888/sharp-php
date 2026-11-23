<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use Random\Randomizer;

/**
 * System.Random. With a seed it is .NET's seeded generator (Knuth's subtractive method, as .NET
 * Framework and .NET's Random(seed) compute it), so new Random(42) gives the numbers it gives in C#.
 * Without one, PHP's secure randomizer.
 */
class Random
{
    private const int MSEED = 161803398;
    private const int MBIG = 2147483647;

    private ?Randomizer $randomizer = null;

    /** @var array<int, int> */
    private array $seedArray = [];

    private int $inext = 0;

    private int $inextp = 21;

    public function __construct(?int $seed = null)
    {
        if ($seed === null) {
            $this->randomizer = new Randomizer();

            return;
        }
        $subtraction = $seed === -2147483648 ? self::MBIG : abs($seed);
        $mj = self::MSEED - $subtraction;
        $this->seedArray = array_fill(0, 56, 0);
        $this->seedArray[55] = $mj;
        $mk = 1;
        for ($i = 1; $i < 55; $i++) {
            $ii = (21 * $i) % 55;
            $this->seedArray[$ii] = $mk;
            $mk = $mj - $mk;
            if ($mk < 0) {
                $mk += self::MBIG;
            }
            $mj = $this->seedArray[$ii];
        }
        for ($k = 1; $k < 5; $k++) {
            for ($i = 1; $i < 56; $i++) {
                $this->seedArray[$i] -= $this->seedArray[1 + ($i + 30) % 55];
                if ($this->seedArray[$i] < 0) {
                    $this->seedArray[$i] += self::MBIG;
                }
            }
        }
    }

    /**
     * Next() => [0, int.MaxValue), Next(max) => [0, max), Next(min, max) => [min, max)
     */
    public function Next(?int $minOrMax = null, ?int $max = null): int
    {
        [$min, $max] = match (true) {
            $minOrMax === null => [0, Int32::MaxValue],
            $max === null => [0, $minOrMax],
            default => [$minOrMax, $max],
        };
        if ($max < $min) {
            throw $minOrMax !== null && func_num_args() === 1
                ? new ArgumentOutOfRangeException('maxValue', "'maxValue' must be greater than or equal to '0'.")
                : new ArgumentOutOfRangeException('minValue', "'minValue' cannot be greater than maxValue.");
        }
        if ($this->randomizer !== null) {
            return $max === $min ? $min : $this->randomizer->getInt($min, $max - 1);
        }
        if (func_num_args() === 0) {
            return $this->internalSample();
        }
        $range = $max - $min;
        if ($range <= self::MBIG) {
            return (int) ($this->sample() * $range) + $min;
        }

        return (int) ($this->largeRangeSample() * $range) + $min;
    }

    /** [0.0, 1.0) */
    public function NextDouble(): float
    {
        return $this->randomizer !== null ? $this->randomizer->getFloat(0, 1) : $this->sample();
    }

    private function sample(): float
    {
        return $this->internalSample() * (1.0 / self::MBIG);
    }

    private function internalSample(): int
    {
        $locINext = $this->inext + 1;
        if ($locINext >= 56) {
            $locINext = 1;
        }
        $locINextp = $this->inextp + 1;
        if ($locINextp >= 56) {
            $locINextp = 1;
        }
        $retVal = $this->seedArray[$locINext] - $this->seedArray[$locINextp];
        if ($retVal === self::MBIG) {
            $retVal--;
        }
        if ($retVal < 0) {
            $retVal += self::MBIG;
        }
        $this->seedArray[$locINext] = $retVal;
        $this->inext = $locINext;
        $this->inextp = $locINextp;

        return $retVal;
    }

    /** For a range larger than int.MaxValue: two samples, as .NET's GetSampleForLargeRange. */
    private function largeRangeSample(): float
    {
        $result = $this->internalSample();
        if ($this->internalSample() % 2 === 0) {
            $result = -$result;
        }
        $d = (float) $result;
        $d += self::MBIG - 1;
        $d /= 2.0 * self::MBIG - 1;

        return $d;
    }
}
