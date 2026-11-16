<?php
declare(strict_types=1);
namespace Poisk;
final class Labor
{
    public static function price(string $model, string $part, ?string $technology): ?int
    {
        if (preg_match('/iphone\s*(\d+)(?!\d)/iu', $model, $m)) {
            $g = (int) $m[1];
            return match ($part) {
                'battery' => 4000, 'display' => $g <= 11 ? 4000 : 5000,
                'charging' => $g <= 11 ? 3000 : 4000,
                'back' => $g <= 11 ? 7000 : ($g <= 16 ? 10000 : null), default => null,
            };
        }
        if (preg_match('/iphone/iu', $model)) return null;
        return match ($part) {
            'battery', 'charging' => 2000,
            'display' => in_array($technology, ['OLED','AMOLED'], true) ? 4000 : (in_array($technology, ['LCD','TFT / IPS'], true) ? 2500 : null),
            default => null,
        };
    }
}
