<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * One parsed RSS <enclosure> / Atom rel="enclosure" link / Media RSS
 * <media:content>, exposing SimplePie's enclosure method API.
 *
 * This is real, working enclosure support - something the previous
 * (Joomla-Feed-API-based) adapter attempt could never provide at all,
 * since that API has no enclosure concept whatsoever.
 *
 * Known gap: <media:thumbnail> (a separate tag from <media:content>) is
 * not parsed, so get_thumbnail() always returns null here. If your
 * feeds use Media RSS thumbnails and you want them picked up the way
 * FeedgatorHelper::processEnclosures() supports, extend
 * SimplePieFeedAdapter::buildItemFromRssNode() to read
 * $item->children($namespaces['media'])->thumbnail similarly to how
 * media:content is already handled there.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Helper;

\defined('_JEXEC') or die;

class SimplePieEnclosureAdapter
{
    private string $link;
    private string $mimeType;
    private int $lengthBytes;

    public function __construct(string $link, string $mimeType = '', string $length = '')
    {
        $this->link        = $link;
        $this->mimeType     = $mimeType;
        $this->lengthBytes  = (int) $length;
    }

    public function get_link()
    {
        return $this->link;
    }

    public function get_type()
    {
        return $this->mimeType;
    }

    public function get_real_type()
    {
        return $this->mimeType ?: $this->guessTypeFromExtension();
    }

    public function get_thumbnail()
    {
        // See class docblock - <media:thumbnail> isn't parsed separately.
        return null;
    }

    public function get_title()
    {
        return '';
    }

    public function get_caption()
    {
        return '';
    }

    public function get_duration()
    {
        return null;
    }

    public function get_size()
    {
        if (!$this->lengthBytes) {
            return null;
        }

        return round($this->lengthBytes / (1024 * 1024), 2);
    }

    public function get_extension()
    {
        $path = parse_url($this->link, PHP_URL_PATH) ?: $this->link;
        $ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $ext ? '.' . $ext : '';
    }

    private function guessTypeFromExtension(): string
    {
        return match ($this->get_extension()) {
            '.mp3' => 'audio/mpeg',
            '.mp4' => 'video/mp4',
            '.jpg', '.jpeg' => 'image/jpeg',
            '.png' => 'image/png',
            '.gif' => 'image/gif',
            '.pdf' => 'application/pdf',
            '.zip' => 'application/zip',
            default => '',
        };
    }
}
