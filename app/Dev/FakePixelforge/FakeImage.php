<?php

namespace App\Dev\FakePixelforge;

/**
 * DEV ONLY. A 256×256 PNG with a vertical gradient whose colours come from the generation id,
 * built by hand so the fake needs no image extension.
 */
final class FakeImage
{
    private const SIZE = 256;

    public static function png(string $seed): string
    {
        $hash = md5($seed, true);
        $top = [ord($hash[0]), ord($hash[1]), ord($hash[2])];
        $bottom = [ord($hash[3]), ord($hash[4]), ord($hash[5])];

        $pixels = '';
        for ($y = 0; $y < self::SIZE; $y++) {
            $rgb = [];
            foreach ([0, 1, 2] as $channel) {
                $rgb[] = intdiv($top[$channel] * (self::SIZE - 1 - $y) + $bottom[$channel] * $y, self::SIZE - 1);
            }
            // Each row starts with filter type 0 (none).
            $pixels .= "\0".str_repeat(pack('C3', ...$rgb), self::SIZE);
        }

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', pack('NNCCCCC', self::SIZE, self::SIZE, 8, 2, 0, 0, 0))
            .self::chunk('IDAT', (string) gzcompress($pixels))
            .self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
