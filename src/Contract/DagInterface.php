<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace Serendipity\Job\Contract;

use Serendipity\Job\Kernel\Concurrent\ConcurrentMySQLPattern;

interface DagInterface
{
    /**
     * Get dag Token
     *
     * @return int|string
     */
    public function getIdentity(): int|string;

    /**
     * Get dag action time
     */
    public function getTimeout(): int;

    public function run(array $results): mixed;

    public function runConcurrentMySQLPattern(ConcurrentMySQLPattern $pattern): mixed;

    public function isNext(): bool;
}
