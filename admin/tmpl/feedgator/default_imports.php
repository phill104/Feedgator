<?php

/**
 * FeedGator - Aggregate RSS newsfeed content into a Joomla! database
 *
 * @package     Joomla.Administrator
 * @subpackage  com_feedgator
 * @copyright   Copyright (C) 2005-2026 Stephen Simmons, Matt Faulds, Trafalgar Design and contributors. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * Converted from views/feedgator/tmpl/default_imports.php. The original's
 * publish/frontpage toggle icons used a MooTools-only
 * `listContentItemTask()` JS helper (removed along with the rest of the
 * dropped inline scripts - see HtmlView's docblock); they're now plain
 * links to the controller's existing publish/unpublish tasks instead,
 * which is a small behavioural simplification (no more "select this row
 * then submit" - it acts immediately).
 *
 * NOTE: HTMLHelper::_('grid.access', ...) no longer exists in modern
 * Joomla (it rendered an access-level icon/dropdown in much older
 * versions) - the access level's name is now selected directly in the
 * driver's getContentItemsQuery() (a LEFT JOIN to #__viewlevels) and
 * just printed as plain text below, matching how core Joomla's own
 * admin list views handle this since the helper was dropped.
 */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

$db     = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
$user   = Factory::getApplication()->getIdentity();
$config = Factory::getConfig();
$now    = Factory::getDate();
$app    = Factory::getApplication();
$ajax   = $app->getInput()->getInt('ajax', 0);
$token  = Session::getFormToken();

$ordering = \in_array($this->lists['order'], ['section_name', 'cat_name', 'ordering'], true);

if (!$ajax) : ?>
<form action="index.php?option=com_feedgator" method="post" name="adminForm">
<?php endif; ?>
	<table>
		<tr>
			<td width="100%"><?php echo Text::_('JSEARCH_FILTER'); ?>:
				<input type="text" name="search" id="search" value="<?php echo htmlspecialchars($this->lists['search']); ?>" class="form-control" onchange="document.adminForm.submit();" title="<?php echo Text::_('FG_FILTER_BY_TITLE_OR_ID'); ?>" />
				<button class="btn" onclick="this.form.submit();"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
				<button class="btn" onclick="document.getElementById('search').value='';this.form.submit();"><?php echo Text::_('JSEARCH_FILTER_CLEAR'); ?></button>
			</td>
			<td>
				<?php if (!$ajax) :
					echo $this->lists['feed'];
					echo $this->lists['sectionid'];
					echo $this->lists['catid'];
					echo $this->lists['authorid'];
					echo $this->lists['state'];
				endif; ?>
			</td>
		</tr>
	</table>

	<table class="table" cellspacing="1">
	<thead>
		<tr>
			<th width="5"><?php echo Text::_('FG_NUM'); ?></th>
			<th width="5"><input type="checkbox" name="checkall-toggle" value="" onclick="Joomla.checkAll(this);" /></th>
			<th class="title"><?php echo HTMLHelper::_('grid.sort', 'JGLOBAL_TITLE', 'title', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
			<th width="1%" nowrap="nowrap"><?php echo HTMLHelper::_('grid.sort', 'JPUBLISHED', 'state', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
			<th width="1%" nowrap="nowrap"><?php echo HTMLHelper::_('grid.sort', 'FG_FRONT_PAGE', 'frontpage', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
			<th width="8%"><?php echo HTMLHelper::_('grid.sort', 'JGRID_HEADING_ORDERING', 'ordering', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); if ($ordering) { echo HTMLHelper::_('grid.order', $this->rows); } ?></th>
			<th width="7%"><?php echo HTMLHelper::_('grid.sort', 'JGRID_HEADING_ACCESS', 'groupname', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
			<th width="8%" class="title" nowrap="nowrap"><?php echo HTMLHelper::_('grid.sort', 'FG_FEED_GATOR', 'feed_title', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
			<th width="8%" class="title" nowrap="nowrap"><?php echo HTMLHelper::_('grid.sort', 'JGLOBAL_SECTION', 'section_name', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
			<th width="8%" class="title" nowrap="nowrap"><?php echo HTMLHelper::_('grid.sort', 'JCATEGORY', 'cat_name', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
			<th width="8%" class="title" nowrap="nowrap"><?php echo HTMLHelper::_('grid.sort', 'JAUTHOR', 'author', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
			<th width="10" align="center"><?php echo HTMLHelper::_('grid.sort', 'JDATE', 'created', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
			<th width="1%" class="title"><?php echo HTMLHelper::_('grid.sort', 'JGLOBAL_NUM_ID', 'id', $this->lists['order_Dir'] ?? '', $this->lists['order'] ?? ''); ?></th>
		</tr>
	</thead>
	<tfoot>
	<tr>
		<td colspan="12">
			<?php echo $this->page->getListFooter(); ?>
		</td>
	</tr>
	</tfoot>
	<tbody>
	<?php
	$k        = 0;
	$nullDate = $db->getNullDate();

	for ($i = 0, $n = \count($this->rows); $i < $n; $i++) {
		$row = $this->rows[$i];

		$row->feed_link = Route::_('index.php?option=com_feedgator&task=edit&cid[]=' . $row->feedid);

		$publish_up   = Factory::getDate($row->publish_up);
		$publish_down = Factory::getDate($row->publish_down);

		$img = '';
		$alt = '';

		if ($now->toUnix() <= $publish_up->toUnix() && $row->state == 1) {
			$alt = Text::_('JPUBLISHED');
		} elseif (($now->toUnix() <= $publish_down->toUnix() || $row->publish_down == $nullDate) && $row->state == 1) {
			$alt = Text::_('JPUBLISHED');
		} elseif ($now->toUnix() > $publish_down->toUnix() && $row->state == 1) {
			$alt = Text::_('JGLOBAL_EXPIRED');
		} elseif ($row->state == 0) {
			$alt = Text::_('JUNPUBLISHED');
		} elseif ($row->state == 2) {
			$alt = Text::_('JARCHIVED');
		} elseif ($row->state == -2) {
			$alt = Text::_('JTRASHED');
		}

		$author = $row->created_by_alias ?: $row->author;

		$access  = htmlspecialchars((string) ($row->access_level ?? $row->access));
		$checked = HTMLHelper::_('grid.checkedout', $row, $i);
		?>
		<tr class="<?php echo 'row' . $k; ?>">
			<td><?php echo $this->page->getRowOffset($i); ?></td>
			<td class="text-center"><?php echo $checked; ?></td>
			<td>
			<?php
				// Table::isCheckedOut() was a static utility method in
				// old Joomla (2.5/3.x) - modern Joomla converted it to a
				// regular instance method, so calling it statically like
				// the original did no longer works. The actual logic is
				// simple enough to inline directly rather than needing a
				// real Table instance just for this one check.
				if (!empty($row->checked_out) && (int) $row->checked_out !== (int) $user->id) {
					echo $row->title;
				} elseif ($row->state == -1) {
					echo htmlspecialchars($row->title, ENT_QUOTES, 'UTF-8') . ' [ ' . Text::_('JARCHIVED') . ' ]';
				} else {
					?>
					<a href="<?php echo $row->content_link; ?>"><?php echo htmlspecialchars($row->title, ENT_QUOTES); ?></a>
					<?php
				}
			?></td>
			<td class="text-center">
				<a href="<?php echo Route::_('index.php?option=com_feedgator&task=' . ($row->state ? 'unpublish' : 'publish') . '&cid[]=' . $row->id . '&' . $token . '=1'); ?>" title="<?php echo $alt; ?>">
					<?php echo $alt; ?>
				</a>
			</td>
			<td class="text-center">
				<a href="<?php echo Route::_('index.php?option=com_feedgator&task=' . ($row->frontpage ? 'front_no' : 'front_yes') . '&cid[]=' . $row->id . '&' . $token . '=1'); ?>" title="<?php echo $row->frontpage ? Text::_('JYES') : Text::_('JNO'); ?>">
					<?php echo $row->frontpage ? Text::_('JYES') : Text::_('JNO'); ?>
				</a>
			</td>
			<td class="order">
				<span><?php echo $this->page->orderUpIcon($i, ($row->catid == ($this->rows[$i - 1]->catid ?? null)), 'orderup', 'JLIB_HTML_MOVE_UP', $ordering); ?></span>
				<span><?php echo $this->page->orderDownIcon($i, $n, ($row->catid == ($this->rows[$i + 1]->catid ?? null)), 'orderdown', 'JLIB_HTML_MOVE_DOWN', $ordering); ?></span>
				<input type="text" name="order[]" size="5" value="<?php echo $row->ordering; ?>" <?php echo $ordering ? '' : 'disabled="disabled"'; ?> class="form-control" style="text-align: center; display:inline-block; width:5em;" />
			</td>
			<td class="text-center"><?php echo $access; ?></td>
			<td><a href="<?php echo $row->feed_link; ?>" title="<?php echo Text::_('FG_EDIT_FEED'); ?>"><?php echo $row->feed_title; ?></a></td>
			<td><?php echo $row->section_name; ?></td>
			<td><?php echo $row->cat_name; ?></td>
			<td><?php echo $author; ?></td>
			<td nowrap="nowrap"><?php echo HTMLHelper::_('date', $row->created, Text::_('DATE_FORMAT_LC4')); ?></td>
			<td><?php echo $row->id; ?></td>
		</tr>
		<?php
		$k = 1 - $k;
	}
	?>
	</tbody>
	</table>

<input type="hidden" name="filter_order" value="<?php echo $this->lists['order']; ?>" />
<input type="hidden" name="filter_order_Dir" value="<?php echo $this->lists['order_Dir']; ?>" />
<input type="hidden" name="boxchecked" value="0" />
<?php if (!$ajax) : ?>
<input type="hidden" name="option" value="com_feedgator" />
<input type="hidden" name="task" value="imports" />
<?php echo HTMLHelper::_('form.token'); ?>
</form>
<?php endif; ?>
