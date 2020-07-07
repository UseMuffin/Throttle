<?php
declare(strict_types=1);

namespace Muffin\Throttle;

use Cake\Core\BasePlugin;

class Plugin extends BasePlugin
{
    /**
     * The name of this plugin
     *
     * @var string
     */
    protected $name = 'Throttle';

    /**
     * Do bootstrapping or not
     *
     * @var bool
     */
    protected $bootstrapEnabled = false;

    /**
     * Load routes or not
     *
     * @var bool
     */
    protected $routesEnabled = false;

    /**
     * Console middleware
     *
     * @var bool
     */
    protected $consoleEnabled = false;
}
