<?php

declare(strict_types=1);

namespace Birinchishop;

/**
 * Tracking-link helper.
 */
final class TrackingUrl
{
    /**
     * Fill a link's `tracking_url_template` (`click_id`, `sub1`..`sub5`) with your tracker values.
     *
     * Values are URL-encoded, but `{` and `}` are kept so tracker macros such as `{clickid}` keep working.
     * Placeholders you do not pass (e.g. unused sub3..sub5) are removed from the URL.
     *
     * @param array<string, scalar|null> $values e.g. ['click_id' => '{clickid}', 'sub1' => '{campaign_id}']
     */
    public static function fill(string $template, array $values = []): string
    {
        $q = strpos($template, '?');
        $base = $q === false ? $template : substr($template, 0, $q);
        $query = $q === false ? '' : substr($template, $q + 1);
        $parts = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            if (!preg_match('/^([^=]+)=\{([a-z0-9_]+)\}$/', $pair, $m)) {
                $parts[] = $pair;
                continue;
            }
            $v = $values[$m[2]] ?? null;
            if ($v === null || $v === '' || $v === false) {
                continue;
            }
            $enc = str_ireplace(['%7B', '%7D'], ['{', '}'], rawurlencode((string) $v));
            $parts[] = $m[1] . '=' . $enc;
        }

        return $parts === [] ? $base : $base . '?' . implode('&', $parts);
    }
}
