<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from views/feedgator/tmpl/default_tools.php. The original's
 * MooTools-based show/hide + AJAX "ignore duplicate" interactions are
 * replaced here with a small vanilla-JS snippet (MooTools was removed
 * from Joomla core in Joomla 4) rather than left non-functional.
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

HTMLHelper::_('bootstrap.tooltip');

$token = Session::getFormToken();
$base  = Uri::base();
?>
<br />
<ul>
	<li><a href="index.php?option=com_feedgator&task=syncImports">Synchronise Import Database</a></li>
	<li style="list-style:none;">&nbsp;</li>
	<li><a id="duplink" href="#">View Duplicates</a></li>
</ul>
<table id="duptable" class="table" style="display:none;">
	<thead>
		<tr>
			<td class="title" width="10%"><?php echo Text::_('FG_NUM_OF_DUPLICATES'); ?></td>
			<td class="title" width="40%"><?php echo Text::_('JGLOBAL_TITLE'); ?></td>
			<td class="title" width="30%"><?php echo Text::_('FG_FEED_GATOR'); ?></td>
			<td class="title" width="20%"><?php echo Text::_('FG_CONTENT_TYPE'); ?></td>
		</tr>
	</thead>
	<tbody>
		<?php if (!empty($this->dups)) : ?>
			<?php $i = 0; foreach ($this->dups as $dup) : ?>
				<tr>
					<td><span class="dupdrill" data-target="tr<?php echo $i; ?>"> <?php echo $dup->num; ?></span></td>
					<td><?php echo $dup->title; ?></td>
					<td><?php echo $dup->feed_title; ?></td>
					<td><?php echo $dup->content_type; ?></td>
				</tr>
				<tr id="tr<?php echo $i; ?>" style="display:none;">
					<td colspan="4">
						<table id="drilldowntable" class="table">
							<thead>
								<tr>
									<td class="title" width="10%"><?php echo Text::_('FG_DUPLICATE'); ?></td>
									<td class="title" width="40%"><?php echo Text::_('JGLOBAL_TITLE'); ?></td>
									<td class="title" width="10%"><?php echo Text::_('FG_CONTENT_ID'); ?></td>
									<td class="title" width="10%"><?php echo Text::_('FG_SECTION_ID'); ?></td>
									<td class="title" width="10%"><?php echo Text::_('FG_CATEGORY_ID'); ?></td>
									<td class="title" width="20%"><?php echo Text::_('FG_IGNORE'); ?></td>
								</tr>
							</thead>
							<tbody>
								<?php $ds = $dup->dups; for ($j = 0; $j < \count($ds); $j++) : ?>
									<tr id="<?php echo $i . $j . '_' . $ds[$j]->id; ?>">
										<td><?php echo $j; ?></td>
										<td><a href="<?php echo $ds[$j]->content_link; ?>"><?php echo $ds[$j]->title; ?></a></td>
										<td><?php echo $ds[$j]->id; ?></td>
										<td><?php echo $ds[$j]->sectionid; ?></td>
										<td><?php echo $ds[$j]->catid; ?></td>
										<td><a class="ignoredup" href="#" data-type="<?php echo $dup->content_type; ?>" data-rel="<?php echo $i . $j . '_' . $ds[$j]->id; ?>">Ignore</a></td>
									</tr>
								<?php endfor; ?>
							</tbody>
						</table>
					</td>
				</tr>
			<?php $i++; endforeach; ?>
		<?php else : ?>
			<tr>
				<td colspan="4"><?php echo Text::_('FG_NO_DUPLICATES_FOUND'); ?></td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>
<script>
document.addEventListener('DOMContentLoaded', function () {
	var duplink = document.getElementById('duplink');
	if (duplink) {
		duplink.addEventListener('click', function (e) {
			e.preventDefault();
			var t = document.getElementById('duptable');
			t.style.display = (t.style.display === 'none' || !t.style.display) ? '' : 'none';
		});
	}

	document.querySelectorAll('.dupdrill').forEach(function (el) {
		el.addEventListener('click', function () {
			var target = document.getElementById(el.dataset.target);
			if (target) {
				target.style.display = (target.style.display === 'none' || !target.style.display) ? '' : 'none';
			}
		});
	});

	document.querySelectorAll('.ignoredup').forEach(function (el) {
		el.addEventListener('click', function (e) {
			e.preventDefault();
			var url = '<?php echo $base; ?>index.php?option=com_feedgator&task=ignoreDuplicate&<?php echo $token; ?>=1'
				+ '&type=' + encodeURIComponent(el.dataset.type)
				+ '&rel=' + encodeURIComponent(el.dataset.rel)
				+ '&format=raw';
			fetch(url, { credentials: 'same-origin' }).then(function () {
				var row = document.getElementById(el.dataset.rel);
				if (row) {
					row.style.display = 'none';
				}
			});
		});
	});
});
</script>
