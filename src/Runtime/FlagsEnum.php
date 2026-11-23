<?php

declare(strict_types=1);

namespace SharpPhp\Runtime;

/**
 * A runtime library enum that is a [Flags] enum in .NET (StringSplitOptions): it has a case for every
 * combination of its flags (the combined ones are internal, named "A_B"), so that A | B, A & B and
 * A ^ B are values of the enum, and ToString() names the flags as .NET does ("A, B").
 *
 * @internal
 */
interface FlagsEnum
{
}
