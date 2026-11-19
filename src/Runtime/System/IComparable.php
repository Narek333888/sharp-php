<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

/**
 * System.IComparable<T>: implementers define "int CompareTo(T other)".
 *
 * The method is not declared here because PHP would not allow implementations to
 * narrow the parameter type (C#'s CompareTo(Person other)). Numbers and strings are
 * comparable too: the compiler late-binds CompareTo on type parameters.
 *
 * @template T
 */
interface IComparable
{
}
