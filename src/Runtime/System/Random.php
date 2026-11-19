<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * System.Random
 */
class Random
{
    private readonly Randomizer $randomizer;

    public function __construct(?int $seed = null)
    {
        $this->randomizer = new Randomizer($seed === null ? null : new Mt19937($seed));
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
            throw new ArgumentOutOfRangeException('minValue', "'minValue' cannot be greater than maxValue.");
        }

        return $max === $min ? $min : $this->randomizer->getInt($min, $max - 1);
    }

    public function NextDouble(): float
    {
        return $this->randomizer->getFloat(0, 1);
    }
}
