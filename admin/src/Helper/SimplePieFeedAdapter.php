<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * SECOND ATTEMPT at replacing the original bundled SimplePie 1.3.1.
 *
 * The first attempt wrapped Joomla's built-in Feed API
 * (Joomla\CMS\Feed\FeedFactory). Real-world testing found that API
 * parses channel-level metadata (title etc.) fine but returns *zero*
 * items for at least one real WordPress feed - Joomla's built-in parser
 * is much thinner than SimplePie ever was and evidently doesn't handle
 * some real-world RSS extensions/namespaces WordPress feeds use.
 *
 * Bundling an actual current SimplePie release (1.8.x) was the other
 * option floated in the migration report, but SimplePie's source is
 * split across 50+ class files with no single-file distribution
 * available through this session's tooling (no general network access;
 * only previously-searched pages are fetchable, as rendered HTML rather
 * than raw source) - reliably reconstructing the whole library file by
 * file wasn't practical here.
 *
 * So: this is a small, self-contained RSS 2.0 / RSS 1.0 (RDF) / Atom
 * parser written directly against PHP's SimpleXML, with no external
 * library dependency at all. It exposes the same SimplePie-style method
 * API (get_title(), get_items(), and per-item get_permalink()/
 * get_content()/get_enclosures()/get_author()/get_date()/get_id()) that
 * the rest of this codebase (FeedgatorHelper, FeedModel) already
 * expects, so no other file needs to change.
 *
 * This has NOT been tested against a live Joomla 6 site - only reasoned
 * through against the RSS 2.0/Atom specs. Common real-world cases
 * (WordPress RSS2, content:encoded, dc:creator, standard <enclosure>
 * tags, Atom entries) are handled; deliberately unusual or malformed
 * feeds may still need adjustment. If you hit a feed this doesn't parse
 * correctly, the fix is almost certainly here rather than elsewhere.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Helper;

\defined('_JEXEC') or die;

if (!\defined('SIMPLEPIE_TYPE_NONE')) {
    \define('SIMPLEPIE_TYPE_NONE', 1);
}

class SimplePieFeedAdapter
{
    private string $url = '';
    public ?string $error = null;
    private string $title = '';
    private bool $parsed = false;

    /** @var SimplePieFeedItemAdapter[] */
    private array $items = [];

    private bool $orderByDate = true;
    // 0 = unset - let getUrl()'s own sensible default (45s) apply
    // unless the user has actually configured a feed-specific timeout
    // via set_timeout() (wired to each feed's "Timeout" setting).
    private int $timeout = 0;

    // Fluent setters mirroring SimplePie's original configuration API.
    // Several have no direct equivalent in this lightweight parser and
    // are accepted but currently no-ops.
    public function set_input_encoding($encoding) { /* feed content is converted to UTF-8 during fetch, see FeedgatorUtility::convert_to_utf8() */ }
    public function set_feed_url($url) { $this->url = $url; }
    public function force_fsockopen($force) { /* FeedgatorUtility::getUrl() picks cURL vs fopen automatically */ }
    public function set_cache_location($path) { /* no file-cache support in this parser */ }
    public function enable_cache($enable) { /* no file-cache support in this parser */ }
    public function set_cache_duration($seconds) { /* no file-cache support in this parser */ }
    public function set_stupidly_fast($enable) { /* not applicable */ }
    public function enable_order_by_date($enable) { $this->orderByDate = (bool) $enable; }
    public function set_timeout($seconds) { $this->timeout = (int) $seconds; }

    /**
     * Fetches and parses the feed.
     *
     * @throws \Exception on a hard fetch/parse failure.
     */
    public function init()
    {
        if (!$this->url) {
            $this->error = 'No feed URL set';

            return;
        }

        // Fetch the body directly with no headers attached at all
        // ('noheader' sets CURLOPT_HEADER=0), rather than fetching
        // headers+body together and trying to split them apart
        // afterwards. An earlier version of this method did the
        // latter (reusing FeedgatorUtility::extractHTTP(), designed for
        // scraping arbitrary web pages, not feed XML) and its line-by-
        // line header/body boundary detection turned out to be fragile
        // enough to corrupt/truncate the start of the XML document on
        // at least one real feed, causing "tag mismatch" parse errors
        // a few lines in. Fetching cleanly avoids that whole class of
        // problem.
        $body = FeedgatorUtility::getUrl($this->url, null, 'noheader', null, null, $this->timeout ?: null);

        if (!$body) {
            $this->error = 'Unable to fetch feed URL'
                . (FeedgatorUtility::$lastCurlError ? ' - ' . FeedgatorUtility::$lastCurlError : ' (no cURL error captured - check timeout/DNS)');

            return;
        }

        // No HTTP header available here to detect charset from, so
        // convert_to_utf8() falls back to whatever encoding the XML
        // declaration itself states (or assumes UTF-8, which is by far
        // the most common case for feeds) - see that method.
        $body = FeedgatorUtility::convert_to_utf8($body);
        $body = trim($body);

        if (!$body) {
            $this->error = 'Empty feed response';

            return;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();

        if ($xml === false) {
            $firstError  = $xmlErrors[0]->message ?? 'unknown XML error';
            $this->error = 'XML parse error: ' . trim($firstError);

            return;
        }

        $this->parseDocument($xml);
        $this->parsed = true;

        if ($this->orderByDate) {
            usort($this->items, static function (SimplePieFeedItemAdapter $a, SimplePieFeedItemAdapter $b) {
                return strtotime((string) $b->get_date()) <=> strtotime((string) $a->get_date());
            });
        }
    }

    private function parseDocument(\SimpleXMLElement $xml): void
    {
        $namespaces = $xml->getNamespaces(true);
        $rootName   = $xml->getName();

        if ($rootName === 'feed') {
            $this->parseAtom($xml, $namespaces);
        } elseif ($rootName === 'RDF') {
            $this->parseRdf($xml, $namespaces);
        } else {
            // RSS 2.0 (and 0.9x) - <rss><channel>...
            $this->parseRss2($xml, $namespaces);
        }
    }

    private function parseRss2(\SimpleXMLElement $xml, array $namespaces): void
    {
        $channel = $xml->channel ?? null;

        if (!$channel) {
            $this->error = 'No <channel> element found';

            return;
        }

        $this->title = trim((string) $channel->title);

        foreach ($channel->item as $item) {
            $this->items[] = $this->buildItemFromRssNode($item, $namespaces);
        }
    }

    private function parseRdf(\SimpleXMLElement $xml, array $namespaces): void
    {
        // RSS 1.0: <rdf:RDF><channel>...</channel><item>...</item><item>...</item></rdf:RDF>
        // Items are siblings of <channel>, not nested inside it.
        if (isset($xml->channel)) {
            $this->title = trim((string) $xml->channel->title);
        }

        foreach ($xml->item as $item) {
            $this->items[] = $this->buildItemFromRssNode($item, $namespaces);
        }
    }

    private function parseAtom(\SimpleXMLElement $xml, array $namespaces): void
    {
        $this->title = trim((string) $xml->title);

        foreach ($xml->entry as $entry) {
            $this->items[] = $this->buildItemFromAtomNode($entry, $namespaces);
        }
    }

    private function buildItemFromRssNode(\SimpleXMLElement $item, array $namespaces): SimplePieFeedItemAdapter
    {
        $content  = isset($namespaces['content']) ? (string) $item->children($namespaces['content'])->encoded : '';
        $creator  = isset($namespaces['dc']) ? (string) $item->children($namespaces['dc'])->creator : '';
        $link     = trim((string) $item->link);
        $guid     = trim((string) $item->guid) ?: $link;
        $category = trim((string) $item->category);

        $enclosures = [];

        foreach ($item->enclosure as $enc) {
            $attrs = $enc->attributes();
            $enclosures[] = new SimplePieEnclosureAdapter(
                (string) ($attrs['url'] ?? ''),
                (string) ($attrs['type'] ?? ''),
                (string) ($attrs['length'] ?? '')
            );
        }

        // Media RSS <media:content> is a common enclosure-like extension
        // some feeds use instead of/alongside <enclosure>.
        if (isset($namespaces['media'])) {
            foreach ($item->children($namespaces['media'])->content as $mc) {
                $attrs = $mc->attributes();
                $enclosures[] = new SimplePieEnclosureAdapter(
                    (string) ($attrs['url'] ?? ''),
                    (string) ($attrs['type'] ?? ''),
                    (string) ($attrs['fileSize'] ?? '')
                );
            }
        }

        return new SimplePieFeedItemAdapter(
            title: trim((string) $item->title),
            permalink: $link,
            content: $content,
            description: trim((string) $item->description),
            date: trim((string) $item->pubDate),
            authorName: $creator,
            id: $guid,
            category: $category ?: null,
            enclosures: $enclosures
        );
    }

    private function buildItemFromAtomNode(\SimpleXMLElement $entry, array $namespaces): SimplePieFeedItemAdapter
    {
        $link = '';

        foreach ($entry->link as $l) {
            $attrs = $l->attributes();
            $rel   = (string) ($attrs['rel'] ?? 'alternate');

            if ($rel === 'alternate' || $link === '') {
                $link = (string) ($attrs['href'] ?? '');
            }
        }

        $content = trim((string) $entry->content) ?: trim((string) $entry->summary);
        $author  = '';

        if (isset($entry->author->name)) {
            $author = trim((string) $entry->author->name);
        }

        $enclosures = [];

        foreach ($entry->link as $l) {
            $attrs = $l->attributes();

            if ((string) ($attrs['rel'] ?? '') === 'enclosure') {
                $enclosures[] = new SimplePieEnclosureAdapter(
                    (string) ($attrs['href'] ?? ''),
                    (string) ($attrs['type'] ?? ''),
                    (string) ($attrs['length'] ?? '')
                );
            }
        }

        $date = trim((string) $entry->published) ?: trim((string) $entry->updated);

        return new SimplePieFeedItemAdapter(
            title: trim((string) $entry->title),
            permalink: $link,
            content: $content,
            description: trim((string) $entry->summary),
            date: $date,
            authorName: $author,
            id: trim((string) $entry->id) ?: $link,
            category: null,
            enclosures: $enclosures
        );
    }

    public function get_type()
    {
        return $this->parsed && !$this->error ? 0 : 1;
    }

    public function get_title()
    {
        return $this->title;
    }

    /**
     * @return SimplePieFeedItemAdapter[]
     */
    public function get_items()
    {
        return $this->items;
    }

    public function __destruct()
    {
        $this->items = [];
    }
}
