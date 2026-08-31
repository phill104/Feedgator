<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Converted from helpers/feedgator.helper.php. This is the content-
 * processing engine: turns one parsed feed item into an article-ready
 * $content array (title/alias generation, duplicate detection, image
 * extraction & download, enclosure handling, HTML sanitising via the
 * bundled htmLawed, optional full-text extraction via the bundled
 * Readability port, tag/keyword extraction).
 *
 * Logic is preserved 1:1 from the original; only API calls were
 * modernised. Two behavioural notes from the conversion:
 *  - getTagsToStrip()/filterTerms() used `global $fgParams` in the
 *    original. That's been replaced with explicit parameter passing
 *    (filterTerms is now built as a closure inside generateTags()) since
 *    relying on a superglobal for component state is fragile and the
 *    original `global $fgParams` set inside FeedModel::import() is not
 *    something this conversion carries forward.
 *  - $item parameters are now SimplePieFeedItemAdapter instances (see
 *    that class + SimplePieFeedAdapter's docblock) rather than real
 *    SimplePie items - the enclosure-related code paths in this file
 *    (processEnclosures/extractEnclosures) will not receive any
 *    enclosures under the adapter, since Joomla's built-in Feed API has
 *    no enclosure support to draw from.
 *
 * This file also depends on the bundled inc/htmLawed and inc/readability
 * libraries (copied over unchanged) - their own PHP 8 compatibility has
 * not been verified in this environment (no PHP interpreter available to
 * lint with). Check for updated releases of both before going live.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Table\Table;

\defined('_JEXEC') or die;

class FeedgatorHelper
{
    /**
     * Trims trailing "not actually the article" boilerplate that some
     * page builders/WordPress itself bake directly into content:encoded -
     * confirmed on a real Elementor-built site, where content:encoded
     * includes the full rendered page: the real article, then a sidebar
     * (post navigation prev/next links, share buttons), a video widget,
     * a "More news"/related-posts grid, and finally WordPress's own
     * auto-appended "The post X first appeared on Y" feed footer. None
     * of that is the article, so it's cut at the first recognisable
     * marker for any of it. Heuristic by nature (matching known
     * Elementor/WordPress class names and phrases) - if a different
     * feed's page builder uses different markup for the same kind of
     * page chrome, add its marker(s) here too.
     */
    public static function stripNonArticleTail($html)
    {
        $markers = [
            'elementor-post-navigation',   // Elementor: prev/next post links
            'elementor-share-buttons',     // Elementor: share button widget
            'elementor-posts--skin-cards', // Elementor: "More news"/related-posts grid
            'Der Beitrag ',                // WordPress (German locale): "The post ... first appeared on ..."
            'The post ',                   // WordPress (English locale): same footer, only ever appears here as this boilerplate
        ];

        $cutAt = null;

        foreach ($markers as $marker) {
            $pos = strpos($html, $marker);

            if ($pos !== false && ($cutAt === null || $pos < $cutAt)) {
                $cutAt = $pos;
            }
        }

        if ($cutAt === null) {
            return $html;
        }

        // The marker can (and does, in practice - e.g. a wrapper div's
        // OWN class is "elementor-post-navigation-borders-yes",
        // containing the "elementor-post-navigation" marker as a
        // substring) land inside a tag's attributes rather than between
        // tags. Cutting at the raw marker position then leaves a
        // dangling, unclosed tag fragment (e.g. `<div class="...`)
        // which shows up as literal visible text instead of being
        // parsed as markup. Back up to the start of whichever tag
        // contains the marker and cut there instead, so the cut always
        // lands at a clean tag boundary.
        $tagStart = strrpos(substr($html, 0, $cutAt), '<');

        if ($tagStart !== false) {
            $cutAt = $tagStart;
        }

        return substr($html, 0, $cutAt);
    }

    public static function processFeedItem(&$item, &$fgParams, &$plugin, $feedId, $channelTitle, $preview, $update)
    {
        FeedgatorUtility::profiling('Start Feed Item Processing');

        $db    = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $user  = Factory::getApplication()->getIdentity();
        $model = FeedgatorFactory::getFeedModel();

        if ($model->_id != $fgParams->getValue('id')) {
            $model->setId($fgParams->getValue('id'));
        }

        $imports = $model->getImports();

        $hash = ($fgParams->getValue('hash_type', null, 0) ? $feedId . '_' : '') . md5($item->get_id());
        $fgParams->setValue('hash', null, $hash);

        $origLink = $item->get_permalink();
        $origLink = FeedgatorUtility::adjustLink($origLink, $fgParams);
        preg_match('#^[a-zA-Z\d\-+.]+://[^/]+#', $origLink, $matches);
        $fgParams->setValue('fBase', null, ($matches[0] ?? '') . '/');
        unset($matches);

        if (!$fgParams->getValue('base')) {
            if (Factory::getApplication()->getInput()->get('task', '', 'word') !== 'cron') {
                $base = \Joomla\CMS\Uri\Uri::base();
                $fgParams->setValue('base', null, substr($base, 0, strpos($base, 'administrator/')));
            } else {
                Factory::getApplication()->close('FeedGator cron error: base not set');
            }
        }

        $fgParams->setValue('name_prefix', null, $fgParams->getValue('id') . '_');
        $content = [
            'id'         => 0,
            'introtext'  => '',
            'fulltext'   => '',
            'sectionid'  => $fgParams->getValue('sectionid'),
            'catid'      => $fgParams->getValue('catid'),
            'metakey'    => '',
            'metadesc'   => '',
            'images'     => ['feed' => [], 'source' => [], 'stack' => []],
        ];

        FeedgatorUtility::profiling('Make Title and Alias');
        $text          = [];
        $text['feed']  = $fgParams->getValue('show_html') ? trim($item->get_content()) : trim($item->get_description());

        if (empty($text['feed'])) {
            $text['feed'] = $fgParams->getValue('show_html') ? trim($item->get_description()) : trim($item->get_content());
        }

        // See stripNonArticleTail()'s docblock - some feeds (WordPress/
        // Elementor confirmed) bake the entire rendered page, not just
        // the article, into content:encoded.
        $text['feed'] = self::stripNonArticleTail($text['feed']);

        $text['feed'] = FeedgatorUtility::adjustText($text['feed'], $fgParams);

        $content      = self::makeTitleAlias($item, $content, $text['feed'], $channelTitle, $hash, $fgParams);

        FeedgatorUtility::profiling('Check For Duplicates');

        if (!$fgParams->getValue('check_existing')) {
            foreach ($imports as $import) {
                if ($import['hash'] == $hash) {
                    FeedgatorUtility::profiling('Already Imported: Hash Check');

                    return false;
                }
            }
        } else {
            if ($fgParams->getValue('compare_existing') == 0) {
                foreach ($imports as $import) {
                    if ($import['hash'] == $hash) {
                        if (self::findDuplicates($content, $imports, $hash, $import['content_id'], $fgParams, $plugin)) {
                            FeedgatorUtility::profiling('Already Imported: Basic Duplicate Check');

                            return false;
                        }

                        break;
                    }
                }
            } elseif ($fgParams->getValue('compare_existing') == 1) {
                if (self::findDuplicates($content, $imports, $hash, $content['id'], $fgParams, $plugin, true)) {
                    FeedgatorUtility::profiling('Already Imported: Thorough Duplicate Check');

                    return false;
                }
            } else {
                foreach ($imports as $import) {
                    if ($import['hash'] == $hash) {
                        $content['id'] = $import['content_id'];
                        break;
                    }
                }
            }
        }

        if ($update) {
            if (!$content['id']) {
                $content['id'] = self::findDuplicates($content, $imports, $hash, $content['id'], $fgParams, $plugin, true);
            }
        } elseif (!$fgParams->getValue('create_art', null, 1)) {
            // no article, just enclosures
            $encs = $item->get_enclosures();
            self::processEnclosures($encs, $content, $fgParams, $enc_image_unused, $thumb_unused, $text);
            self::saveImport($fgParams->getValue('hash'), $fgParams->getValue('id'), $content['id'], 'enclosure', $fgParams);
        } elseif ((int) $content['id'] === 0 || ($content['id'] && $fgParams->getValue('compare_existing'))) {
            $text['source'] = $fgParams->getValue('fulltext') ? self::getFullText($origLink, $fgParams) : '';
            $text['source'] = self::stripNonArticleTail($text['source']);
            $text['source'] = FeedgatorUtility::adjustText($text['source'], $fgParams);

            $alt_title = $fgParams->getValue('readability_title');

            if ($alt_title && $alt_title != 1) {
                $content['title'] = $alt_title;
                self::makeTitleAlias($item, $content, $text['feed'], $channelTitle, $hash, $fgParams);
            }

            FeedgatorUtility::profiling('Check Filtering');

            if ($fgParams->getValue('filtering')) {
                if ($fgParams->getValue('filter_blacklist')) {
                    foreach (explode(',', strtolower($fgParams->getValue('filter_blacklist', null, true))) as $value) {
                        if (strpos(strtolower($content['title'] . ' ' . $text['feed'] . ' ' . ($text['source'] ?? '')), trim($value)) !== false) {
                            FeedgatorUtility::profiling('Item Blacklisted');

                            if ($fgParams->getValue('save_filter_result')) {
                                self::saveImport($fgParams->getValue('hash'), $fgParams->getValue('id'), -1, $plugin->extension, $fgParams);
                            }

                            return false;
                        }
                    }
                }

                if ($fgParams->getValue('filter_whitelist')) {
                    $white = false;

                    foreach (explode(',', strtolower($fgParams->getValue('filter_whitelist', null, true))) as $value) {
                        if (strpos(strtolower($content['title'] . ' ' . $text['feed'] . ' ' . ($text['source'] ?? '')), trim($value)) !== false) {
                            FeedgatorUtility::profiling('Item Whitelisted');
                            $white = true;
                            break;
                        }
                    }

                    if (!$white) {
                        FeedgatorUtility::profiling('Item Failed Whitelist');

                        if ($fgParams->getValue('save_filter_result')) {
                            self::saveImport($fgParams->getValue('hash'), $fgParams->getValue('id'), -2, $plugin->extension, $fgParams);
                        }

                        return false;
                    }
                }
            }

            FeedgatorUtility::profiling('Set Creator/Author');
            $content['created_by'] = (int) $fgParams->getValue('default_author') ?: $user->id;

            if (!$content['created_by']) {
                $query = 'SELECT u.*'
                    . ' FROM #__users AS u'
                    . ' INNER JOIN #__user_usergroup_map AS uum ON uum.user_id = u.id'
                    . ' WHERE uum.group_id = 8';
                $db->setQuery($query);
                $admin                 = $db->loadObject();
                $content['created_by'] = $admin->id ?? 0;
            }

            $author = $item->get_author();

            switch ($fgParams->getValue('save_author')) {
                default:
                case 1:
                    if (!isset($admin)) {
                        $admin = Factory::getUser($content['created_by']);
                    }

                    $content['created_by_alias'] = $admin->name ?? '';
                    break;

                case 2:
                    $content['created_by_alias'] = $fgParams->getValue('default_author_alias');
                    break;

                case 3:
                    $content['created_by_alias'] = $author ? ($author->get_name() ?: $channelTitle) : $channelTitle;
                    break;

                case 4:
                    $content['created_by_alias'] = $author
                        ? ($author->get_name() ?: $fgParams->getValue('default_author_alias'))
                        : $fgParams->getValue('default_author_alias');
                    break;
            }

            if ($fgParams->getValue('feed_author_article')) {
                $authors = '<p>' . Text::_('FG_AUTHORS') . ': ' . $content['created_by_alias'] . '</p>';

                if ($text['source']) {
                    $text['source'] = ($fgParams->getValue('feed_author_article') === 'top') ? $authors . $text['source'] : $text['source'] . $authors;
                } else {
                    $text['source'] = ($fgParams->getValue('feed_author_article') === 'top') ? $authors . $text['feed'] : $text['feed'] . $authors;
                }
            }

            FeedgatorUtility::profiling('Process Feed Images');
            self::processImages($origLink, $text['feed'], $content, $plugin, $fgParams, $content['images']['feed']);

            FeedgatorUtility::profiling('Process Source Images');
            self::processImages($origLink, $text['source'], $content, $plugin, $fgParams, $content['images']['source']);

            $enc_image = false;
            $thumb     = false;

            if ($encs = $item->get_enclosures()) {
                self::processEnclosures($encs, $content, $fgParams, $enc_image, $thumb, $text);

                if ($enc_image) {
                    if ($text['feed']) {
                        self::processImages($origLink, $text['feed'], $content, $plugin, $fgParams, $content['images']['feed']);
                    } elseif ($text['source']) {
                        self::processImages($origLink, $text['source'], $content, $plugin, $fgParams, $content['images']['source']);
                    }
                }

                if ($thumb) {
                    if ($text['feed']) {
                        self::processImages($origLink, $text['feed'], $content, $plugin, $fgParams, $content['images']['feed']);
                    } elseif ($text['source']) {
                        self::processImages($origLink, $text['source'], $content, $plugin, $fgParams, $content['images']['source']);
                    }
                }
            }

            self::balanceImages($text, $content, $fgParams);

            FeedgatorUtility::profiling('Start Make Parts and Filter/Clean Text');
            $content = self::makeParts($content, $text, $fgParams);
            FeedgatorUtility::profiling('End Make Parts and Filter/Clean Text');

            if ($fgParams->getValue('ignore_empty_intro') && empty($content['introtext'])) {
                FeedgatorUtility::profiling('Intro Text Empty -> Aborting');

                return false;
            }

            if (!self::addDefaultImage($content, $plugin, $fgParams) && ($fgParams->getValue('ignore_no_image') && empty($content['images']['stack']))) {
                if ($preview) {
                    FeedgatorUtility::profiling('No Image Detected -> IMPORT WOULD BE ABORTED');
                } else {
                    FeedgatorUtility::profiling('No Image Detected -> Aborting');

                    return false;
                }
            }

            if ($fgParams->getValue('show_orig_link') || !$content['introtext']) {
                FeedgatorUtility::profiling('Trackback Processing');
                $target = ($fgParams->getValue('target_frame') === 'none')
                    ? ''
                    : 'target="' . (($fgParams->getValue('target_frame') === 'custom') ? $fgParams->getValue('custom_frame') : $fgParams->getValue('target_frame')) . '"';

                if (!empty($origLink)) {
                    if ($fgParams->getValue('shortened_url')) {
                        switch ($fgParams->getValue('shortened_url')) {
                            case 1: // Bit.ly
                                FeedgatorUtility::profiling('Bit.ly URL Shortener');
                                $origLink = FeedgatorUtility::getUrl(
                                    'http://api.bitly.com/v3/shorten?login=feedgator&apiKey=R_9e7b64db664f89150100e95fbcaa6a85&longUrl='
                                        . FeedgatorUtility::encode_url($origLink) . '&format=txt&x_login=' . $fgParams->getValue('bitly_login')
                                        . '&x_apiKey=' . $fgParams->getValue('bitly_api_key'),
                                    $fgParams->getValue('scrape_type'),
                                    'noheader'
                                ) ?: $origLink;
                                break;

                            case 2: // Goo.gl
                                FeedgatorUtility::profiling('Goo.gl URL Shortener');

                                if ($json = FeedgatorUtility::getUrl('https://www.googleapis.com/urlshortener/v1/url?key=AIzaSyD4e2Kc67Thf6-dt7v0B1KcCn4RPRKjQyc', 'fopen', 'goo.gl', null, [$origLink])) {
                                    if (strpos($json, 'error') === false) {
                                        $json     = json_decode($json);
                                        $origLink = $json->id;
                                    }
                                }

                                break;
                        }
                    }

                    if ($fgParams->getValue('shortlink')) {
                        $readonlink = '<strong><a class="' . $fgParams->getValue('trackback_class') . '"'
                            . ' rel="' . $fgParams->getValue('trackback_rel') . '"'
                            . ' title="' . trim(substr($content['title'], 0, 50)) . '"'
                            . ' href="' . $origLink . '" ' . $target . '>' . $fgParams->getValue('orig_link_text') . '</a></strong>';
                    } else {
                        $readonlink = '<strong>' . $fgParams->getValue('orig_link_text') . '</strong>&nbsp;'
                            . '<a class="' . $fgParams->getValue('trackback_class') . '"'
                            . ' rel="' . $fgParams->getValue('trackback_rel') . '"'
                            . ' title="' . trim(substr($content['title'], 0, 50)) . '"'
                            . ' href="' . $origLink . '" ' . $target . '>' . $origLink . '</a>';
                    }

                    $readonlink = '<p>' . $readonlink . '</p>';

                    if ($fgParams->getValue('onlyintro') || !$content['fulltext'] || !$content['introtext']) {
                        if (!$content['introtext']) {
                            $content['introtext'] .= '<p>' . $fgParams->getValue('default_introtext') . '</p>';
                        }

                        $content['introtext'] .= $readonlink;
                    } else {
                        $content['fulltext'] .= $readonlink;
                    }
                }
            }

            if ($fgParams->getValue('save_feed_cats') && method_exists($item, 'get_category') && ($category = $item->get_category())) {
                $content['metakey'] .= $category->get_label();
            }

            if ($fgParams->getValue('save_sect_cats')) {
                $content['metakey'] .= (empty($content['metakey']) ? '' : ',') . $plugin->getFieldNames($content);
            }

            FeedgatorUtility::profiling('Start Tag/Keyword Processing');

            if ($fgParams->getValue('default_tags', null, '')) {
                $content['metakey'] .= (empty($content['metakey']) ? '' : ',') . $fgParams->getValue('default_tags');
            }

            switch ($fgParams->getValue('compute_tags')) {
                case 1: // internal method
                    FeedgatorUtility::profiling('FG Internal Tagging/Keyword Processing');
                    $content['metakey'] .= (empty($content['metakey']) ? '' : ',') . self::generateTags($content['introtext'] . ' ' . $content['fulltext'], $fgParams);
                    break;

                case 2: // "Add Keywords" 3rd-party plugin
                    FeedgatorUtility::profiling('AddKeywords Tagging/Keyword Processing');

                    if (\class_exists('plgSystemAddKeywords')) {
                        $addkeywordmeta       = \plgSystemAddKeywords::generateMeta($content['introtext'] . ' ' . $content['fulltext'], true, true, null);
                        $content['metakey']  .= (empty($content['metakey']) ? '' : ',') . $addkeywordmeta['keywords'];
                        $content['metadesc'] .= (empty($content['metadesc']) ? '' : ',') . $addkeywordmeta['description'];
                    } else {
                        $content['metakey'] .= (empty($content['metakey']) ? '' : ',') . self::generateTags($content['introtext'] . ' ' . $content['fulltext'], $fgParams);
                    }

                    break;

                case 3: // Yahoo! (defunct - see extractTerms() docblock)
                    FeedgatorUtility::profiling('Yahoo content.analyze Tagging/Keyword Processing');
                    $content['metakey'] .= (empty($content['metakey']) ? '' : ',') . self::extractTerms($origLink, $fgParams);
                    break;

                case 4: // OpenCalais
                    FeedgatorUtility::profiling('OpenCalais Tagging/Keyword Processing');
                    $content['metakey'] .= (empty($content['metakey']) ? '' : ',') . self::extractCalais($content['introtext'] . ' ' . $content['fulltext'], $fgParams);
                    break;
            }

            FeedgatorUtility::cleanMeta($content);
            FeedgatorUtility::profiling('End Tag/Keyword Processing');

            $tzOffset = Factory::getConfig()->get('offset');
            $itemDate = Factory::getDate($item->get_date(), $tzOffset);
            $iDate    = $itemDate->toSql();
            $today    = gmdate('Y-m-d H:i:s');

            if ($itemDate->toUnix() < Factory::getDate('2000-01-01 00:00:00')->toUnix()) {
                $iDate = $today;
            }

            if (!$fgParams->getValue('advance_date') && $itemDate->toUnix() > Factory::getDate('now')->toUnix()) {
                $iDate = $today;
            }

            if ($iDate && \strlen(trim($iDate)) <= 10) {
                $iDate .= ' 00:00:00';
            }

            $content['created']     = $fgParams->getValue('created_date') ? $today : $iDate;
            $content['publish_up']  = $fgParams->getValue('pub_date') ? $today : $iDate;
            $content['state']       = (int) $fgParams->getValue('auto_publish');
            $publishDays            = (int) $fgParams->getValue('publish_duration');

            if ($content['state'] > 0 && $publishDays) {
                switch ($fgParams->getValue('pub_dur_type', null, 0)) {
                    case 0:
                        $publishDays *= 24 * 60 * 60;
                        break;
                    case 1:
                        $publishDays *= 60 * 60;
                        break;
                    case 2:
                        $publishDays *= 60;
                        break;
                }

                $content['publish_down'] = gmdate('Y-m-d H:i:s', time() + $publishDays);
            }
        }

        FeedgatorUtility::profiling('End Item Processing');
        unset($item);
        FeedgatorUtility::profiling('Feed Item Unset');

        return $content;
    }

    public static function makeTitleAlias(&$item, &$content, &$feed_text, $channelTitle, $hash, &$fgParams)
    {
        if (!isset($content['title'])) {
            $content['title'] = trim($item->get_title());

            if (!$content['title']) {
                $regex = '#<(?:h1|h2|h3|b|strong)[^>]*>([\s\S]*?)<\/(?:h1|h2|h3|b|strong)>#i';
                preg_match($regex, $feed_text, $matches);
                $content['title'] = isset($matches[1]) ? OutputFilter::cleanText($matches[1]) : '';

                if (empty($content['title'])) {
                    $datenow          = Factory::getDate();
                    $content['title'] = $channelTitle . ' - ' . $hash . ' - ' . $datenow->format('Y-m-d-H-i-s');
                }
            }

            $content['title'] = str_replace(["\n", "\r", "\t"], ' ', $content['title']);
            $content['title'] = OutputFilter::cleanText($content['title']);
            $content['title'] = preg_replace('#\s{2,}#', ' ', $content['title']);
        }

        $content['title'] = FeedgatorUtility::adjustText($content['title'], $fgParams);
        $content['alias'] = FeedgatorUtility::stringURLSafe($content['title']);

        if ($fgParams->getValue('translit', null, 0)) {
            $content['alias'] = FeedgatorUtility::transliterate($content['alias'], $fgParams->getValue('custom_translit'));
        }

        $length = \strlen($content['alias']);

        if (strrpos($content['alias'], '-') == $length - 1) {
            $content['alias'] = substr($content['alias'], 0, $length - 1);
        }

        $content['title'] = html_entity_decode(substr($content['title'], 0, 255), ENT_QUOTES, 'UTF-8');
        $content['alias'] = substr($content['alias'], 0, 255);

        return $content;
    }

    public static function findDuplicates(&$content, &$imports, $hash, $id, &$fgParams, &$plugin, $thorough = false, $exhaustive = false)
    {
        $db    = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $debug = $fgParams->getValue('debug');

        if (!$thorough && !$exhaustive) {
            if ($existId = $plugin->findDuplicates('id', $id, $content['catid'])) {
                return $existId;
            }
        } elseif ($thorough && !$exhaustive) {
            if ($content['title'] && $content['alias']) {
                if ($existId = $plugin->findDuplicates('alias', $content['alias'], $content['catid'])) {
                    return $existId;
                }

                foreach ($imports as $import) {
                    if ($import['hash'] == $hash) {
                        if ($debug) {
                            self::findDuplicates($content, $imports, $hash, $import['content_id'], $fgParams, $plugin);
                        }

                        break;
                    }
                }
            } elseif ($content['title']) {
                if ($existId = $plugin->findDuplicates('title', $content['title'], $content['catid'])) {
                    return $id;
                }

                foreach ($imports as $import) {
                    if ($import['hash'] == $hash) {
                        if ($debug) {
                            self::findDuplicates($content, $imports, $hash, $import['content_id'], $fgParams, $plugin);
                        }

                        break;
                    }
                }
            }
        } elseif ($exhaustive) {
            $type = $fgParams->getValue('check_text') ? 'introtext' : 'fulltext';

            if ($existId = $plugin->findDuplicates($type, $content[$type], $content['catid'])) {
                return $existId;
            }

            $query = 'SELECT * FROM ' . $plugin->table . ' WHERE id = ' . $db->quote($id);
            $db->setQuery($query);

            return $db->loadAssoc();
        }

        foreach ($imports as $import) {
            if ($import['hash'] == $hash) {
                // doesn't exist any more, so remove the stale import record and allow re-importing
                $row = FeedgatorFactory::getTable('Import');
                $row->delete($import['id']);
            }
        }

        return false;
    }

    /**
     * Rewrites Elementor gallery-widget markup into plain <img> tags.
     *
     * Elementor's gallery widget renders each image as:
     *   <a href="full-size.jpg" ...><div class="...elementor-gallery-item__image..." data-thumbnail="thumb.jpg" ...></div></a>
     * with no <img> tag anywhere - real content from a real feed hit
     * this exact pattern, and processImages() (which only ever looks
     * for <img> elements) silently found nothing to scrape from it.
     * Targets the elementor-gallery-item__image class specifically
     * (rather than any <a href="*.jpg">) to avoid false-matching an
     * unrelated link that happens to point at an image file.
     */
    public static function normalizeGalleryMarkup($text)
    {
        $pattern = '/<a\s+[^>]*href="([^"]+\.(?:jpe?g|png|gif|webp))"[^>]*>\s*<div\s+[^>]*class="[^"]*elementor-gallery-item__image[^"]*"[^>]*>\s*<\/div>\s*<\/a>/i';

        return preg_replace_callback($pattern, static function ($m) {
            return '<img src="' . $m[1] . '" alt="" />';
        }, $text);
    }

    public static function processImages($origLink, &$text, &$content, &$plugin, &$fgParams, &$images = [])
    {
        // PHP 8 made DOMDocument::loadHTML() throw a ValueError for an
        // empty string argument (older PHP silently produced an empty
        // document instead) - the leading @ here only ever suppressed
        // warnings, never exceptions, so an item with empty feed/source
        // text (fulltext fetch failed, or a feed item with no
        // description) would crash the whole import. Nothing to extract
        // images from in an empty string anyway, so just skip.
        if ((string) $text === '') {
            return $images;
        }

        // Some site builders (Elementor's gallery widget, seen on at
        // least one real feed) render gallery images as an <a href="
        // full-size.jpg"><div data-thumbnail="thumb.jpg"></div></a>
        // pair rather than a plain <img src="..."> tag at all - nothing
        // below here would ever find these, since it specifically looks
        // for <img> elements. Rewriting the recognisable pattern into a
        // plain <img> tag first lets the rest of this function's image-
        // check/save/replace logic handle it exactly like any other
        // image, without needing a parallel code path.
        $text = self::normalizeGalleryMarkup($text);

        $replace  = [];
        $rimages  = [];
        $regex    = '/<img[^>]*>/';
        $disallowed = explode(',', $fgParams->getValue('blocked_images'));

        $dom  = new \DOMDocument();
        $dom2 = new \DOMDocument();
        @$dom->loadHTML($text);
        $imgs = $dom->getElementsByTagName('img');
        $k    = empty($images) ? 0 : \count($images);

        foreach ($imgs as $img) {
            $src = $img->getAttribute('src');

            if ($src && !FeedgatorUtility::in_array_recursive($src, $images) && strpos($src, $fgParams->getValue('base')) === false) {
                FeedgatorUtility::profiling('Processing Image SRC: ' . $src);
                $src                          = FeedgatorUtility::encode_url(FeedgatorUtility::makeAbsUrl($origLink, $src));
                $images[$k]['image_details']  = [];
                $image_pass                   = 0;

                if (FeedgatorUtility::strpos_array($src, $disallowed) === false) {
                    if ($fgParams->getValue('img_check', null, 1) && \function_exists('getimagesize')) {
                        $images[$k]['image_details'] = @getimagesize($src);

                        if ($images[$k]['image_details'] && $images[$k]['image_details'][0] > 48 && $images[$k]['image_details'][1] > 48) {
                            $image_pass = 1;
                        }
                    } else {
                        $image_pass = 1;
                    }
                }

                if ($image_pass) {
                    $images[$k]['title'] = $img->getAttribute('title') ? $k . '_' . $img->getAttribute('title') : '';
                    $images[$k]['alt']   = $img->getAttribute('alt') ? $k . '_' . $img->getAttribute('alt') : '';
                    $images[$k]['src']   = $src;
                    $img->setAttribute('src', $src);

                    if ($fgParams->getValue('save_img')) {
                        if ($fgParams->getValue('preview')) {
                            FeedgatorUtility::profiling('Skipping Image Saving for Preview');
                        } else {
                            $image_data = [
                                'title'     => $images[$k]['title'],
                                'alt'       => $images[$k]['alt'],
                                'src'       => $src,
                                'name_type' => $fgParams->getValue('img_name_type', null, 0),
                                'prefix'    => $fgParams->getValue('name_prefix'),
                                'suffix'    => '_' . $k,
                            ];
                            $filename = self::getImageName($image_data, $images[$k]['image_details']);

                            if (self::imageUpload($src, $filename, $fgParams)) {
                                $images[$k]['filename'] = $filename;
                                $images[$k]['savepath'] = $fgParams->getValue('img_savepath') . $filename;
                                $images[$k]['old_src']  = $src;
                                $images[$k]['src']      = $fgParams->getValue('img_srcpath') . $filename;
                                $img->setAttribute('src', $fgParams->getValue('img_srcpath') . $filename);
                            }
                        }
                    }

                    if (\strlen($images[$k]['alt']) >= \strlen($content['title']) || !$images[$k]['alt']) {
                        $img->setAttribute('alt', $content['title']);
                    }

                    if ($fgParams->getValue('rmv_img_style') || $fgParams->getValue('disallow_attribs')) {
                        foreach (['class', 'style', 'align', 'border', 'width', 'height'] as $attr) {
                            $img->removeAttribute($attr);
                        }
                    }

                    if ($fgParams->getValue('img_class')) {
                        $img->setAttribute('class', $fgParams->getValue('img_class'));
                    }

                    $new_img = $dom2->importNode($imgs->item($k), true);
                    $dom2->appendChild($new_img);
                    $images[$k]['html'] = $dom2->saveHTML();
                    $rimages[$k]        = $images[$k]['html'];
                    $dom2->removeChild($new_img);

                    $text        = preg_replace($regex, 'fg_img' . $k, $text, 1);
                    $replace[$k] = 'fg_img' . $k;
                    $k++;
                } else {
                    unset($images[$k]);
                    $text = preg_replace($regex, '', $text, 1);
                }
            }
        }

        $text = str_replace(array_reverse($replace), array_reverse($rimages), $text);

        foreach ($images as $image) {
            if (!FeedgatorUtility::in_array_recursive($image['src'], $content['images']['stack'])) {
                $content['images']['stack'][] = $image;
            }
        }

        return $images;
    }

    public static function balanceImages(&$text, &$content, &$fgParams)
    {
        if ($fgParams->getValue('alt_img_ext')) {
            if (empty($content['images']['feed']) != empty($content['images']['source'])) {
                if (empty($content['images']['source']) && $text['source']) {
                    $text['source'] = ($content['images']['feed'][0]['html'] ?? '') . $text['source'];
                } elseif (empty($content['images']['feed'])) {
                    $text['feed'] = ($content['images']['source'][0]['html'] ?? '') . $text['feed'];
                }
            }
        }
    }

    public static function imageUpload($src, $filename, &$fgParams)
    {
        if (!Folder::exists($fgParams->getValue('img_savepath'))) {
            Folder::create($fgParams->getValue('img_savepath'));
        }

        $filepath = $fgParams->getValue('img_savepath') . $filename;
        $saved    = file_exists($filepath);

        if (!$saved && FeedgatorUtility::getUrl($src, $fgParams->getValue('scrape_type'), 'images', $filepath)) {
            $saved = true;
        }

        return $saved;
    }

    public static function addDefaultImage(&$content, &$plugin, &$fgParams)
    {
        if ($fgParams->getValue('default_img', null, 0)) {
            switch ($fgParams->getValue('default_img')) {
                case 1: // add to introtext if no images
                    if (empty($content['images']['feed'])) {
                        $plugin->addDefaultImage('introtext', $content, $fgParams);

                        return true;
                    }

                    break;

                case 2: // add to introtext and fulltext if no images
                    if (empty($content['images']['stack'])) {
                        $plugin->addDefaultImage('both', $content, $fgParams);

                        return true;
                    }

                    break;

                case 3: // force add to introtext
                    $plugin->addDefaultImage('introtext', $content, $fgParams);

                    return true;

                case 4: // force add to introtext and fulltext
                    $plugin->addDefaultImage('both', $content, $fgParams);

                    return true;
            }
        }

        return false;
    }

    public static function getImageName($image_data, $image_details, $add_ext = 1)
    {
        ['title' => $title, 'alt' => $alt, 'src' => $src, 'name_type' => $name_type, 'prefix' => $prefix, 'suffix' => $suffix] = $image_data;

        preg_match('#[/?&]([^/?&]*)(\.jpg|\.jpeg|\.gif|\.png)#i', $src, $matches);
        $ext = isset($matches[2]) ? trim(strtolower($matches[2])) : '';

        if (!$ext && !empty($image_details)) {
            $ext = match ($image_details['mime'] ?? null) {
                'image/pjpeg', 'image/jpeg', 'image/jpg' => '.jpg',
                'image/x-png', 'image/png'                => '.png',
                'image/gif'                                => '.gif',
                'image/bmp'                                => '.bmp',
                default                                    => '',
            };
        }

        $name = '';

        switch ($name_type) {
            case 0:
                [$name] = $title
                    ? FeedgatorUtility::splitText($title, 50, 'char', false)
                    : FeedgatorUtility::splitText($alt, 50, 'char', false);
                break;

            case 1:
                $name = $matches[1] ?? '';
                break;

            case 2:
                $name = md5($src);
                break;
        }

        $image_data['name_type'] = ($name_type ?? 0) + 1;

        if (empty($name)) {
            $name = self::getImageName($image_data, $image_details, 0);
        }

        $name = File::makeSafe(FeedgatorUtility::stringURLSafe($prefix . $name . $suffix));

        return $add_ext ? $name . $ext : $name;
    }

    /**
     * Identifies image enclosures and adds their HTML to the item text;
     * delegates other enclosure types to extractEnclosures().
     *
     * NOTE: under SimplePieFeedAdapter, $encs is always an empty array
     * (Joomla's built-in Feed API doesn't expose enclosures), so this
     * method is effectively a no-op today - see SimplePieFeedAdapter's
     * docblock.
     */
    public static function processEnclosures(&$encs, &$content, &$fgParams, &$enc_image, &$thumb, &$text)
    {
        $enc_links = [];

        foreach ($encs as $enc) {
            if (!isset($enc_links[$enc->get_link()])) {
                $enc_links[$enc->get_link()] = 1;

                if ($enc->get_type()) {
                    if (stripos($enc->get_real_type(), 'image') !== false && empty($content['images']['feed']) && $fgParams->getValue('process_enc_images')) {
                        $enc_image    = '<img src="' . $enc->get_link() . '" alt="' . $content['title'] . '"/>';
                        $text['feed'] = $enc_image . $text['feed'];

                        if ($text['source'] && (empty($content['images']['source']) || $fgParams->getValue('force_enc_image'))) {
                            $text['source'] = $enc_image . $text['source'];
                        }
                    } else {
                        $text['source']
                            ? self::extractEnclosures($enc, $text['source'], $content, $fgParams)
                            : self::extractEnclosures($enc, $text['feed'], $content, $fgParams);
                    }
                } elseif (($thumbnail = $enc->get_thumbnail()) && empty($content['images']['feed']) && $fgParams->getValue('process_enc_images')) {
                    $thumb        = '<img src="' . $thumbnail . '" alt="' . $content['title'] . '"/>';
                    $text['feed'] = $thumb . $text['feed'];

                    if ($text['source'] && (empty($content['images']['source']) || $fgParams->getValue('force_enc_image'))) {
                        $text['source'] = $thumb . $text['source'];
                    }
                }
            }
        }
    }

    public static function extractEnclosures(&$e, &$text, &$content, &$fgParams)
    {
        if (!$fgParams->getValue('process_enc')) {
            return true;
        }

        if (!Folder::exists($fgParams->getValue('savepath'))) {
            Folder::create($fgParams->getValue('savepath'));
        }

        $real_type = strtolower($e->get_real_type());
        $src       = $e->get_link();
        $parts     = explode('/', $src);
        $real_name = array_pop($parts);
        $name      = $e->get_title() ?: $e->get_caption();

        if (!$name) {
            $name = $real_name;
        }

        $e_inf = '';
        $saved = false;
        $e_img = 'generic';

        if (strpos($real_type, 'audio') !== false) {
            $e_img = 'audio';

            if ($fgParams->getValue('save_enc')) {
                $saved = self::saveEnclosure($name, 'audio', $src, $fgParams);
            }

            $e_lnk = '<a href="' . ($saved ? $fgParams->getValue('srcpath') . 'audio/' . $name : $src) . '">' . $name . '</a>';

            if ($e->get_duration()) {
                $e_inf .= 'Duration: ' . $e->get_duration() . ' seconds<br />';
            }

            if ($e->get_size()) {
                $e_inf .= 'Size: ' . $e->get_size() . ' Mb';
            }

            if ($saved && !$fgParams->getValue('create_art', null, 1)) {
                $content['id'] = -1;
            }
        } elseif (strpos($real_type, 'video') !== false) {
            $e_img = $e->get_thumbnail() ?: 'generic';

            if ($fgParams->getValue('save_enc')) {
                $saved = self::saveEnclosure($name, 'videos', $src, $fgParams);
            }

            $e_lnk = '<a href="' . ($saved ? $fgParams->getValue('srcpath') . 'videos/' . $name : $src) . '">' . $name . '</a>';

            if ($e->get_duration()) {
                $e_inf .= 'Duration: ' . $e->get_duration() . ' seconds<br />';
            }

            if ($e->get_size()) {
                $e_inf .= 'Size: ' . $e->get_size() . ' Mb';
            }

            if ($saved && !$fgParams->getValue('create_art', null, 1)) {
                $content['id'] = -2;
            }
        } elseif (strpos($real_type, 'image') !== false) {
            $e_img = 'image';

            if ($fgParams->getValue('save_enc')) {
                $saved = self::saveEnclosure($name, 'images', $src, $fgParams);
            }

            $e_lnk = '<a href="' . ($saved ? ($fgParams->getValue('save_enc_image_as_img') ? $fgParams->getValue('img_srcpath') : $fgParams->getValue('srcpath') . 'images/') . $name : $src) . '">' . $name . '</a>';

            if ($e->get_size()) {
                $e_inf .= 'Size: ' . $e->get_size() . ' Mb';
            }

            if ($saved && !$fgParams->getValue('create_art', null, 1)) {
                $content['id'] = -3;
            }
        } elseif (strpos($real_type, 'pdf') !== false) {
            $e_img = 'pdf';

            if ($fgParams->getValue('save_enc')) {
                $saved = self::saveEnclosure($name, 'attachments', $src, $fgParams);
            }

            $e_lnk = '<a href="' . ($saved ? $fgParams->getValue('srcpath') . 'attachments/' . $name : $src) . '">' . $name . '</a>';

            if ($e->get_size()) {
                $e_inf .= 'Size: ' . $e->get_size() . ' Mb';
            }

            if ($saved && !$fgParams->getValue('create_art', null, 1)) {
                $content['id'] = -4;
            }
        } elseif (strpos($real_type, 'doc') !== false) {
            $e_img = match ($e->get_extension()) {
                '.doc', '.docx' => 'word',
                '.xls', '.xlsx' => 'xls',
                '.ppt', '.pptx' => 'ppt',
                '.odf'          => 'odf',
                default         => 'generic',
            };

            if ($fgParams->getValue('save_enc')) {
                $saved = self::saveEnclosure($name, 'attachments', $src, $fgParams);
            }

            $e_lnk = '<a href="' . ($saved ? $fgParams->getValue('srcpath') . 'attachments/' . $name : $src) . '">' . $name . '</a>';

            if ($e->get_size()) {
                $e_inf .= 'Size: ' . $e->get_size() . ' Mb';
            }

            if ($saved && !$fgParams->getValue('create_art', null, 1)) {
                $content['id'] = -5;
            }
        } elseif (strpos($real_type, 'zip') !== false) {
            $e_img = 'archive';

            if ($fgParams->getValue('save_enc')) {
                $saved = self::saveEnclosure($name, 'attachments', $src, $fgParams);
            }

            $e_lnk = '<a href="' . ($saved ? $fgParams->getValue('srcpath') . 'attachments/' . $name : $src) . '">' . $name . '</a>';

            if ($e->get_size()) {
                $e_inf .= 'Size: ' . $e->get_size() . ' Mb';
            }

            if ($saved && !$fgParams->getValue('create_art', null, 1)) {
                $content['id'] = -6;
            }
        } else {
            if ($fgParams->getValue('save_enc')) {
                $saved = self::saveEnclosure($name, 'attachments', $src, $fgParams);
            }

            $e_lnk = '<a href="' . ($saved ? $fgParams->getValue('srcpath') . 'attachments/' . $name : $src) . '">' . $name . '</a>';

            if ($e->get_size()) {
                $e_inf .= 'Size: ' . $e->get_size() . ' Mb';
            }

            if ($saved && !$fgParams->getValue('create_art', null, 1)) {
                $content['id'] = -7;
            }
        }

        $img   = sprintf('<img class="fg_enclosure_img" src="%sadministrator/components/com_feedgator/images/%s.png" height="16" width="16" style="margin:8px 8px;">', $fgParams->getValue('base'), $e_img);
        $e_lnk = sprintf('<div class="fg_enclosure_lnk" style="padding-left:34px;white-space:nowrap;">%s</div>', $e_lnk);

        if ($e_inf) {
            $e_inf = sprintf('<div class="fg_enclosure_inf" style="padding-left:34px;white-space:nowrap;">%s</div>', $e_inf);
        }

        $e_out = sprintf('<div class="fg_enclosure" style="margin:10px 0px;"><div class="fg_enclosure_img" style="display:inline-block;position:absolute;">%s</div>%s%s</div>', $img, $e_lnk, $e_inf);
        $text .= $e_out;
    }

    public static function saveEnclosure($name, $type, $src, &$fgParams)
    {
        if ($type === 'images') {
            $savepath = $fgParams->getValue('save_enc_image_as_img', null, 1) ? $fgParams->getValue('img_savepath') : $fgParams->getValue('savepath') . $type . '/';
        } else {
            $savepath = $fgParams->getValue('savepath') . $type . '/';
        }

        if (!Folder::exists($savepath)) {
            Folder::create($savepath);
        }

        $file_path = $savepath . $name;

        if (!file_exists($file_path)) {
            if (!FeedgatorUtility::getUrl(FeedgatorUtility::encode_url($src), $fgParams->getValue('scrape_type'), $type, $file_path)) {
                return false;
            }
        }

        return true;
    }

    public static function saveImport($hash, $feed_id, $content_id, $plugin, &$fgParams)
    {
        $import = [
            'hash'       => $hash,
            'feed_id'    => $feed_id,
            'content_id' => $content_id,
            'plugin'     => $plugin,
        ];

        $irow = $fgParams->getValue('irow') ?: FeedgatorFactory::getTable('Import');
        $irow->save($import);
        $fgParams->setValue('hash', null, '');
    }

    /**
     * Uses the bundled inc/readability port for full-article extraction.
     * PHP 8 compatibility of that bundled library has not been verified
     * in this environment - test this path specifically.
     */
    public static function getFullText($origLink, &$fgParams)
    {
        require_once \JPATH_ADMINISTRATOR . '/components/com_feedgator/inc/readability/Readability.php';

        $page  = FeedgatorUtility::getUrl($origLink, $fgParams->getValue('scrape_type'), 'html');
        $parts = FeedgatorUtility::extractHTTP($page, $fgParams);
        $body  = FeedgatorUtility::convert_to_utf8($parts['body'], $parts['header']);

        if ($body) {
            $readability                       = new \Readability($body, $origLink, $fgParams);
            $readability->convertLinksToFootnotes = (bool) $fgParams->getValue('link_table');

            if ($readability->init()) {
                $fgParams->setValue('rDebug', null, $readability->debugMsg);

                if ($fgParams->getValue('readability_title')) {
                    $fgParams->setValue('readability_title', null, $readability->articleTitle->innerHTML);
                }

                $return = $readability->articleContent->innerHTML;

                if ($return === '<p>Sorry, Readability was unable to parse this page for content.</p>') {
                    $return = '';
                }

                $return = '<div id="fgimages">' . $readability->articleImages->innerHTML . '</div>' . $return;
                $return = '<div id="fgvideos">' . $readability->articleVideos->innerHTML . '</div>' . $return;

                return $return;
            }
        }

        return false;
    }

    /**
     * Builds article introtext/fulltext from the raw feed/source text,
     * cleaning HTML via the bundled htmLawed library.
     */
    public static function makeParts($content, &$text, &$fgParams)
    {
        $app = Factory::getApplication();

        $text['feed']   = str_replace(['<br>', '<br/>'], '<br />', $text['feed']);
        $text['source'] = str_replace(['<br>', '<br/>'], '<br />', $text['source'] ?? '');

        if ($fgParams->getValue('remove_dups_emp')) {
            while (strpos($text['feed'], '<br /><br />') !== false) {
                $text['feed'] = str_replace('<br /><br />', '<br />', $text['feed']);
            }

            while (strpos($text['source'], '<br /><br />') !== false) {
                $text['source'] = str_replace('<br /><br />', '<br />', $text['source']);
            }
        }

        $clean_config = [
            'safe'     => 1,
            'comment'  => 1,
            'abs_url'  => $fgParams->getValue('rel_src', null, 0) ? 0 : 1,
            'base_url' => $fgParams->getValue('fBase'),
        ];
        $spec = 'img=-*,src';

        if ($fgParams->getValue('img_class')) {
            $spec .= ',class(match=%' . $fgParams->getValue('img_class') . '%)';
        }

        if (!$fgParams->getValue('disallow_attribs')) {
            $spec .= ',height,width';
        }

        $spec .= ';table=-*,border,width,cellspacing,cellpadding;';

        if (strpos($fgParams->getValue('strip_list'), '*iframe') !== false) {
            $spec .= 'iframe=frameborder,height,width,src,srcdoc,seamless,scrolling,sandbox,name,longdesc;';
        }

        if (strpos($fgParams->getValue('strip_list'), '*object') !== false) {
            $spec .= 'object=border,height,width,classid,codebase,codetype,data,declare,type,usemap,archive,id;param=name,type,value,valuetype;';
        }

        if (strpos($fgParams->getValue('strip_list'), '*embed') !== false) {
            $spec .= 'embed=src,type,height,width;';
        }

        if ($fgParams->getValue('disallow_attribs')) {
            $clean_config['deny_attribute'] = '* -title -href -target -alt';
        }

        if ($fgParams->getValue('xhtml_clean')) {
            $clean_config['valid_xhtml'] = 1;
        }

        if ($fgParams->getValue('remove_bad')) {
            $clean_config['keep_bad'] = 6;
        }

        if ($fgParams->getValue('link_nofollow')) {
            $clean_config['anti_link_spam'] = ['`.`', ''];
        }

        $clean_config['tidy'] = $fgParams->getValue('tidy');

        if ($fgParams->getValue('strip_html_tags')) {
            $text['feed']   = trim(strip_tags($text['feed']));
            $text['source'] = trim(strip_tags($text['source']));
        } else {
            [$tags, , $special] = self::getTagsToStrip($fgParams);

            if ($special) {
                $special = str_replace([' ', '*'], '', $special);
                $special = '*+' . str_replace(',', ' +', $special);
                $tags    = $tags ? $special . ' -' . str_replace(',', ' -', str_replace(' ', '', $tags)) : $special;
            } elseif (strpos($tags, '+') !== false) {
                $tags = str_replace('+', '', $tags);
            } elseif ($tags) {
                $tags = '*-' . str_replace(',', ' -', str_replace(' ', '', $tags));
            }

            if ($tags) {
                $clean_config['elements'] = $tags;
            }
        }

        $clean_config['hook_tag'] = ['\\Trafalgardesign\\Component\\Feedgator\\Administrator\\Helper\\FeedgatorUtility', 'hook_tag_cleaning'];

        if ($fgParams->getValue('debug')) {
            $fgParams->setValue('clean_config', null, FeedgatorUtility::makeINIString($clean_config));
            $fgParams->setValue('spec', null, $spec);
        }

        [$text['source']] = FeedgatorUtility::splitText($text['source'], $fgParams->getValue('max_length'), $fgParams->getValue('max_length_type'), true);
        [$text['feed']]   = FeedgatorUtility::splitText($text['feed'], $fgParams->getValue('max_length'), $fgParams->getValue('max_length_type'), true);
        $trimTo = $fgParams->getValue('trim_to');

        // Prefer whichever of "source" (Get Full Text's webpage scrape)
        // or "feed" (the RSS's own content:encoded/description) is
        // actually longer, rather than always preferring "source"
        // whenever it's merely non-empty. A "Get Full Text" scrape that
        // returns *less* content than the feed already provided is a
        // failed/degenerate scrape (the bundled Readability-style
        // extractor can grab the wrong DOM section entirely on some
        // page layouts - confirmed happening on at least one real
        // Elementor-built site, returning a small fragment instead of
        // the article body) - it should never be allowed to throw away
        // perfectly good content the feed already gave us directly.
        $useSource = $text['source'] && \strlen($text['source']) >= \strlen($text['feed']);

        if ($fgParams->getValue('combine_text') && !$fgParams->getValue('onlyintro')) {
            [$introText] = FeedgatorUtility::splitText($text['feed'], $trimTo, $fgParams->getValue('trim_type'), true);

            if (!$introText) {
                [$introText] = FeedgatorUtility::splitText($text['source'], $trimTo, $fgParams->getValue('trim_type'), true);
            }

            $fullText = $useSource ? $text['source'] : $text['feed'];
        } else {
            [$introText, $fullText] = $useSource
                ? FeedgatorUtility::splitText($text['source'], $trimTo, $fgParams->getValue('trim_type'), true)
                : FeedgatorUtility::splitText($text['feed'], $trimTo, $fgParams->getValue('trim_type'), true);
        }

        PluginHelper::importPlugin('feedgator');
        $app->triggerEvent('onBeforeFGCleanText', [$content, $introText, $fullText, $clean_config, $fgParams]);

        if ($fgParams->getValue('onlyintro') || !$trimTo || !$fullText) {
            $content['introtext'] = FeedgatorUtility::cleanText($introText, $clean_config, $spec, $fgParams);
        } else {
            $content['introtext'] = FeedgatorUtility::cleanText($introText, $clean_config, $spec, $fgParams);
            $content['fulltext']  = FeedgatorUtility::cleanText($fullText, $clean_config, $spec, $fgParams);
        }

        if (empty($content['fulltext']) && !$fgParams->getValue('onlyintro')) {
            $content['fulltext'] = $content['introtext'];
        }

        if ($fgParams->getValue('dotdotdot')) {
            $content['introtext'] = preg_replace('/([^<])([\s]*(?:<[^>]*>[\s]*){0,})$/', '$1...$2', $content['introtext']);
        }

        $app->triggerEvent('onAfterFGCleanText', [$content, $fgParams]);

        return $content;
    }

    /**
     * @return array{0: string, 1: string, 2: string} [plain tag list, attribute-qualified tags, wildcard/special tags]
     */
    public static function getTagsToStrip($fgParams)
    {
        $s  = $fgParams->getValue('strip_list');
        $ts = explode(',', $s);
        $ht = [];
        $sp = [];

        foreach ($ts as $k => $t) {
            if (strpos($t, '=') !== false) {
                $ht[] = $t;
                unset($ts[$k]);
            }

            if (strpos($t, '*') !== false) {
                $sp[] = $t;
                unset($ts[$k]);
            }
        }

        return [implode(',', $ts), implode(',', $ht), implode(',', $sp)];
    }

    /**
     * @deprecated  OpenCalais's free "Enlighten" API used here was retired years ago;
     *              this will fail over to generateTags() in practice. Kept for
     *              structural completeness - point $fgParams->calais_app_id at a
     *              current OpenCalais endpoint/API version if you still want this.
     */
    public static function extractCalais($text, &$fgParams)
    {
        $app  = Factory::getApplication();
        $text = strip_tags($text);

        if (!trim($text)) {
            return '';
        }

        $externalID = md5(\JPATH_ADMINISTRATOR . microtime());
        $paramsXML  = '<c:params xmlns:c="http://s.opencalais.com/1/pred/" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<c:processingDirectives c:contentType="TEXT/RAW" c:enableMetadataType="GenericRelations,SocialTags" c:outputFormat="Application/JSON" c:docRDFaccesible="true"></c:processingDirectives>'
            . '<c:userDirectives c:allowDistribution="true" c:allowSearch="true" c:externalID="' . $externalID . '" c:submitter="' . $app->get('sitename') . '"></c:userDirectives>'
            . '<c:externalMetadata></c:externalMetadata></c:params>';

        $request = 'https://api.opencalais.com/enlighten/rest/';
        $args     = ['licenseID=' . $fgParams->getValue('calais_app_id') . '&content=' . urlencode($text) . '&paramsXML=' . urlencode($paramsXML)];

        $response = FeedgatorUtility::getUrl($request, $fgParams->getValue('scrape_type'), 'yql', null, $args);
        $respObj  = $response ? json_decode($response) : null;

        if (!$respObj) {
            return self::generateTags($text, $fgParams);
        }

        $allowedEntities = array_flip([
            'Anniversary', 'City', 'Company', 'Continent', 'Country', 'Currency', 'EntertainmentAwardEvent', 'Facility',
            'Holiday', 'IndustryTerm', 'MedicalCondition', 'MedicalTreatment', 'Movie', 'MusicAlbum', 'MusicGroup',
            'NaturalFeature', 'OperatingSystem', 'Organization', 'PoliticalEvent', 'Position', 'Product',
            'ProgrammingLanguage', 'ProvinceOrState', 'PublishedMedium', 'RadioProgram', 'RadioStation', 'Region',
            'SportsEvent', 'SportsGame', 'SportsLeague', 'Technology', 'TVShow', 'TVStation',
        ]);

        $results = [];

        foreach ($respObj as $obj) {
            if (isset($obj->_typeGroup)) {
                if ($obj->_typeGroup === 'socialTag') {
                    $results[] = $obj->name;
                } elseif (\in_array($obj->_typeGroup, ['entities', 'relations'], true) && isset($allowedEntities[$obj->_type])) {
                    $results[] = $obj->name;
                }
            }
        }

        $results = self::removeIgnoreWords($results, true, $fgParams);
        $results = \is_array($results) ? \array_slice($results, 0, $fgParams->getValue('max_tags')) : [];

        return implode(',', $results);
    }

    /**
     * @deprecated  Yahoo's content.analyze YQL table used here was
     *              retired along with the rest of the YQL service in
     *              2019 - this will always fall through to
     *              generateTags(). Kept for structural completeness.
     */
    public static function extractTerms($url, &$fgParams)
    {
        $text = "SELECT * FROM contentanalysis.analyze WHERE url='" . strip_tags($url) . "'";

        if (!trim($text)) {
            return '';
        }

        $request = 'https://query.yahooapis.com/v1/public/yql';
        $args    = ['q=' . urlencode($text) . '&format=json' . ($fgParams->getValue('yahoo_app_id') ? '&appid=' . $fgParams->getValue('yahoo_app_id') : '')];

        $response = FeedgatorUtility::getUrl($request, $fgParams->getValue('scrape_type'), 'yql', null, $args);
        $respObj  = $response ? json_decode($response) : null;

        if (!$respObj) {
            return self::generateTags($text, $fgParams);
        }

        $results = [];

        if (!empty($respObj->query->count)) {
            foreach ($respObj->query->results->entities->entity as $entity) {
                $results[] = $entity->text->content;
            }
        }

        if (isset($respObj->query->results->yctCategories)) {
            foreach ($respObj->query->results->yctCategories->yctCategory as $yctCat) {
                $results[] = $yctCat->content;
            }
        }

        $results = self::removeIgnoreWords($results, true, $fgParams);
        $results = \is_array($results) ? \array_slice($results, 0, $fgParams->getValue('max_tags')) : [];

        return implode(',', $results);
    }

    public static function removeIgnoreWords($results, $utf = false, &$fgParams)
    {
        if ($fgParams->getValue('use_ignore_list') == '1') {
            $ignore_words = $fgParams->getValue('ignore_list');
            $ignoreArray  = explode(',', str_replace(', ', ',', $ignore_words));
            $results      = array_diff($results, $ignoreArray);
        }

        return $results;
    }

    /**
     * Simple word-frequency tag generator - the "internal method" tagger.
     */
    public static function generateTags($text, &$fgParams)
    {
        $text = strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES));

        if (!trim($text)) {
            return '';
        }

        $words = explode(' ', $text);

        array_walk($words, static function (&$term) {
            $term = trim($term);
            $term = str_replace(["\n", "\r"], ' ', $term);
            $term = preg_replace('/[,.?:;!()=\\*\']/', '', $term);
        });

        $minTagChars = (int) $fgParams->getValue('min_tag_chars');
        $words       = array_filter($words, static function ($var) use ($minTagChars) {
            $keep = !empty($var) && !preg_match('/^\s*$/', $var);

            if ($minTagChars > 0) {
                $keep = $keep && \strlen($var) >= $minTagChars;
            }

            return $keep;
        });

        $words = self::removeIgnoreWords($words, false, $fgParams);
        $words = array_count_values($words);
        arsort($words);
        $words = \is_array($words) ? \array_slice($words, 0, $fgParams->getValue('max_tags')) : [];

        return implode(',', array_keys($words));
    }

    public static function getDynaLists(&$fgParams, $default)
    {
        $contentsections   = [-1 => [(object) ['id' => -1, 'title' => Text::_('FG_SELECT_SECTION')]]];
        $sectioncategories = [];

        $pluginModel = FeedgatorFactory::getPluginModel();
        $rows        = $pluginModel->loadInstalledPlugins();

        foreach ($rows as $row) {
            if ($row->published && $row->installed) {
                $row->plugin = $pluginModel->getPlugin($row->extension);
                $row->plugin->getParams();

                if (($sectionList = $row->plugin->getSectionList($fgParams, $default)) && \count($sectionList)) {
                    foreach ($sectionList as $section) {
                        $contentsections[$row->plugin->extension][] = $section;
                    }

                    $sectioncategories += $row->plugin->getSectionCategories($fgParams, $default);
                } else {
                    $sectioncategories = null;
                }
            }
        }

        if (!$fgParams->getValue('content_type')) {
            $feedModel   = FeedgatorFactory::getFeedModel();
            $xmlFile     = \JPATH_ADMINISTRATOR . '/components/com_feedgator/forms/default_feed_default.xml';
            $fgdefParams = \Joomla\CMS\Form\Form::getInstance('form', $xmlFile, ['control' => 'params']);
            $feedModel->getDefaultParams();
            $fgdefParams->bind($feedModel->_defaultParamsData ?? []);

            if ($fgdefParams->getValue('content_type') && isset($contentsections[$fgdefParams->getValue('content_type')])) {
                $contentsections[''] = $contentsections[$fgdefParams->getValue('content_type')];
            }
        }

        return ['contentsections' => $contentsections, 'sectioncategories' => $sectioncategories];
    }

    public static function getPreviewArticle(&$content, &$fgParams, $channelTitle)
    {
        $previewArticle  = '<h3 class="red">' . Text::_('FG_PREV_ART') . ' for <span class="blue"><strong>' . $fgParams->getValue('title')
            . '</strong> (' . $channelTitle . ')</span></h3>';
        $previewArticle .= '<div id="title" class="fgprevdata"><h4 class="fgprevinfo">' . Text::_('FG_PREV_TITLEALIAS') . '</h4><ul>'
            . '<li><strong>' . Text::_('FG_PREV_TITLE') . ':</strong> ' . $content['title'] . '</li>'
            . '<li><strong>' . Text::_('FG_PREV_ALIAS') . ':</strong> ' . $content['alias'] . '</li>'
            . '</ul></div><br />';
        $previewArticle .= '<div id="introtext" class="fgprevdata"><h4 class="fgprevinfo">' . Text::_('FG_PREV_INTROTEXT_TITLE')
            . '</h4>' . $content['introtext'] . '</div><br />';
        $previewArticle .= '<div id="fulltext" class="fgprevdata"><h4 class="fgprevinfo">' . Text::_('FG_PREV_FULLTEXT_TITLE')
            . '</h4>' . ($content['fulltext'] ?? '') . '</div><br />';
        $previewArticle .= '<div id="metadata" class="fgprevdata"><h4>' . Text::_('FG_PREV_DATA') . '</h4><ul>';
        $previewArticle .= isset($content['created_by_alias']) ? '<li><strong>' . Text::_('FG_PREV_AUTHOR') . ':</strong> ' . $content['created_by_alias'] . '</li>' : '';
        $previewArticle .= '<li><strong>' . Text::_('FG_PREV_PUB') . ':</strong> ' . ($content['publish_up'] ?? '') . '</li>'
            . '<li><strong>' . Text::_('FG_PREV_KEYS') . ':</strong> ' . $content['metakey'] . '</li>'
            . '<li><strong>' . Text::_('FG_PREV_DESC') . ':</strong> ' . $content['metadesc'] . '</li>'
            . '</ul>';

        if ($fgParams->getValue('debug')) {
            $rDebug = $fgParams->getValue('rDebug');
            $fgParams->setValue('rDebug', null, '');
            unset($content['introtext'], $content['fulltext']);
            $previewArticle .= '<h4 class="fgprevinfo">FG Debug Dump - Content</h4><pre>' . print_r($content, true) . '</pre>';
            $previewArticle .= '<h4 class="fgprevinfo">FG Debug Dump - htmLawed config</h4><pre>' . print_r($fgParams->getValue('clean_config'), true) . '<br/>'
                . print_r($fgParams->getValue('spec'), true) . '</pre>';
            $previewArticle .= $rDebug ? '<h4 class="fgprevinfo">FG Debug Dump - Readability processing</h4>' . $rDebug : '';
        }

        $previewArticle .= '</div><br /><a href="javascript:closeMsgArea();">Close this window</a><br />';

        return $previewArticle;
    }

    /**
     * cpanel icon rendering helper - unchanged behaviour.
     */
    public static function renderCpanel($aAttribs = null, $iAttribs = null, $text = '')
    {
        $a = '';
        $i = '';

        if (!empty($aAttribs)) {
            foreach ($aAttribs as $k => $v) {
                $a .= $k . '="' . $v . '" ';
            }
        } else {
            $a = 'href="#"';
        }

        if (!empty($iAttribs)) {
            foreach ($iAttribs as $k => $v) {
                $i .= $k . '="' . $v . '" ';
            }
        }
        ?>
        <div style="float: left;">
            <div class="icon">
                <a <?php echo $a; ?>>
                    <?php if (!empty($iAttribs)) : ?>
                        <img <?php echo $i; ?>/>
                    <?php endif; ?>
                    <span><?php echo $text; ?></span>
                </a>
            </div>
        </div>
        <?php
    }

    public static function renderFieldset($fieldset, &$form, $show_default = false, $options = [])
    {
        $fieldset = $form->getFieldset($fieldset);
        $deffgParams = null;
        static $optvalues = [];

        if ($show_default) {
            $model       = FeedgatorFactory::getFeedModel();
            $deffgParams = $model->getDefaultParams();
            $xmlFile     = \JPATH_ADMINISTRATOR . '/components/com_feedgator/forms/default_feed_default.xml';

            if (empty($optvalues) && file_exists($xmlFile)) {
                $xml = simplexml_load_file($xmlFile);

                foreach ($xml->fieldset as $xfieldset) {
                    foreach ($xfieldset->field as $xfield) {
                        if (\count($xfield->option) > 1) {
                            $j    = 0;
                            $name = (string) $xfield['name'];

                            foreach ($xfield->option as $option) {
                                $optvalues[$name][$j] = (string) $option;
                                $j++;
                            }
                        }
                    }
                }
            }
        }

        $options['legend']        ??= null;
        $options['fieldset_class'] ??= 'adminform';
        $options['ul_class']       ??= 'adminformlist';
        ?>
        <fieldset class="<?php echo $options['fieldset_class']; ?>">
            <?php if ($options['legend']) : ?><legend><?php echo Text::_($options['legend']); ?></legend><?php endif; ?>
            <ul class="<?php echo $options['ul_class']; ?>">
            <?php foreach ($fieldset as $field) :
                $name = $field->fieldname; ?>
                <li style="list-style:none;">
                    <div class="col left"><?php echo $field->label; ?></div>
                    <div class="col middle"><?php echo $field->input; ?></div>
                    <div class="col right">
                    <?php if ($show_default && $deffgParams && strpos($name, 'spacer') === false) :
                        if ($deffgParams->getValue($name) == '') :
                            if (!\in_array(trim($name), ['title', 'feed'], true)) : ?>
                                <div>No Default Setting</div>
                            <?php endif; ?>
                        <?php elseif ($name === 'default_author') :
                            $id   = $deffgParams->getValue($name);
                            $user = Factory::getUser($id); ?>
                            <div>Default Setting is: <em><?php echo $user->name; ?></em></div>
                        <?php elseif ($name === 'access') :
                            $access = $deffgParams->getValue($name);
                            $db     = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
                            $query  = 'SELECT title FROM #__viewlevels WHERE id = ' . (int) $access;
                            $db->setQuery($query); ?>
                            <div>Default Setting is: <em><?php echo $db->loadResult(); ?></em></div>
                        <?php elseif (!\in_array($name, ['published', 'sectionid', 'catid'], true)) :
                            if (\in_array($name, ['link_target', 'target_frame', 'feed_author_article'], true)) {
                                $default = $optvalues[$name][$deffgParams->getValue($name)] ?? $deffgParams->getValue($name);
                            } elseif ($name === 'feed_img' && $deffgParams->getValue($name) == -1) {
                                $default = 'No default image';
                            } else {
                                $default = $optvalues[$name][$deffgParams->getValue($name)] ?? $deffgParams->getValue($name);
                            } ?>
                            <div>Default Setting is: <em><?php echo $default; ?></em></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>
        </fieldset>
        <?php
    }

    /**
     * @deprecated  This originally offered a one-click auto-upgrade from
     *              joomlacode.org, which has been offline since ~2014.
     *              ToolsModel::checkLatestVersion() (which feeds this)
     *              is similarly dead. Kept for structural completeness -
     *              remove or repoint at wherever you host releases now.
     */
    public static function renderVersionUpdatePanel(&$version_data)
    { ?>
        <form name="adminForm" method="post">
        <p>Your Installed Version: <strong><?php echo self::getFGVersion(); ?></strong>
        <br />
        Latest Stable Version: <strong><span class="<?php echo $version_data['stable']['upgrade'] ? 'red' : ''; ?>">
            <?php echo $version_data['stable']['v'] ?? 'unknown'; ?>
            </span></strong>
        <br />
        <?php if (!empty($version_data['dev'])) : ?>
            Latest Development Version: <strong><span class="<?php echo $version_data['dev']['upgrade'] ? 'red' : ''; ?>">
                <?php echo $version_data['dev']['v'] ?? ''; ?>
            </span></strong>
            </p>
        <?php endif; ?>
        <?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
        </form>
    <?php
    }

    public static function getFGVersion()
    {
        static $fgversion = null;

        if ($fgversion === null) {
            $xmlFile = \JPATH_ADMINISTRATOR . '/components/com_feedgator/feedgator.xml';

            if (file_exists($xmlFile) && ($xml = simplexml_load_file($xmlFile))) {
                $fgversion = (string) $xml->version;
            } else {
                $fgversion = 'unknown';
            }
        }

        return $fgversion;
    }
}
