<?php
namespace RiskEngine\KnownCrawlers;

/**
 * Reads the locally cached crawler range file that the refresh CLI writes.
 *
 * The cache is a plain PHP file returning an array, so including it is cheap
 * and opcode-cacheable -- no JSON parsing on the request path.
 *
 * Every failure mode (absent, unreadable, malformed, stale) yields an EMPTY
 * verifier rather than an exception. A missing cache must degrade crawler
 * classification to "unavailable"; it must never change anyone's risk score
 * and must never take a page down.
 */
class RangeCache
{
    /** Seconds after which a cache is considered stale. */
    const DEFAULT_MAX_AGE = 1209600; // 14 days

    private $path;
    private $maxAge;
    private $status = 'not_loaded';
    private $meta = array();

    public function __construct($path, $maxAge = null)
    {
        $this->path = (string)$path;
        $this->maxAge = $maxAge === null ? self::DEFAULT_MAX_AGE : max(0, (int)$maxAge);
    }

    /**
     * A verifier that loads this cache only if something actually needs it.
     *
     * Building the range sets costs milliseconds and hundreds of kilobytes,
     * and the overwhelming majority of requests are browsers that never claim
     * to be a crawler. Handing the verifier a deferred loader keeps that cost
     * off the common path entirely; status()/meta() stay empty until the load
     * really happens.
     *
     * @param int|null $now unix time, injectable for tests
     * @return CrawlerVerifier
     */
    public function lazyVerifier($now = null)
    {
        $self = $this;
        return new CrawlerVerifier(function () use ($self, $now) {
            return $self->rangeSets($now);
        });
    }

    /**
     * @param int|null $now unix time, injectable for tests
     * @return CrawlerVerifier always usable; empty when the cache is not.
     *                         Loads immediately -- prefer lazyVerifier() on
     *                         the request path.
     */
    public function verifier($now = null)
    {
        return new CrawlerVerifier($this->rangeSets($now));
    }

    /**
     * @param int|null $now unix time, injectable for tests
     * @return array vendor => group => IpRangeSet (empty on any failure)
     */
    public function rangeSets($now = null)
    {
        $now = $now === null ? time() : (int)$now;
        $data = $this->load();
        if ($data === null) {
            return array();
        }

        $generatedAt = isset($data['generated_at']) ? (int)$data['generated_at'] : 0;
        if ($generatedAt <= 0) {
            $this->status = 'malformed';
            return array();
        }
        if ($this->maxAge > 0 && ($now - $generatedAt) > $this->maxAge) {
            $this->status = 'stale';
            $this->meta['age_seconds'] = $now - $generatedAt;
            return array();
        }

        $sets = array();
        $vendors = isset($data['vendors']) && is_array($data['vendors']) ? $data['vendors'] : array();
        foreach ($vendors as $vendor => $entries) {
            if (!is_array($entries)) {
                continue;
            }
            $grouped = array();
            foreach ($entries as $groupOrEntry => $groupEntries) {
                // Current cache format is vendor => [[cidr, group], ...].
                // Also accept vendor => [group => [[cidr, group], ...]] so
                // future refreshers can preserve groups without ambiguity.
                if (is_array($groupEntries) && isset($groupEntries[0]) && is_array($groupEntries[0])) {
                    $groupName = (string)$groupOrEntry;
                    foreach ($groupEntries as $entry) {
                        if (!is_array($entry) || !isset($entry[0])) {
                            continue;
                        }
                        $entryGroup = isset($entry[1]) ? (string)$entry[1] : $groupName;
                        if ($entryGroup === '') {
                            $entryGroup = '*';
                        }
                        if (!isset($grouped[$entryGroup])) {
                            $grouped[$entryGroup] = new IpRangeSet();
                        }
                        $grouped[$entryGroup]->add((string)$entry[0], $entryGroup);
                    }
                    continue;
                }
                if (!is_array($groupEntries) || !isset($groupEntries[0])) {
                    continue;
                }
                $entry = $groupEntries;
                $groupName = isset($entry[1]) ? (string)$entry[1] : '*';
                if ($groupName === '') {
                    $groupName = '*';
                }
                if (!isset($grouped[$groupName])) {
                    $grouped[$groupName] = new IpRangeSet();
                }
                $grouped[$groupName]->add((string)$entry[0], $groupName);
            }
            $usableGroups = array();
            foreach ($grouped as $group => $set) {
                if ($set instanceof IpRangeSet && $set->count() > 0) {
                    $usableGroups[(string)$group] = $set;
                }
            }
            if ($usableGroups) {
                $sets[(string)$vendor] = $usableGroups;
            }
        }

        if (!$sets) {
            $this->status = 'empty';
            return array();
        }

        $this->status = 'ok';
        $this->meta['age_seconds'] = $now - $generatedAt;
        $this->meta['vendors'] = array();
        foreach ($sets as $vendor => $groups) {
            $this->meta['vendors'][$vendor] = array();
            foreach ((array)$groups as $group => $set) {
                if ($set instanceof IpRangeSet) {
                    $this->meta['vendors'][$vendor][$group] = $set->count();
                }
            }
        }
        return $sets;
    }

    /** 'ok' | 'absent' | 'unreadable' | 'malformed' | 'stale' | 'empty' | 'not_loaded' */
    public function status()
    {
        return $this->status;
    }

    public function meta()
    {
        return $this->meta;
    }

    private function load()
    {
        if ($this->path === '' || !is_file($this->path)) {
            $this->status = 'absent';
            return null;
        }
        if (!is_readable($this->path)) {
            $this->status = 'unreadable';
            return null;
        }
        /*
         * The cache is generated by our own CLI into a directory the package
         * controls. Still, a truncated or half-written file must not be fatal:
         * a parse error inside include is not catchable in PHP 5.6, so the
         * writer commits atomically via rename() and we validate the shape.
         */
        $data = @include $this->path;
        if (!is_array($data)) {
            $this->status = 'malformed';
            return null;
        }
        return $data;
    }
}
