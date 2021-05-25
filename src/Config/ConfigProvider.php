<?php
declare(strict_types = 1);
namespace Serendipity\Job\Config;

use Serendipity\Job\Contract\ConfigInterface;
use Serendipity\Job\Kernel\Provider\AbstractProvider;

class  ConfigProvider extends AbstractProvider
{
    protected static string $interface = ConfigInterface::class;

    public function bootApp() : void
    {
        $call = $this->container()->call(ConfigFactory::class);
        $this->container()->set(ConfigInterface::class, $call);
    }
}
