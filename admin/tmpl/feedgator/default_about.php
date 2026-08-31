<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 * Converted from views/feedgator/tmpl/default_about.php - static/marketing
 * content, only the API calls needed modernising.
 */

use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorHelper;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorUtility;

\defined('_JEXEC') or die;
?>

<div class="fgnarrow">
	<div class="fglogo"></div>
	<h1>FeedGator RSS news feed aggregator component for Joomla!</h1>

	<?php FeedgatorHelper::renderVersionUpdatePanel($this->version_data); ?>
	<p><strong>FeedGator</strong> imports RSS feeds into your Joomla! database as regular content items, so you can get more control of the syndicated content on your site. Display RSS content in blog format, or any other method supported by Joomla! Turn your site into a sophisticated news reader.<br />
	This component (or derivatives) is what drives the news section of many Joomla! websites. FeedGator has lots of features to give you the power to manipulate the imported content in useful ways.</p>
	<p>Original author Stephen Simmons now continued and modified by Matt Faulds, Remco Boom & Stephane Koenig, Phill Luckhurst and others, license GNU/GPL: http://www.gnu.org/copyleft/gpl.html</p>
	<p>Ported to Joomla 6</p>
	<br />

	<h3 class="blue">Features Include</h3>
	<div id="featureleft">
		<ul>
			<li>Joomla 6 support</li>
			<li>RSS feed content can be stored as native Joomla articles via the bundled com_content driver</li>
			<li><strong>Robust RSS fetching</strong> via Joomla's built-in Feed API. Supports RSS and Atom feeds.</li>
			<li><strong>Full text importing</strong>, even if not included in the source feed using the <span class="blue"><a href="http://lab.arc90.com/experiments/readability/">Readability</a></span> port by <span class="blue"><a href="http://fivefilters.org/content-only/">FiveFilters.org</a></span></li>
			<li>Robust duplicate handling</li>
			<li>Import logging</li>
			<li>Auto-publishing imported content</li>
			<li>Access control for imported content</li>
			<li>Ability to specify the number of days content remains published</li>
		</ul>
	</div>
	<div id="featureright">
		<ul>
			<li><strong>Feed filtering</strong> based on whitelist/blacklist set for each feed</li>
			<li><strong class="blue"><a href="http://www.bioinformatics.org/phplabware/internal_utilities/htmLawed/">htmLawed</a></strong> (X)HTML filter, processor, purifier, sanitizer and beautifier for imported text</li>
			<li><strong>HTML tag</strong> filtering</li>
			<li>Optional built-in automatic keyword tagging</li>
			<li>Optional trackback links to the original content</li>
			<li>Optional trackback link accessibility compliance</li>
			<li>Optional <strong>automated imports</strong> using a Joomla Scheduled Task</li>
			<li>Easy to read HTML reports online or via email</li>
		</ul>
	</div>
	<div style="clear:both;">
	<br />
	<br />

	<h3 class="blue">Release Notes</h3>
	<div>
		<ul>
			<li>Joomla 5/6 Beta release</li>
	</div>
</div>
