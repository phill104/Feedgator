<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 * Converted from views/feedgator/tmpl/default.php (control panel).
 *
 * Joomla's old `sliders.*` JHtml behaviour (jQuery UI accordion, used
 * for the FeedGator/Latest Imports/Credits panels) was removed when
 * Joomla 4 dropped jQuery UI. Replaced with a plain Bootstrap 5
 * accordion (Joomla 6 ships Bootstrap 5), which needs no extra JS.
 */

use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Trafalgardesign\Component\Feedgator\Administrator\Helper\FeedgatorHelper;

\defined('_JEXEC') or die;

$acc = 'fgcpanelAccordion';
?>
<table class="table">
	<tbody>
		<tr>
			<td valign="top" width="55%">
				<div id="cpanel">
					<?php
					echo FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=feeds'], ['src' => 'components/com_feedgator/images/cpanel/feeds.png', 'alt' => 'Manage Feeds'], Text::_('FG_MAN_FEEDS'));
					echo FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=settings'], ['src' => 'components/com_feedgator/images/cpanel/settings.png', 'alt' => 'Global Settings'], Text::_('FG_SETTINGS'));
					echo FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=plugins'], ['src' => 'components/com_feedgator/images/cpanel/plugins.png', 'alt' => 'Plugins'], Text::_('FG_PLUGINS'));
					echo FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=tools'], ['src' => 'components/com_feedgator/images/cpanel/tools.png', 'alt' => 'Tools'], Text::_('FG_TOOLS'));
					echo FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=imports'], ['src' => 'components/com_feedgator/images/cpanel/history.png', 'alt' => 'Import History'], Text::_('FG_IMPORTS'));
					echo FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=support'], ['src' => 'components/com_feedgator/images/cpanel/support.png', 'alt' => 'Support'], Text::_('FG_SUPPORT'));
					echo FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=about'], ['src' => 'components/com_feedgator/images/cpanel/about.png', 'alt' => 'About FeedGator'], Text::_('FG_ABOUT'));
					echo FeedgatorHelper::renderCpanel();
					echo FeedgatorHelper::renderCpanel();

					$upgradeNeeded = (!empty($this->version_data['dev']['upgrade']) || !empty($this->version_data['stable']['upgrade']));
					echo $upgradeNeeded
						? FeedgatorHelper::renderCpanel(['href' => '#'], ['src' => 'components/com_feedgator/images/cpanel/warning.png', 'alt' => Text::_('FG_UPDATE_NEEDED')], Text::_('FG_UPDATE_NEEDED'))
						: FeedgatorHelper::renderCpanel(['href' => '#'], ['src' => 'components/com_feedgator/images/cpanel/ok.png', 'alt' => Text::_('FG_LATEST_VERSION')], Text::_('FG_LATEST_VERSION'));

					echo $this->jplugin
						? FeedgatorHelper::renderCpanel(['href' => '#'], ['src' => 'components/com_feedgator/images/cpanel/ok.png', 'alt' => Text::_('FG_J_PLGS_OK')], Text::_('FG_J_PLGS_OK'))
						: FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_scheduler&view=tasks'], ['src' => 'components/com_feedgator/images/cpanel/warning.png', 'alt' => Text::_('FG_J_PLGS_NOT_OK')], Text::_('FG_J_PLGS_NOT_OK'));

					echo $this->fgplugins
						? FeedgatorHelper::renderCpanel(['href' => '#'], ['src' => 'components/com_feedgator/images/cpanel/ok.png', 'alt' => Text::_('FG_PLGS_OK')], Text::_('FG_PLGS_OK'))
						: FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=plugins'], ['src' => 'components/com_feedgator/images/cpanel/warning.png', 'alt' => Text::_('FG_PLGS_NOT_OK')], Text::_('FG_PLGS_NOT_OK'));

					echo $this->import_sync
						? FeedgatorHelper::renderCpanel(['href' => '#'], ['src' => 'components/com_feedgator/images/cpanel/ok.png', 'alt' => Text::_('FG_IMPORTS_OK')], Text::_('FG_IMPORTS_OK'))
						: FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=tools'], ['src' => 'components/com_feedgator/images/cpanel/warning.png', 'alt' => Text::_('FG_IMPORTS_NOT_OK')], Text::_('FG_IMPORTS_NOT_OK'));

					echo $this->duplicates
						? FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=tools'], ['src' => 'components/com_feedgator/images/cpanel/warning.png', 'alt' => Text::_('FG_DUPS')], Text::_('FG_DUPS'))
						: FeedgatorHelper::renderCpanel(['href' => '#'], ['src' => 'components/com_feedgator/images/cpanel/ok.png', 'alt' => Text::_('FG_NO_DUPS')], Text::_('FG_NO_DUPS'));

					echo $this->globals
						? FeedgatorHelper::renderCpanel(['href' => '#'], ['src' => 'components/com_feedgator/images/cpanel/ok.png', 'alt' => Text::_('FG_GLOBALS')], Text::_('FG_GLOBALS'))
						: FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=settings'], ['src' => 'components/com_feedgator/images/cpanel/warning.png', 'alt' => Text::_('FG_NO_GLOBALS')], Text::_('FG_NO_GLOBALS'));

					echo $this->defaults
						? FeedgatorHelper::renderCpanel(['href' => '#'], ['src' => 'components/com_feedgator/images/cpanel/ok.png', 'alt' => Text::_('FG_DEFAULTS')], Text::_('FG_DEFAULTS'))
						: FeedgatorHelper::renderCpanel(['href' => 'index.php?option=com_feedgator&task=editdefault'], ['src' => 'components/com_feedgator/images/cpanel/warning.png', 'alt' => Text::_('FG_NO_DEFAULTS')], Text::_('FG_NO_DEFAULTS'));
					?>
				</div>
			</td>
			<td valign="top" width="45%">
				<div class="accordion" id="<?php echo $acc; ?>">
					<div class="accordion-item">
						<h2 class="accordion-header">
							<button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $acc; ?>_panel1">FeedGator</button>
						</h2>
						<div id="<?php echo $acc; ?>_panel1" class="accordion-collapse collapse show" data-bs-parent="#<?php echo $acc; ?>">
							<div class="accordion-body">
								<?php echo Text::_('FG_DESCRIPTION'); ?>
								<?php FeedgatorHelper::renderVersionUpdatePanel($this->version_data); ?>
							</div>
						</div>
					</div>
					<div class="accordion-item">
						<h2 class="accordion-header">
							<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $acc; ?>_panel2">Latest Imports</button>
						</h2>
						<div id="<?php echo $acc; ?>_panel2" class="accordion-collapse collapse" data-bs-parent="#<?php echo $acc; ?>">
							<div class="accordion-body">
								<?php if ($this->latest_imports) : ?>
									<table class="table">
										<thead>
											<tr>
												<td class="title"><?php echo Text::_('JGLOBAL_TITLE'); ?></td>
												<td class="title"><?php echo Text::_('FG_FEED_GATOR'); ?></td>
												<td class="title"><?php echo Text::_('JDATE'); ?></td>
											</tr>
										</thead>
										<tbody>
											<?php foreach ($this->latest_imports as $latest) : ?>
												<tr>
													<td><a href="<?php echo $latest->content_link; ?>"><?php echo $latest->title; ?></a></td>
													<td><a href="<?php echo $latest->feed_link; ?>"><?php echo $latest->feed_title; ?></a></td>
													<td><?php echo HTMLHelper::_('date', $latest->created); ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								<?php else : ?>
									<p><?php echo Text::_('FG_NO_IMPORTS_IN_DATABASE'); ?></p>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<div class="accordion-item">
						<h2 class="accordion-header">
							<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $acc; ?>_panel3">Credits</button>
						</h2>
						<div id="<?php echo $acc; ?>_panel3" class="accordion-collapse collapse" data-bs-parent="#<?php echo $acc; ?>">
							<div class="accordion-body">
								<ul>
									<li>Built on the <a href="https://www.joomla.org/" target="_blank" rel="noopener">Joomla</a> framework</li>
									<li>Feed parsing via a self-contained RSS/Atom parser</li>
									<li><a href="http://lab.arc90.com/experiments/readability/">Readability</a> port by <a href="http://fivefilters.org/content-only/">FiveFilters.org</a> for full text extraction</li>
									<li><a href="http://www.bioinformatics.org/phplabware/internal_utilities/htmLawed/">htmLawed</a> (X)HTML filter, processor, purifier, sanitizer and beautifier for imported text</li>
									<li>Small icons <a href="http://www.famfamfam.com/lab/icons/silk/">Famfamfam Silk Icons</a> by Mark James</li>
									<li>Coding influences from: <a href="http://joomla.vargas.co.cr/">Xmap component by Vargas</a>, Trafalgar Design Learning Management System (unreleased)</li>
								</ul>
								<ul>
									<li>Released under the GNU <a href="http://www.gnu.org/licenses/gpl-2.0.html">GPLv2</a> copyleft license</li>
									<li>Maintained and developed by Matthew Faulds (2010)</li>
									<li>Original author Stephen Simmons (2006)</li>
									<li>Contributing authors: Remco Boom, Stephane Koenig and others</li>
									<li>Ported to Joomla 6 / PHP 8.3</li>
								</ul>
							</div>
						</div>
					</div>
				</div>
			</td>
		</tr>
	</tbody>
</table>

<script>
// Self-contained accordion toggling - doesn't depend on Bootstrap's JS
// bundle auto-initialising data-bs-toggle="collapse" on this page (see
// default_feed.php's docblock for the same reasoning applied to tabs).
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
