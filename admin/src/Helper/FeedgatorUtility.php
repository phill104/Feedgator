<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Converted from helpers/feedgator.utility.php. Logic preserved 1:1;
 * notable fixes required for PHP 8 (not just style modernisation):
 *  - create_function() was removed entirely in PHP 8.0 - splitText()'s
 *    trailing array_walk() now uses a closure.
 *  - utf8_encode()/utf8_decode() are deprecated since PHP 8.2 (and gone
 *    in PHP 9) - replaced with mb_convert_encoding() equivalents.
 *  - JString::* (a UTF-8-safe string wrapper) no longer exists - replaced
 *    with native mb_* functions. str_ireplace() has no true multibyte
 *    equivalent in core PHP; cleanMeta() below uses a Unicode-aware
 *    regex instead of JString::str_ireplace() to stay correct for
 *    non-Latin feed content.
 *
 * hook_tag_cleaning() is called directly by the bundled htmLawed library
 * with a fixed (element, attributes) signature that htmLawed itself
 * controls - there is no way to thread $fgParams through as a normal
 * parameter without patching htmLawed. cleanText() sets a global right
 * before invoking htmLawed() specifically so this hook can read it; this
 * is the one place in the conversion that still uses a global, and it's
 * a constraint of the third-party library's calling convention rather
 * than a design choice carried over from the original code.
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Helper;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

class FeedgatorUtility
{
    // Set by getUrl() whenever a cURL fetch fails, so callers (see
    // SimplePieFeedAdapter) can surface the specific reason (DNS
    // failure, connection timeout, SSL error, etc.) instead of a
    // generic "couldn't fetch" message - this turned out to matter in
    // practice: it's what revealed a real host-to-destination network
    // connectivity problem (cURL errno 28, connection timeout) rather
    // than a code bug, on the first feed that needed it.
    public static ?string $lastCurlError = null;

    public static function adjustLink(&$link, &$fgParams)
    {
        // Reserved for future query-string adjustments to the trackback URL.
        return $link;
    }

    /**
     * Applies find/replace and regex text adjustments per feed parameter settings.
     */
    public static function adjustText(&$text, &$fgParams)
    {
        if ($fgParams->getValue('text_filter')) {
            $search   = [];
            $replace  = [];
            $regex    = [];
            $regplace = [];

            if ($fgParams->getValue('text_filter_remove')) {
                foreach (explode(',', $fgParams->getValue('text_filter_remove')) as $s) {
                    $search[]  = str_replace('[[comma]]', ',', $s);
                    $replace[] = '';
                }
            }

            if ($fgParams->getValue('text_filter_replace')) {
                foreach (explode("\n", $fgParams->getValue('text_filter_replace')) as $pair) {
                    $pair      = explode('===', $pair);
                    $search[]  = trim($pair[0]);
                    $replace[] = trim($pair[1] ?? '');
                }
            }

            if ($fgParams->getValue('text_filter_regex')) {
                foreach (explode("\n", $fgParams->getValue('text_filter_regex')) as $pair) {
                    $pair       = explode('===', $pair);
                    $regex[]    = trim($pair[0]);
                    $regplace[] = trim($pair[1] ?? '');
                }
            }

            if ($search) {
                $text = str_replace($search, $replace, $text);
            }

            if ($regex) {
                $text = preg_replace($regex, $regplace, $text);
            }
        }

        return $text;
    }

    /**
     * Cleans HTML via the bundled htmLawed library. See class docblock
     * for why $fgParams is set to a global here.
     */
    public static function cleanText($text, $clean_config, $spec, &$fgParams)
    {
        require_once \JPATH_ADMINISTRATOR . '/components/com_feedgator/inc/htmLawed/htmLawed.php';

        // Populate the global for hook_tag_cleaning() to read, since
        // htmLawed calls that function internally with a fixed
        // (element, attributes) signature it can't be given extra
        // parameters through. IMPORTANT: this must SET $GLOBALS
        // (propagating the already-received parameter outward), not
        // "global $fgParams;" - that statement reads *from* the global
        // scope and would overwrite this method's own $fgParams
        // parameter with whatever (undefined, i.e. null) global
        // happened to exist, which is exactly the bug that was here
        // before and crashed on the very next line.
        $GLOBALS['fgParams'] = $fgParams;

        $text = self::stripEmptyTags($text);

        // Defensive against PHP's PCRE backtrack limit: on very large
        // or deeply-nested real-world HTML (page builders like
        // Elementor routinely produce huge, heavily-nested markup),
        // any preg_* call can silently return null rather than
        // throwing once it hits pcre.backtrack_limit - and null would
        // then silently wipe out the entire remaining article if not
        // guarded against, at any of the three preg_* steps in this
        // function (this one, htmLawed()'s own internals, and the
        // trailing <br> cleanup below). Each step here keeps the
        // pre-step text if its result comes back null, rather than
        // letting one failed regex destroy everything already
        // processed successfully so far.
        if (strpos($fgParams->getValue('strip_list'), '*script') === false) {
            $stripped = preg_replace('/<script[^>]*?>[\s\S]*?<\/script>/i', '', $text);

            if ($stripped !== null) {
                $text = $stripped;
            }
        }

        $cleaned = \htmLawed($text, $clean_config, $spec);

        if ($cleaned !== null && $cleaned !== '') {
            $text = $cleaned;
        }

        $trimmed = preg_replace('#(<br[^>]*>)*$#u', '', $text);

        if ($trimmed !== null) {
            $text = $trimmed;
        }

        return $text;
    }

    /**
     * Removes empty HTML tags.
     */
    public static function stripEmptyTags($result)
    {
        $regexps = ['~<(\w+)\b[^\>]*>\s*</\\1>~', '~<\w+\s*/>~'];

        do {
            $string = $result;
            $result = preg_replace($regexps, '', $string);
        } while ($result != $string);

        return $result;
    }

    /**
     * htmLawed hook_tag callback - see class docblock for why $fgParams
     * is read from a global here rather than passed as a parameter.
     */
    public static function hook_tag_cleaning($element, $attribute_array = 0)
    {
        global $fgParams;

        if (is_numeric($attribute_array)) {
            return "</$element>";
        }

        $selfclose = ['hr', 'br', 'img', 'input'];
        $string    = '';
        $block     = false;

        [, $hook_tag] = FeedgatorHelper::getTagsToStrip($fgParams);

        $regex = '/([\S]+)\s*?([^=]*)?=?([\S]*)?/';

        if (!empty($hook_tag)) {
            $white    = strpos($hook_tag, '+') ? 1 : 0;
            $hook_tag = str_replace('+', '', $hook_tag);
            $parts    = explode(',', $hook_tag);

            foreach ($parts as $part) {
                preg_match($regex, $part, $matches);

                if (isset($matches[1]) && $element == $matches[1]) {
                    if (empty($matches[2])) {
                        $block = $white;
                    }

                    if (isset($attribute_array[trim($matches[2])]) && @$attribute_array[trim($matches[2])] == trim($matches[3])) {
                        $block = !$white;
                    }
                }
            }

            if ($block) {
                return '';
            }
        }

        if ($element === 'a' && $fgParams->getValue('link_target', null, 0)) {
            $attribute_array['target'] = $fgParams->getValue('link_target');
        }

        foreach ($attribute_array as $k => $v) {
            $string .= " {$k}=\"{$v}\"";
        }

        return "<{$element}{$string}" . (\in_array($element, $selfclose, true) ? ' /' : '') . '>';
    }

    public static function cleanMeta(&$content)
    {
        if (!empty($content['metakey'])) {
            // Unicode-aware equivalent of the original JString::str_ireplace() (JString no longer exists).
            $after_clean = preg_replace('/[\n\r"<>]/u', '', $content['metakey']);
            $keys        = array_unique(explode(',', $after_clean));
            $clean_keys  = [];

            foreach ($keys as $key) {
                if (trim($key)) {
                    $clean_keys[] = trim($key);
                }
            }

            $content['metakey'] = implode(', ', $clean_keys);
        }

        if (!empty($content['metadesc'])) {
            $content['metadesc'] = preg_replace('/["<>]/u', '', $content['metadesc']);
        }
    }

    /**
     * Splits a string by char, word, or sentence while optionally
     * preserving HTML tags.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function splitText($text, $trimTo, $type = 'char', $keep_tags = true)
    {
        $textArray = [0 => '', 1 => '', 2 => ''];

        if (!$keep_tags) {
            $text = strip_tags($text);
        }

        if (!$trimTo) {
            $textArray[0] = $text;

            return $textArray;
        }

        $length = 0;
        $split  = false;
        $matches = [[]];

        if (!$keep_tags) {
            $matches[0] = explode(' ', $text);
        } else {
            $regex = '#<[^<^>]*>|[^<]*|<[^<^>]*#u';
            $text  = preg_replace('/>\s*</', '><', $text);
            preg_match_all($regex, $text, $matches);
        }

        // $textArray[1] is meant to be "the rest of the article after the
        // intro cutoff" (used as fulltext by makeParts()) - it used to be
        // set once here to the whole, untouched $text and never updated
        // again below, so every caller got the entire original article
        // back as "the remainder" (duplicating whatever's in the intro
        // at the very start of fulltext) rather than actually just the
        // continuation. Each switch case below now appends to
        // $textArray[1] once the split point is reached, same as it
        // already did for $textArray[0] before that point.

        switch ($type) {
            case 'char':
                foreach ($matches[0] as $match) {
                    if (!$split) {
                        $m = mb_strlen($match);

                        if (mb_strpos($match, '<') !== 0 || mb_strpos($match, '>') !== ($m - 1)) {
                            $length += $m;
                        }

                        if ($length <= $trimTo) {
                            $textArray[0] .= $match;
                        } else {
                            $offset = $trimTo - ($length - $m);
                            $introPart = mb_substr($match, 0, $offset);
                            $lastSpace = strrpos($introPart, ' ');

                            if ($lastSpace) {
                                $introPart = mb_substr($introPart, 0, $lastSpace);
                            }

                            $textArray[0] .= $introPart;
                            // Whatever of this token wasn't used for the
                            // intro starts the remainder.
                            $textArray[1] .= mb_substr($match, mb_strlen($introPart));
                            $split = true;
                        }
                    } else {
                        $textArray[1] .= $match;
                    }
                }

                break;

            case 'word':
                $string = '';
                $i      = 0;

                foreach ($matches[0] as $match) {
                    if (!$split) {
                        if (mb_strpos($match, '<') !== 0 || mb_strpos($match, '>') !== (mb_strlen($match) - 1)) {
                            $explode = explode(' ', trim($match));

                            foreach ($explode as $k => $ematch) {
                                if ($i < $trimTo) {
                                    $string .= $ematch . ' ';
                                    $i++;
                                } else {
                                    $textArray[0] = trim($string);
                                    // Remaining words in this same token,
                                    // plus everything after it, become
                                    // the remainder.
                                    $textArray[1] .= implode(' ', \array_slice($explode, $k));
                                    $split = true;
                                    break;
                                }
                            }

                            if (empty($textArray[0]) && !$split) {
                                $textArray[0] = trim($string);
                            }
                        } else {
                            $string .= $match;
                        }
                    } else {
                        $textArray[1] .= $match;
                    }
                }

                if (!$split) {
                    $textArray[0] = trim($string);
                }

                break;

            case 'sent':
                $string  = '';
                $pattern = '#([\s\S]*?[\.|\!|\?])(?:[\s]+|$)#u';
                $i       = 0;

                foreach ($matches[0] as $match) {
                    if (!$split) {
                        if (mb_strpos($match, '<') !== 0 || mb_strpos($match, '>') !== (mb_strlen($match) - 1)) {
                            for (; $i < $trimTo; $i++) {
                                if (!preg_match($pattern, $match, $smatches)) {
                                    break;
                                }

                                $textArray[0] .= ($i == 0) ? $smatches[1] : ' ' . $smatches[1];

                                $offset = mb_strpos($match, $smatches[1]);
                                $offset += \strlen($smatches[1]) - 1;
                                $match = mb_substr($match, $offset);

                                if (!$match) {
                                    break;
                                }
                            }

                            if ($i >= $trimTo) {
                                // Whatever's left of this token, plus
                                // every token after it, is the remainder.
                                $textArray[1] .= $match;
                                $split = true;
                            }
                        } else {
                            $textArray[0] .= $match;
                        }
                    } else {
                        $textArray[1] .= $match;
                    }
                }

                break;
        }

        array_walk($textArray, static function (&$val) {
            $val = trim($val);
        });

        return $textArray;
    }

    /**
     * Sends the import-summary email to the configured admin address.
     */
    public static function sendAdminEmail($message = '')
    {
        $app      = Factory::getApplication();
        $db       = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $fgConfig = ComponentHelper::getParams('com_feedgator');

        $mailfrom = $app->get('mailfrom');
        $fromname = $app->get('fromname');

        if (!$mailfrom || !$fromname) {
            $query = "SELECT name, email FROM #__users WHERE LOWER(usertype) = " . $db->quote('super administrator');
            $db->setQuery($query);
            $rows = $db->loadObjectList();

            if ($rows) {
                $fromname = $rows[0]->name;
                $mailfrom = $rows[0]->email;
            }
        }

        $subject = html_entity_decode((string) $fgConfig->get('email_subject'), ENT_QUOTES);
        $email   = $fgConfig->get('admin_email');
        $isHtml  = $fgConfig->get('html_email') == '1';

        if ($isHtml) {
            $css = '<style type="text/css">'
                . 'body { color:#000000; font-size: 12px; font-family:Arial, Helvetica, sans-serif;}'
                . '.feedmsg { color:#0400A2; line-height: 1.4em;}'
                . '#feedinfo { border:1px solid #bababa; padding:0 10px;}'
                . 'h1 { color:#618700; font-size: 16px; margin:10px 0 5px 0;}'
                . 'h2 { color:#e56d02; font-size: 14px; margin:5px 10px 0 10px;}'
                . 'h3 { color:#e56d02; font-size: 12px; margin:5px 10px 0 10px;}'
                . 'h4 { color:#e56d02; font-size: 8px; margin:5px 10px 0 10px;}'
                . '.small { color:#999999; font-size: 10px;}'
                . '#feedinfo a:link, #feedinfo a:visited { color:#990000;}'
                . '</style>';
            $message = $css . nl2br($message);
        } else {
            $message = strip_tags($message);
        }

        try {
            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->setSender([$mailfrom, $fromname]);
            $mailer->addRecipient($email);
            $mailer->setSubject($subject);
            $mailer->isHtml($isHtml);
            $mailer->setBody($message);

            return (bool) $mailer->Send();
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function log($msg, $priority = '', $category = '')
    {
        $category = $category ?: 'Some Error:';

        Log::addLogger(['text_file' => 'com_feedgator.log.php'], Log::ALL, [$category]);
        Log::add($msg, $priority ?: Log::INFO, $category);
    }

    /**
     * Lightweight profiling/logging hook. Uses Joomla's Profiler when
     * FeedGator's own debug setting is on.
     */
    public static function profiling($string)
    {
        static $profiler = null;
        static $debugChecked = false;
        static $debugOn = false;

        if (!$debugChecked) {
            $fgConfig     = ComponentHelper::getParams('com_feedgator');
            $debugOn      = (bool) $fgConfig->get('debug');
            $debugChecked = true;
        }

        if ($debugOn) {
            if ($profiler === null) {
                $profiler = new \Joomla\CMS\Profiler\Profiler('FG');
                $profiler->mark('Start');
            } else {
                $profiler->mark($string);
            }
        }
    }

    public static function parseINIString($string)
    {
        $registry = new Registry();
        $registry->loadString($string, 'INI');

        return $registry->toArray();
    }

    public static function makeINIString($array)
    {
        $registry = new Registry($array);

        return $registry->toString('INI');
    }

    public static function strpos_array($haystack, $needle)
    {
        if (!\is_array($needle)) {
            $needle = [$needle];
        }

        foreach ($needle as $what) {
            if ($what && ($pos = strpos($haystack, $what)) !== false) {
                return $pos;
            }
        }

        return false;
    }

    public static function in_array_recursive($needle, $haystack)
    {
        foreach ($haystack as $value) {
            if (\is_array($value)) {
                if (self::in_array_recursive($needle, $value)) {
                    return true;
                }
            } elseif ($value == $needle) {
                return true;
            }
        }

        return false;
    }

    public static function array_overlay($a1, $a2)
    {
        foreach ($a1 as $k => $v) {
            if ($a1[$k] === '' && isset($a2[$k])) {
                $a1[$k] = $a2[$k];
            }
        }

        return $a1;
    }

    public static function str_replace_first($search, $replace, $string)
    {
        $pos = strpos($string, $search);

        if ($pos !== false) {
            $string = substr_replace($string, $replace, $pos, \strlen($search));
        }

        return $string;
    }

    /**
     * Manually follows HTTP redirects by inspecting the status code and
     * Location header of each hop. Needed because CURLOPT_FOLLOWLOCATION
     * cannot be relied on when open_basedir is set (a long-standing PHP/
     * cURL restriction on many shared hosts) - without this, a feed URL
     * that 301/302-redirects (very common - WordPress in particular
     * often redirects /feed to /feed/) would silently return the tiny
     * HTML redirect page itself as if it were the real content, which
     * is exactly what was happening before this method existed.
     */
    private static function resolveFinalUrl($url, $userAgent, $maxRedirects = 5)
    {
        $current = $url;

        for ($i = 0; $i < $maxRedirects; $i++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $current);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!\in_array($status, [301, 302, 303, 307, 308], true) || !$response) {
                return $current;
            }

            if (!preg_match('/^Location:\s*(.+?)\r?$/mi', $response, $m)) {
                return $current;
            }

            $location = trim($m[1]);

            if (!preg_match('#^https?://#i', $location)) {
                $location = self::makeAbsUrl($current, $location);
            }

            $current = $location;
        }

        return $current;
    }

    /**
     * Fetches a URL via cURL (preferred) or the fopen wrapper. If
     * $file_path is given, saves the response directly to that path.
     */
    public static function getUrl($url, $type = null, $expected_result = 'html', $file_path = null, $parts = null, $timeoutSeconds = null)
    {
        if (\function_exists('curl_init') && (!$type || $type === 'cURL')) {
            // NOTE: this used to unconditionally strip the URL's
            // protocol (`if (strpos($url, '//')) { ...explode/slice... }`)
            // before ever reaching cURL - strpos() finds "//" in
            // virtually every normal http(s) URL, so this was firing on
            // every single fetch, leaving a scheme-less string like
            // "www.example.com/feed/" for cURL to guess a protocol for.
            // That's not a legitimate need for a feed/page fetch (only
            // ever worked by luck when a given server happened to
            // accept a bare-HTTP guess or similar) - removed entirely.
            $url = html_entity_decode(trim($url), ENT_QUOTES);
            $url = strip_tags($url);

            // Modern, realistic User-Agent - the original used a
            // fingerprintable 2009-era Firefox string, which some
            // modern bot-protection layers (Cloudflare etc.) may block
            // or challenge, silently returning an HTML error/challenge
            // page instead of the requested feed/page.
            $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

            // The original pointed this at helpers/cookies.txt, a path
            // that no longer exists after this component's admin/helpers
            // folder was restructured into admin/src/Helper/ during the
            // Joomla 6 conversion - point it at Joomla's own writable
            // cache directory instead of a path that's guaranteed missing.
            $cookie_path = \JPATH_ROOT . '/cache/com_feedgator_cookies.txt';

            $followLocationOk = !ini_get('open_basedir');

            if (!$followLocationOk) {
                // See resolveFinalUrl()'s docblock - this is the actual
                // fix for feed URLs that redirect (e.g. WordPress's
                // /feed -> /feed/), which previously silently returned
                // the redirect's own tiny HTML page as if it were the
                // feed content.
                $url = self::resolveFinalUrl($url, $userAgent);
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            curl_setopt($ch, CURLOPT_AUTOREFERER, true);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_path);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_path);
            // Auto-request and auto-decompress gzip/deflate - without
            // this, a server that compresses its response by default
            // would hand back raw compressed bytes that any XML/HTML
            // parser downstream would fail on.
            curl_setopt($ch, CURLOPT_ENCODING, '');
            // Without an explicit timeout, a genuinely unreachable
            // destination (blocked outbound connection, dead server,
            // etc.) hangs for whatever PHP/cURL's own default is - one
            // real case took over 130 seconds to fail. 45s is generous
            // for any legitimately-reachable feed/page while still
            // failing fast enough that one bad feed can't stall an
            // "Import All" run for minutes. A caller-supplied value
            // (see SimplePieFeedAdapter::set_timeout(), wired to each
            // feed's own "Timeout" setting) overrides this default;
            // connect timeout is capped at 20s regardless, since a
            // dead TCP connection should never need longer than that
            // to at least establish.
            $totalTimeout = $timeoutSeconds ?: 45;
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(20, $totalTimeout));
            curl_setopt($ch, CURLOPT_TIMEOUT, $totalTimeout);

            if ($followLocationOk) {
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            }

            switch ($expected_result) {
                case 'html':
                    curl_setopt($ch, CURLOPT_HEADER, 1);
                    break;

                case 'noheader':
                    curl_setopt($ch, CURLOPT_HEADER, 0);
                    break;

                case 'header':
                    curl_setopt($ch, CURLOPT_HEADER, 1);
                    curl_setopt($ch, CURLOPT_NOBODY, 1);
                    break;

                case 'goo.gl':
                    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_HEADER, 0);
                    curl_setopt($ch, CURLOPT_CAINFO, \JPATH_ADMINISTRATOR . '/components/com_feedgator/inc/curl/cacert.pem');
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-type: application/json']);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['longUrl' => $parts[0]]));
                    break;

                case 'yql':
                    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_HEADER, 0);
                    curl_setopt($ch, CURLOPT_CAINFO, \JPATH_ADMINISTRATOR . '/components/com_feedgator/inc/curl/cacert.pem');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $parts[0]);
                    break;

                case 'images':
                default:
                    curl_setopt($ch, CURLOPT_HEADER, 0);
                    curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
                    break;
            }

            $fp = null;

            if ($file_path) {
                self::profiling('Saving File (cURL): ' . $file_path);
                $fp = fopen($file_path, 'w');
                curl_setopt($ch, CURLOPT_FILE, $fp);
            }

            self::profiling('Accessing URL (cURL): ' . $url);
            $page = curl_exec($ch);

            // See FeedgatorUtility::$lastCurlError's docblock - kept
            // permanently, not just for this one debugging session.
            if ($page === false) {
                self::$lastCurlError = '[errno ' . curl_errno($ch) . '] ' . curl_error($ch);
            } else {
                self::$lastCurlError = null;
            }

            curl_close($ch);

            if ($fp) {
                fclose($fp);
            }

            return $page;
        }

        // fopen wrapper fallback (used only when the cURL extension
        // isn't available - uncommon on modern hosting, but given the
        // same User-Agent-related issue applies here too, this now
        // sends the same modern User-Agent as the cURL path above
        // rather than PHP's unset default.
        self::profiling('Accessing URL (fopen): ' . $url);

        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

        if ($file_path) {
            self::profiling('Saving File (fopen): ' . $file_path);
            $streamContext = stream_context_create(['http' => ['header' => "User-Agent: {$userAgent}\r\n"]]);
            $stream        = @fopen($url, 'r', false, $streamContext);

            if (!$stream) {
                return false;
            }

            $saved = file_put_contents($file_path, $stream);
            fclose($stream);

            return (bool) $saved;
        }

        if ($expected_result === 'goo.gl') {
            $context = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-type: application/json\r\nUser-Agent: {$userAgent}\r\n",
                    'content' => json_encode(['longUrl' => $parts[0]]),
                ],
            ]);
        } else {
            $context = stream_context_create(['http' => ['header' => "User-Agent: {$userAgent}\r\n"]]);
        }

        return file_get_contents($url, false, $context);
    }

    /**
     * Splits a raw cURL/fopen HTTP response into headers + body.
     *
     * NOTE: $fgParams is accepted for call-site compatibility with the
     * original signature but was never actually used in the body below
     * (checked against the original 2.5 source too) - it's a plain
     * value parameter here (not by-reference) so callers can pass a
     * literal like `null` without triggering PHP's "only variables can
     * be passed by reference" fatal error.
     */
    public static function extractHTTP($string, $fgParams = null)
    {
        $parts = ['headers' => [0 => ''], 'body' => ''];

        $lines = explode("\r\n", $string);

        if (empty($lines)) {
            $lines = explode("\n", $string);
        }

        $splittingheaders = true;
        $header           = 0;
        $count            = \count($lines);

        for ($i = 0; $i < $count; $i++) {
            if ($splittingheaders) {
                $parts['headers'][$header] .= $lines[$i] . "\n";
            } else {
                $parts['body'] .= $lines[$i] . "\n";
            }

            if (trim($lines[$i]) === '') {
                if (strpos($lines[$i + 1] ?? '', 'HTTP/') === false) {
                    $splittingheaders = false;
                } else {
                    $header++;
                    $parts['headers'][$header] = '';
                }
            }
        }

        $parts['header'] = array_pop($parts['headers']);

        if (strpos($parts['header'], 'Content-Encoding: gzip') !== false) {
            $parts['body'] = gzinflate(substr($parts['body'], 10, -8));
        }

        return $parts;
    }

    public static function savefile($contents, $name, $update = false, $header = '', $path = null)
    {
        $name = File::makeSafe($name);
        $path = $path ?: \JPATH_ADMINISTRATOR . '/components/com_feedgator/';
        $path = Folder::makeSafe($path);

        $filePath = rtrim($path, '/') . '/' . $name;

        if ($update && file_exists($filePath)) {
            $out = fopen($filePath, 'a');
        } else {
            $out = fopen($filePath, 'w');

            if ($update && $header) {
                fwrite($out, $header);
            }
        }

        fwrite($out, $contents);
        fclose($out);
    }

    /**
     * Resolves a relative URL against a base URL.
     */
    public static function makeAbsUrl($base, $rel)
    {
        $r = parse_url($rel);

        if (isset($r['scheme'])) {
            return $rel;
        }

        if (strpos($rel, '//') === 0) {
            return 'http:' . $rel;
        }

        $baseParts = parse_url($base);
        $host      = $baseParts['host'] ?? '';
        $scheme    = $baseParts['scheme'] ?? null;
        $path      = $baseParts['path'] ?? '/';

        if (strrpos($path, '/') != \strlen($path) - 1) {
            $path = \dirname($path);
        }

        if (strpos($rel, '/') === 0) {
            $path = $host . $rel;
        } else {
            $aparts = array_filter(explode('/', $path));
            $rparts = array_filter(explode('/', $rel));
            $cparts = array_merge($aparts, $rparts);

            foreach ($cparts as $i => $part) {
                if ($part === '.') {
                    unset($cparts[$i]);
                }

                if ($part === '..') {
                    unset($cparts[$i], $cparts[$i - 1]);
                }
            }

            $path = $host . '/' . implode('/', $cparts);
        }

        $url = $scheme ? "$scheme://" : '';

        return $url . $path;
    }

    /**
     * Converts $html to UTF-8 using HTTP headers and/or embedded
     * encoding hints. utf8_encode()/utf8_decode() replaced with
     * mb_convert_encoding() (the former are deprecated in PHP 8.2+ and
     * removed in PHP 9).
     */
    public static function convert_to_utf8($html, $header = null)
    {
        $accept   = ['application/rss+xml', 'application/xml', 'application/rdf+xml', 'text/xml'];
        $encoding = null;

        if ($html || $header) {
            if (\is_array($header)) {
                $header = implode("\n", $header);
            }

            if ($header && preg_match_all('/^Content-Type:\s+([^;]+)(?:;\s*charset=["]?([^"^\s]*))?/im', $header, $match, PREG_SET_ORDER)) {
                $match = end($match);

                if (isset($match[2])) {
                    $encoding = trim($match[2], '"\'');
                }
            }

            if (!$encoding) {
                if (preg_match('/^<\?xml\s+version=(?:"[^"]*"|\'[^\']*\')\s+encoding=("[^"]*"|\'[^\']*\')/s', $html, $match)) {
                    $encoding = trim($match[1], '"\'');
                } elseif (preg_match('/<meta\s+http-equiv="Content-Type" content="([^;]+)(?:;\s*charset=["]?([^"^\s]*))?"/i', $html, $match)) {
                    if (isset($match[2])) {
                        $encoding = trim($match[2], '"\'');
                    }
                }
            }

            if (!$encoding) {
                $encoding = 'utf-8';
            } else {
                $encoding = strtolower(trim($encoding));

                if ($encoding !== 'utf-8') {
                    if ($encoding === 'iso-8859-1') {
                        $trans               = [];
                        $trans[chr(130)] = '&sbquo;';
                        $trans[chr(131)] = '&fnof;';
                        $trans[chr(132)] = '&bdquo;';
                        $trans[chr(133)] = '&hellip;';
                        $trans[chr(134)] = '&dagger;';
                        $trans[chr(135)] = '&Dagger;';
                        $trans[chr(136)] = '&circ;';
                        $trans[chr(137)] = '&permil;';
                        $trans[chr(138)] = '&Scaron;';
                        $trans[chr(139)] = '&lsaquo;';
                        $trans[chr(140)] = '&OElig;';
                        $trans[chr(145)] = '&lsquo;';
                        $trans[chr(146)] = '&rsquo;';
                        $trans[chr(147)] = '&ldquo;';
                        $trans[chr(148)] = '&rdquo;';
                        $trans[chr(149)] = '&bull;';
                        $trans[chr(150)] = '&ndash;';
                        $trans[chr(151)] = '&mdash;';
                        $trans[chr(152)] = '&tilde;';
                        $trans[chr(153)] = '&trade;';
                        $trans[chr(154)] = '&scaron;';
                        $trans[chr(155)] = '&rsaquo;';
                        $trans[chr(156)] = '&oelig;';
                        $trans[chr(159)] = '&Yuml;';
                        $html                = strtr($html, $trans);
                    }

                    if (\function_exists('iconv')) {
                        $html = @iconv($encoding, 'utf-8', $html);
                    } elseif (\function_exists('mb_convert_encoding')) {
                        $html = @mb_convert_encoding($html, 'utf-8', $encoding);
                    }
                }
            }
        }

        return $html;
    }

    /**
     * Slugifies a string (accented-character folding + hyphenation).
     */
    public static function stringURLSafe($string)
    {
        $str = preg_replace('/\xE3\x80\x80/', ' ', $string);
        $str = str_replace('-', ' ', $str);
        $str = preg_replace('#[:\#\*"@+=;!&%\.,\]\/\'\\\\|\[]#', "\x20", $str);
        $str = str_replace('?', '', $str);
        $str = mb_strtolower(trim($str));
        $str = preg_replace('#\x20+#', '-', $str);

        return $str;
    }

    /**
     * Returns a lowercase transliterated string for use in aliases.
     */
    public static function transliterate($string, $custom)
    {
        $glyph_array = [];

        if ($custom) {
            $array = explode("\n", $custom);

            foreach ($array as $v) {
                $v                       = explode('=', $v);
                $glyph_array[$v[0]] = $v[1] ?? '';
            }
        } else {
            $glyph_array = [
                'a'    => 'à,á,â,ã,ä,å,ā,ă,ą,ḁ,α,ά',
                'ae'   => 'æ',
                'b'    => 'β,б',
                'c'    => 'ç,ć,ĉ,ċ,č,ч,ћ,ц',
                'ch'   => 'ч',
                'd'    => 'ď,đ,Ð,д,ђ,δ,ð',
                'dz'   => 'џ',
                'e'    => 'è,é,ê,ë,ē,ĕ,ė,ę,ě,э,ε,έ',
                'f'    => 'ƒ,ф',
                'g'    => 'ğ,ĝ,ğ,ġ,ģ,г,γ',
                'h'    => 'ĥ,ħ,Ħ,х',
                'i'    => 'ì,í,î,ï,ı,ĩ,ī,ĭ,į,и,й,ъ,ы,ь,η,ή',
                'ij'   => 'ĳ',
                'j'    => 'ĵ',
                'ja'   => 'я',
                'ju'   => 'яю',
                'k'    => 'ķ,ĸ,κ',
                'l'    => 'ĺ,ļ,ľ,ŀ,ł,л,λ',
                'lj'   => 'љ',
                'm'    => 'μ',
                'n'    => 'ñ,ņ,ň,ŉ,ŋ,н,ν',
                'nj'   => 'њ',
                'o'    => 'ò,ó,ô,õ,ø,ō,ŏ,ő,ο,ό,ω,ώ',
                'oe'   => 'œ,ö',
                'p'    => 'п,π',
                'ph'   => 'φ',
                'ps'   => 'ψ',
                'r'    => 'ŕ,ŗ,ř,р,ρ,σ,ς',
                's'    => 'ş,ś,ŝ,ş,š,с',
                'ss'   => 'ß,ſ',
                'sh'   => 'ш',
                'shch' => 'щ',
                't'    => 'ţ,ť,ŧ,τ,т',
                'th'   => 'θ',
                'u'    => 'ù,ú,û,ü,ũ,ū,ŭ,ů,ű,ų,у',
                'v'    => 'в',
                'w'    => 'ŵ',
                'x'    => 'χ,ξ',
                'y'    => 'ý,þ,ÿ,ŷ',
                'z'    => 'ź,ż,ž,з,ж,ζ',
            ];
        }

        foreach ($glyph_array as $letter => $glyphs) {
            $string = str_replace(explode(',', $glyphs), $letter, $string);
        }

        return $string;
    }

    public static function encode_url($url)
    {
        $reserved = [
            ':' => '!%3A!ui', '/' => '!%2F!ui', '?' => '!%3F!ui', '#' => '!%23!ui',
            '[' => '!%5B!ui', ']' => '!%5D!ui', '@' => '!%40!ui', '!' => '!%21!ui',
            '$' => '!%24!ui', '&' => '!%26!ui', "'" => '!%27!ui', '(' => '!%28!ui',
            ')' => '!%29!ui', '*' => '!%2A!ui', '+' => '!%2B!ui', ',' => '!%2C!ui',
            ';' => '!%3B!ui', '=' => '!%3D!ui', '%' => '!%25!ui',
        ];

        $url = str_replace(['%09', '%0A', '%0B', '%0D'], '', $url);
        $url = rawurlencode($url);
        $url = preg_replace(array_values($reserved), array_keys($reserved), $url);

        return $url;
    }

    public static function buffer($var)
    {
        ob_start();
        print_r($var);
        $ret = ob_get_contents();
        ob_clean();

        return $ret;
    }
}
