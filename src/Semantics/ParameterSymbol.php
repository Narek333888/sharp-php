<?php

declare(strict_types=1);

namespace SharpPhp\Semantics;

final class ParameterSymbol
{
    public function __construct(
        public readonly string $name,
        public readonly Type $type,
        public readonly ?string $modifier = null,
        public readonly bool $optional = false,
        /** Declared as int|float in PHP: any number (or char) is accepted. */
        public readonly bool $numeric = false,
    ) {
    }
}
