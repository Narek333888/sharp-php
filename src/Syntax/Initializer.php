<?php

declare(strict_types=1);

namespace SharpPhp\Syntax;

final class Initializer extends Node
{
    public function __construct(
        /** @var list<InitializerItem> */
        public readonly array $items,
    ) {
    }
}
