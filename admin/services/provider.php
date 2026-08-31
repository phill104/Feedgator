<?php

/**
 * @package     FeedGator
 * @copyright   Copyright (C) 2005-2010 Stephen Simmons, Jozef Kapusciarz & Matt Faulds
 * @license     GNU/GPL
 */

namespace Joomla\Component\Feedgator\Administrator;

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Trafalgardesign\Component\Feedgator\Administrator\Extension\FeedgatorComponent;

/**
 * The FeedGator service provider (administrator side).
 *
 * No RouterFactory is registered here: FeedGator has no custom front-end
 * routing needs (see site/src's docblocks - the component has no public
 * UI of its own), and MVCComponent doesn't implement the router-service
 * trait by default, so there is no setRouterFactory() to call.
 */
return new class implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     */
    public function register(Container $container)
    {
        $container->registerServiceProvider(new MVCFactory('\\Trafalgardesign\\Component\\Feedgator'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Trafalgardesign\\Component\\Feedgator'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new FeedgatorComponent($container->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));

                return $component;
            }
        );
    }
};
