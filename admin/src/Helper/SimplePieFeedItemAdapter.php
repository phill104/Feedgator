<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * One parsed feed item, exposing the subset of SimplePie's per-item
 * method API that helpers/feedgator.helper.php relies on. Built directly
 * from RSS/Atom XML by SimplePieFeedAdapter - see that class's docblock
 * for the full context on why this exists instead of wrapping either
 * real SimplePie or Joomla's built-in Feed API.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Helper;

\defined('_JEXEC') or die;

class SimplePieFeedItemAdapter
{
    /**
     * @param   SimplePieEnclosureAdapter[]  $enclosures
     */
    public function __construct(
        private string $title,
        private string $permalink,
        private string $content,
        private string $description,
        private string $date,
        private string $authorName,
        private string $id,
        private ?string $category,
        private array $enclosures = []
    ) {
    }

    public function get_id()
    {
        return $this->id ?: md5($this->title . $this->permalink);
    }

    public function get_title()
    {
        return $this->title;
    }

    public function get_permalink()
    {
        return $this->permalink;
    }

    public function get_content()
    {
        // Prefer full content (e.g. RSS content:encoded / Atom <content>)
        // and fall back to the summary/description if a feed only
        // provides one of the two - matches the original get_content()
        // vs get_description() distinction closely enough for
        // processFeedItem()'s "show_html" toggle to still make sense.
        return $this->content ?: $this->description;
    }

    public function get_description()
    {
        return $this->description ?: $this->content;
    }

    public function get_date($format = null)
    {
        // pubDate (RSS, RFC 2822) and published/updated (Atom, ISO 8601)
        // are both parseable by PHP's strtotime() without needing to
        // know which format a given feed used.
        $timestamp = $this->date ? strtotime($this->date) : false;

        if ($timestamp === false) {
            return $this->date;
        }

        return $format ? date($format, $timestamp) : date('Y-m-d H:i:s', $timestamp);
    }

    public function get_author()
    {
        return $this->authorName ? new SimplePieAuthorAdapter($this->authorName, '') : null;
    }

    /**
     * @return SimplePieEnclosureAdapter[]
     */
    public function get_enclosures()
    {
        return $this->enclosures;
    }

    /**
     * RSS <category> - not exposed by Atom parsing here. Only used by
     * FeedgatorHelper when the "save_feed_cats" setting is on.
     */
    public function get_category()
    {
        return $this->category ? new SimplePieCategoryAdapter($this->category) : null;
    }
}
