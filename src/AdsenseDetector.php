<?php
namespace AdGuard;

/** Detects AdSense-bearing HTML and disables only its executable loader. */
class AdsenseDetector
{
    const BOOTSTRAP_URL = 'pagead2.googlesyndication.com/pagead/js/adsbygoogle.js';

    public function containsAdsense($html)
    {
        if (!is_string($html) || $html === '') {
            return false;
        }

        return stripos($html, self::BOOTSTRAP_URL) !== false
            || stripos($html, 'data-ad-client') !== false
            || stripos($html, 'adsbygoogle') !== false;
    }

    /**
     * Inventory server-rendered AdSense opportunities without claiming an
     * impression or click. Reused slot IDs are disambiguated by DOM order so
     * a page's top/bottom placements remain separate in the operator report.
     */
    public function inventory($html)
    {
        $inventory = array(
            'bootstrap_count' => $this->bootstrapCount($html),
            'manual_unit_count' => 0,
            'manual_units' => array(),
            'truncated' => false,
        );
        if (!is_string($html) || $html === '') {
            return $inventory;
        }

        $matches = array();
        $ok = preg_match_all('~<ins\b[^>]*>~is', $html, $matches);
        if ($ok === false || empty($matches[0])) {
            return $inventory;
        }

        $ordinals = array();
        foreach ($matches[0] as $tag) {
            $class = strtolower($this->attribute($tag, 'class'));
            $slot = $this->attribute($tag, 'data-ad-slot');
            $client = $this->attribute($tag, 'data-ad-client');
            if (strpos(' ' . preg_replace('/\s+/', ' ', $class) . ' ', ' adsbygoogle ') === false
                && $slot === '' && $client === '') {
                continue;
            }

            $inventory['manual_unit_count']++;
            if (count($inventory['manual_units']) >= 24) {
                $inventory['truncated'] = true;
                continue;
            }

            $slot = $this->safeLabel($slot !== '' ? $slot : 'unlabeled', 80);
            $format = $this->safeLabel($this->attribute($tag, 'data-ad-format'), 40);
            if ($format === '') {
                $format = 'default';
            }
            $ordinalKey = $slot;
            if (!isset($ordinals[$ordinalKey])) {
                $ordinals[$ordinalKey] = 0;
            }
            $ordinals[$ordinalKey]++;
            $inventory['manual_units'][] = array(
                'slot' => $slot,
                'format' => $format,
                'ordinal' => $ordinals[$ordinalKey],
            );
        }
        return $inventory;
    }

    /** Count exact official loader URL occurrences in the final HTML. */
    public function bootstrapCount($html)
    {
        if (!is_string($html) || $html === '') {
            return 0;
        }
        return substr_count(strtolower($html), strtolower(self::BOOTSTRAP_URL));
    }

    /**
     * Remove script/preload elements that can execute or fetch the AdSense
     * bootstrap. Manual <ins> units and page content are intentionally left
     * untouched; without the bootstrap they are inert.
     */
    public function removeBootstrap($html, &$removedCount)
    {
        $removedCount = 0;
        $needle = self::BOOTSTRAP_URL;
        if (!is_string($html) || stripos($html, $needle) === false) {
            return $html;
        }

        $original = $html;
        $patterns = array(
            // Ordinary <script>...</script>, including inline scripts that
            // build the bootstrap URL themselves.
            '~<script\b[^>]*>.*?</script\s*>~is',
            // Non-standard self-closing script markup.
            '~<script\b[^>]*/\s*>~is',
            // A preload/prefetch would still contact Google's ad host even
            // with the script element gone.
            '~<link\b[^>]*>~is',
        );

        $count = 0;
        $working = $html;
        foreach ($patterns as $pattern) {
            $result = preg_replace_callback(
                $pattern,
                function ($matches) use ($needle, &$count) {
                    if (stripos($matches[0], $needle) !== false) {
                        $count++;
                        return '';
                    }
                    return $matches[0];
                },
                $working
            );

            /*
             * A PCRE failure (backtrack/recursion limit on a large or
             * malformed page -- an unclosed <script> makes the lazy match scan
             * the whole remaining document) returns null. Continuing would
             * feed null into the next pass and ultimately return an empty
             * string, i.e. serve the visitor a BLANK PAGE. Confirmed
             * reproducible before this guard was added.
             *
             * Delivering the page with ads left in place is a far smaller
             * failure than destroying the page, so abandon the edit entirely
             * and hand back the untouched HTML. removedCount stays 0, and the
             * audit log's ads_served field records that the bootstrap
             * survived.
             */
            if (!is_string($result)) {
                $removedCount = 0;
                return $original;
            }
            $working = $result;
        }

        $removedCount = $count;
        return $working;
    }

    private function attribute($tag, $name)
    {
        $pattern = '~\b' . preg_quote((string)$name, '~')
            . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i';
        $match = array();
        if (!preg_match($pattern, (string)$tag, $match)) {
            return '';
        }
        if (isset($match[1]) && $match[1] !== '') {
            return $match[1];
        }
        if (isset($match[2]) && $match[2] !== '') {
            return $match[2];
        }
        return isset($match[3]) ? $match[3] : '';
    }

    private function safeLabel($value, $limit)
    {
        $value = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', trim((string)$value));
        return substr((string)$value, 0, (int)$limit);
    }
}
