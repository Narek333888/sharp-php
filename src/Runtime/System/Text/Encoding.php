<?php

declare(strict_types=1);

namespace SharpPhp\Runtime\System\Text;

/**
 * System.Text.Encoding: text to bytes and back.
 *
 *   byte[] bytes = Encoding.UTF8.GetBytes("Hello");
 *   string text = Encoding.UTF8.GetString(bytes);
 *
 * UTF8, ASCII (characters above 127 become '?'), Unicode (UTF-16 little endian), BigEndianUnicode,
 * UTF32 and Latin1.
 */
final class Encoding
{
    public static Encoding $UTF8;
    public static Encoding $ASCII;
    public static Encoding $Unicode;
    public static Encoding $BigEndianUnicode;
    public static Encoding $UTF32;
    public static Encoding $Latin1;

    /** @internal use the static encodings */
    public function __construct(private readonly string $php, public readonly string $WebName, public readonly string $EncodingName)
    {
    }

    /**
     * @return list<int>
     * @sharp-return byte[]
     */
    public function GetBytes(string $s): array
    {
        if ($this->php === 'ASCII') {
            $s = (string) preg_replace('/[^\x00-\x7F]/u', '?', $s);
        }
        $bytes = $this->php === 'UTF-8' || $this->php === 'ASCII' ? $s : mb_convert_encoding($s, $this->php, 'UTF-8');

        return self::bytesOf($bytes);
    }

    /**
     * The bytes of a PHP string, as numbers.
     *
     * @internal
     * @return list<int>
     */
    public static function bytesOf(string $bytes): array
    {
        return array_values(array_map(static fn (mixed $b): int => is_int($b) ? $b : 0, unpack('C*', $bytes) ?: []));
    }

    /** @param array<int> $bytes */
    public function GetString(array $bytes, int $index = 0, ?int $count = null): string
    {
        $raw = pack('C*', ...array_slice($bytes, $index, $count));

        return $this->php === 'UTF-8' || $this->php === 'ASCII' ? mb_scrub($raw, 'UTF-8') : (string) mb_convert_encoding($raw, 'UTF-8', $this->php);
    }

    public function GetByteCount(string $s): int
    {
        return count($this->GetBytes($s));
    }

    public function ToString(): string
    {
        return 'System.Text.' . str_replace(' ', '', $this->EncodingName) . 'Encoding';
    }
}

Encoding::$UTF8 = new Encoding('UTF-8', 'utf-8', 'Unicode (UTF-8)');
Encoding::$ASCII = new Encoding('ASCII', 'us-ascii', 'US-ASCII');
Encoding::$Unicode = new Encoding('UTF-16LE', 'utf-16', 'Unicode');
Encoding::$BigEndianUnicode = new Encoding('UTF-16BE', 'utf-16BE', 'Unicode (Big-Endian)');
Encoding::$UTF32 = new Encoding('UTF-32LE', 'utf-32', 'Unicode (UTF-32)');
Encoding::$Latin1 = new Encoding('ISO-8859-1', 'iso-8859-1', 'Western European (ISO)');
