<?php

/**
 * @package     FeedGator
 * @copyright   Copyright (C) 2005-2010 Stephen Simmons, Jozef Kapusciarz & Matt Faulds
 * @license     GNU/GPL
 */

namespace Trafalgardesign\Component\Feedgator\Administrator\Extension;

use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Psr\Container\ContainerInterface;

\defined('_JEXEC') or die;

/**
 * Component class for com_feedgator (backend).
 */
final class FeedgatorComponent extends MVCComponent implements BootableExtensionInterface
{
    /**
     * Booting the extension. Reserved for future startup logic (asset
     * registration, etc.) - currently a no-op.
     *
     * @param   ContainerInterface  $container  The container to boot
     */
    public function boot(ContainerInterface $container): void
    {
    }
}
