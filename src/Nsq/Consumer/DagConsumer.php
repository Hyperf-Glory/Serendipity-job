<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipity-swow/serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace Serendipity\Job\Nsq\Consumer;

use Hyperf\Utils\Coroutine as HyperfCo;
use Serendipity\Job\Constant\Statistical;
use Serendipity\Job\Constant\Task;
use Serendipity\Job\Contract\DagInterface;
use Serendipity\Job\Contract\EventDispatcherInterface;
use Serendipity\Job\Db\DB;
use Serendipity\Job\Event\UpdateWorkflowEvent;
use Serendipity\Job\Kernel\Dag\Dag;
use Serendipity\Job\Kernel\Dag\Exception\InvalidArgumentException;
use Serendipity\Job\Kernel\Dag\Vertex;
use SerendipitySwow\Nsq\Message;
use SerendipitySwow\Nsq\Result;
use SwowCloud\Redis\Lua\Hash\Incr;
use Throwable;
use function Serendipity\Job\Kernel\serendipity_format_throwable;
use function Serendipity\Job\Kernel\serendipity_json_decode;

class DagConsumer extends AbstractConsumer
{
    /**
     * @var array<Vertex>
     */
    protected array $vertexes = [];

    public function consume(Message $message): ?string
    {
        [ $id ] = serendipity_json_decode($message->getBody());
        $dag = new Dag();
        $tasks = DB::query('select `task_id` from vertex_edge where workflow_id = ?;', [$id]);
        if (empty($tasks)) {
            return Result::DROP;
        }

        HyperfCo::create(function () use ($id, $tasks, $dag) {
            /**
             * @var Dag $dag
             */
            $ids = implode("','", array_column($tasks, 'task_id'));
            $task = DB::query("select * from task where id in ('{$ids}');");

            foreach ($task as $value) {
                $object = (object) $value;
                $this->vertexes[$object->task_no] = Vertex::make(static function ($results) use ($object) {
                    /*
                   $object->content
                     {
                        "class": "Serendipity\\Job\\Dag\\Task\\Task1",
                        "params": {
                            "startDate": "2021-06-09",
                            "endDate": "2021-06-19"
                        }
                    }
                    */
                    $content = serendipity_json_decode($object->content);
                    $class = make($content['class'], $content['params']);
                    // 暂不考虑支持协程单例mysql模式.
                    if (!$class instanceof DagInterface) {
                        throw new InvalidArgumentException(sprintf(
                            'unknown class "%s,must be implements DagInterface#.',
                            $class ?? $object->content
                        ));
                    }

                    echo $object->task_no . "\n";

                    return $class->run($results);
                }, $object->timeout, $object->task_no);
                $dag->addVertex($this->vertexes[$object->task_no]);
            }
            $source = <<<'SQL'
select t.task_no,vertex_edge.task_id,vertex_edge.pid from vertex_edge left join task t on vertex_edge.task_id = t.id
where workflow_id = ?
SQL;
            $source = DB::query($source, [$id]);
            $this->buildInitialDag($dag, $source);
            $incr = make(Incr::class);
            try {
                $this->logger->info('Workflow Start #....', ['workflow_id' => $id]);
                $dag->run();
                $this->logger->info('Workflow End #....', ['workflow_id' => $id]);
                $this->container->get(EventDispatcherInterface::class)
                    ->dispatch(
                        new UpdateWorkflowEvent($id, Task::TASK_SUCCESS),
                        UpdateWorkflowEvent::UPDATE_WORKFLOW
                    );
                //加入成功执行统计
                $incr->eval([Statistical::DAG_SUCCESS, $this->config->get('consumer.task_redis_cache_time')]);
            } catch (Throwable $throwable) {
                $this->dingTalk->text(serendipity_format_throwable($throwable));
                $this->logger->error(sprintf('Workflow Error[%s]#', $throwable->getMessage()));
                $incr->eval([Statistical::DAG_FAILURE, $this->config->get('consumer.task_redis_cache_time')]);
            }
        });

        return Result::ACK;
    }

    private function buildInitialDag(Dag $dag, array $source, int $pid = 0): array
    {
        $tree = [];
        foreach ($source as $v) {
            if ($v['pid'] === $pid) {
                $v['children'] = $this->buildInitialDag($dag, $source, $v['task_id']);
                if (empty($v['children'])) {
                    unset($v['children']);
                } else {
                    foreach ($v['children'] as $child) {
                        $dag->addEdge($this->vertexes[$v['task_no']], $this->vertexes[$child['task_no']]);
                    }
                }
                $tree[] = $v;
            }
        }

        return $tree;
    }
}
