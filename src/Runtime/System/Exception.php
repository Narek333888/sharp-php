<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System;

use SharpPhp\Runtime\TypeName;

/**
 * System.Exception. Catching "Exception" in SharpPHP catches every PHP \Throwable.
 */
class Exception extends \Exception
{
    /** The message of "new X()" without one; null means "Exception of type 'X' was thrown." */
    protected const ?string DEFAULT_MESSAGE = null;

    public string $Message {
        get => $this->getMessage();
    }

    public ?\Throwable $InnerException {
        get => $this->getPrevious();
    }

    public string $StackTrace {
        get => $this->getTraceAsString();
    }

    public function __construct(?string $message = null, ?\Throwable $innerException = null)
    {
        parent::__construct(
            $message ?? static::DEFAULT_MESSAGE ?? sprintf("Exception of type '%s' was thrown.", TypeName::ofClass(static::class)),
            0,
            $innerException,
        );
    }

    /** @internal Used by the runtime to report the location of converted PHP warnings. */
    public function withLocation(string $file, int $line): static
    {
        $this->file = $file;
        $this->line = $line;

        return $this;
    }

    /**
     * e.ToString(): the .NET type name and the message ("System.InvalidOperationException: boom"); unlike .NET, without
     * the inner exception and the stack trace.
     */
    public function ToString(): string
    {
        return TypeName::ofClass(static::class) . ': ' . $this->getMessage();
    }

    /** e.GetType(): the exception's Type, named as in .NET (System.InvalidOperationException, App.NotFoundException). */
    public function GetType(): Type
    {
        return Type::fromValue($this);
    }
}
