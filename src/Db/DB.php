<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/Hyperf-Glory/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @see     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Serendipity\Job\Db;

use Closure;
use Serendipity\Job\Db\Pool\PoolFactory;
use Serendipity\Job\Util\ApplicationContext;
use Serendipity\Job\Util\Context;
use Throwable;

/**
 * @method beginTransaction()
 * @method commit()
 * @method rollback()
 * @method insert(string $query, array $bindings = [])
 * @method execute(string $query, array $bindings = [])
 * @method query(string $query, array $bindings = [])
 * @method fetch(string $query, array $bindings = [])
 * @method run(Closure $closure)
 */
class DB
{
    protected PoolFactory $factory;

    protected string $poolName;

    public function __construct(PoolFactory $factory, string $poolName = 'default')
    {
        $this->factory = $factory;
        $this->poolName = $poolName;
    }

    public function __call($name, $arguments)
    {
        $hasContextConnection = Context::has($this->getContextKey());
        $connection = $this->getConnection($hasContextConnection);

        try {
            $connection = $connection->getConnection();
            $result = $connection->{$name}(...$arguments);
        } catch (Throwable $exception) {
            $result = $connection->retry($exception, $name, $arguments);
        } finally {
            if (!$hasContextConnection) {
                if ($this->shouldUseSameConnection($name)) {
                    // Should storage the connection to coroutine context, then use defer() to release the connection.
                    Context::set($contextKey = $this->getContextKey(), $connection);
                    defer(static function () use ($connection, $contextKey) {
                        Context::set($contextKey, null);
                        $connection->release();
                    });
                } else {
                    // Release the connection after command executed.
                    $connection->release();
                }
            }
        }

        return $result;
    }

    public static function __callStatic($name, $arguments)
    {
        $container = ApplicationContext::getContainer();
        $db = $container->get(static::class);

        return $db->{$name}(...$arguments);
    }

    /**
     * Make a new connection with the pool name.
     */
    public static function connection(string $poolName): self
    {
        return make(static::class, [
            'poolName' => $poolName,
        ]);
    }

    /**
     * Define the commands that needs same connection to execute.
     * When these commands executed, the connection will storage to coroutine context.
     */
    protected function shouldUseSameConnection(string $methodName): bool
    {
        return in_array($methodName, [
            'beginTransaction',
            'commit',
            'rollBack',
        ]);
    }

    /**
     * Get a connection from coroutine context, or from mysql connectio pool.
     */
    protected function getConnection(bool $hasContextConnection): AbstractConnection
    {
        $connection = null;
        if ($hasContextConnection) {
            $connection = Context::get($this->getContextKey());
        }
        if (!$connection instanceof AbstractConnection) {
            $pool = $this->factory->getPool($this->poolName);
            $connection = $pool->get();
        }

        return $connection;
    }

    /**
     * The key to identify the connection object in coroutine context.
     */
    private function getContextKey(): string
    {
        return sprintf('db.connection.%s', $this->poolName);
    }
}
