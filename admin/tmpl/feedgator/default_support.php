<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from views/feedgator/tmpl/default_support.php.
 * joomlacode.org (referenced throughout the original) has been offline
 * since ~2014 - those links are left as historical context but won't
 * resolve. The "automator" system plugin and cron.feedgator.php are
 * both superseded by a Joomla Scheduled Task in this port (see
 * MIGRATION_REPORT.md) - this panel's cron instructions are kept for
 * reference in case you still run FeedGator via a real system crontab.
 */

use Joomla\CMS\HTML\HTMLHelper;

\defined('_JEXEC') or die;

HTMLHelper::_('bootstrap.tooltip');

$acc = 'fgsupportAccordion';
?>
<div class="fgsupport">
	<div class="fglogo"></div>
	<h1>FeedGator Support</h1>
	<ul class="main">
		<li>Are you <strong class="blue">having problems</strong> using FeedGator?</li>
		<li>Do you have questions about <strong class="blue">how to use FeedGator?</strong></li>
		<li>Do you want to configure FeedGator to <strong class="blue">run automatically?</strong></li>
	</ul>
	<h3>Here's the help you're looking for:</h3>
	<br />

	<div class="accordion" id="<?php echo $acc; ?>">
		<div class="accordion-item">
			<h2 class="accordion-header">
				<button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $acc; ?>_panel1">Configuring automatic imports</button>
			</h2>
			<div id="<?php echo $acc; ?>_panel1" class="accordion-collapse collapse show" data-bs-parent="#<?php echo $acc; ?>">
				<div class="accordion-body">
					<p>RSS feeds can be imported automatically at regular intervals using Joomla's built-in Scheduled Tasks (System &rarr; Scheduled Tasks). Running an import task is essentially the same as clicking the "Import All" link from the administrative interface. All of your settings are preserved.</p>

					<h4>Important Points</h4>
					<ul>
						<li>The actual frequency that a feed is parsed for importing is set within FeedGator a) through the feed default settings and b) for each individual feed. Under <em>Processing and Duplicates</em> there are 2 relevant options: "cron Import Limit" and "cron Interval". Using these settings you can restrict the number of items imported with each run and vary the frequency that individual feeds are processed independent of the task's own schedule.</li>
					</ul>
				</div>
			</div>
		</div>
		<div class="accordion-item">
			<h2 class="accordion-header">
				<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $acc; ?>_panel2">Report a bad feed</button>
			</h2>
			<div id="<?php echo $acc; ?>_panel2" class="accordion-collapse collapse" data-bs-parent="#<?php echo $acc; ?>">
				<div class="accordion-body">
					<p>Before reporting a feed as broken:</p>
					<ol>
						<li>Make sure the feed URL is correct by pasting it into your browser's address bar - if you see an error, or a web page instead of a feed, it can't be imported.</li>
						<li>Try importing it a few times before concluding it's broken. Slow or busy servers can occasionally cause a timeout, which isn't a bug or a bad feed.</li>
					</ol>
				</div>
			</div>
		</div>
	</div>
</div>

<script>
// Self-contained accordion toggling - see default.php's docblock/script
// for why this doesn't rely on Bootstrap's JS bundle.
(function () {
	var root = document.getElementById('<?php echo $acc; ?>');
	if (!root) {
		return;
	}

	root.querySelectorAll('.accordion-button').forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			var targetId = btn.getAttribute('data-bs-target').replace('#', '');
			var target = document.getElementById(targetId);
			if (!target) {
				return;
			}

			var isOpen = target.classList.contains('show');

			root.querySelectorAll('.accordion-collapse').forEach(function (panel) {
				panel.classList.remove('show');
			});
			root.querySelectorAll('.accordion-button').forEach(function (b) {
				b.classList.add('collapsed');
			});

			if (!isOpen) {
				target.classList.add('show');
				btn.classList.remove('collapsed');
			}
		});
	});
})();
</script>
