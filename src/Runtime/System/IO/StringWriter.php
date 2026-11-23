<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\IO;

/**
 * System.IO.StringWriter: a TextWriter that collects the text (writer.ToString()).
 */
class StringWriter extends TextWriter
{
    private string $text = '';

    protected function append(string $text): void
    {
        $this->text .= $text;
    }

    public function ToString(): string
    {
        return $this->text;
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
