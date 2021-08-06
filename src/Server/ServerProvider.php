<?php
/**
 * This file is part of Serendipity Job
 * @license  https://github.com/serendipitySwow/Serendipity-job/blob/main/LICENSE
 */

declare(strict_types=1);

namespace Serendipity\Job\Server;

use Carbon\Carbon;
use FastRoute\Dispatcher;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Hyperf\Engine\Channel;
use Hyperf\Utils\Str;
use PDO;
use Psr\Http\Message\RequestInterface;
use Serendipity\Job\Console\ManageJobCommand;
use Serendipity\Job\Constant\Statistical;
use Serendipity\Job\Constant\Task;
use Serendipity\Job\Contract\ConfigInterface;
use Serendipity\Job\Contract\LoggerInterface;
use Serendipity\Job\Contract\StdoutLoggerInterface;
use Serendipity\Job\Db\Command;
use Serendipity\Job\Db\DB;
use Serendipity\Job\Kernel\Http\Response;
use Serendipity\Job\Kernel\Provider\AbstractProvider;
use Serendipity\Job\Kernel\Router\RouteCollector;
use Serendipity\Job\Kernel\Signature;
use Serendipity\Job\Kernel\Swow\ServerFactory;
use Serendipity\Job\Logger\LoggerFactory;
use Serendipity\Job\Middleware\AuthMiddleware;
use Serendipity\Job\Redis\Lua\Hash\Incr;
use Serendipity\Job\Serializer\SymfonySerializer;
use Serendipity\Job\Util\Arr;
use Serendipity\Job\Util\Context;
use SerendipitySwow\Nsq\Nsq;
use Swow\Coroutine;
use Swow\Coroutine\Exception as CoroutineException;
use Swow\Http\Exception as HttpException;
use Swow\Http\Server;
use Swow\Http\Server\Request as SwowRequest;
use Swow\Http\Status;
use Swow\Socket\Exception as SocketException;
use Throwable;
use function FastRoute\simpleDispatcher;
use function Serendipity\Job\Kernel\serendipity_format_throwable;
use const Swow\Errno\EMFILE;
use const Swow\Errno\ENFILE;
use const Swow\Errno\ENOMEM;

/**
 * Class ServerProvider
 */
class ServerProvider extends AbstractProvider
{
    protected StdoutLoggerInterface $stdoutLogger;

    protected LoggerInterface $logger;

    protected Dispatcher $fastRouteDispatcher;

    public function bootApp(): void
    {
        /**
         * @var Server $server
         */
        $server = $this->container()
            ->make(ServerFactory::class)
            ->start();
        $this->stdoutLogger = $this->container()
            ->get(StdoutLoggerInterface::class);
        $this->logger = $this->container()
            ->get(LoggerFactory::class)
            ->get();
        $this->stdoutLogger->debug('Serendipity-Job Start Successfully#');
        $this->makeFastRoute();
        while (true) {
            try {
                $session = $server->acceptSession();
                Coroutine::run(function () use ($session) {
                    try {
                        while (true) {
                            if (!$session->isEstablished()) {
                                break;
                            }
                            $time = microtime(true);
                            $request = null;
                            try {
                                $request = $session->recvHttpRequest();
                                $response = $this->dispatcher($request);
                                $session->sendHttpResponse($response);
                            } catch (Throwable $exception) {
                                if ($exception instanceof HttpException) {
                                    $session->error($exception->getCode(), $exception->getMessage());
                                }
                                throw $exception;
                            } finally {
                                $logger = $this->container()
                                    ->get(LoggerFactory::class)
                                    ->get('request');
                                // 日志
                                $time = microtime(true) - $time;
                                $debug = 'URI: ' . $request->getUri()
                                    ->getPath() . PHP_EOL;
                                $debug .= 'TIME: ' . $time . PHP_EOL;
                                if ($customData = $this->getCustomData()) {
                                    $debug .= 'DATA: ' . $customData . PHP_EOL;
                                }
                                $debug .= 'REQUEST: ' . $this->getRequestString($request) . PHP_EOL;
                                if (isset($response)) {
                                    $debug .= 'RESPONSE: ' . $this->getResponseString($response) . PHP_EOL;
                                }
                                if (isset($exception) && $exception instanceof Throwable) {
                                    $debug .= 'EXCEPTION: ' . $exception->getMessage() . PHP_EOL;
                                }

                                if ($time > 1) {
                                    $logger->error($debug);
                                } else {
                                    $logger->info($debug);
                                }
                            }
                            if (!$request->getKeepAlive()) {
                                break;
                            }
                        }
                    } catch (Throwable $throwable) {
                        throw $throwable;
                        // you can log error here
                    } finally {
                        ## close session
                        $session->close();
                    }
                });
            } catch (SocketException | CoroutineException $exception) {
                if (in_array($exception->getCode(), [EMFILE, ENFILE, ENOMEM], true)) {
                    sleep(1);
                } else {
                    break;
                }
            }
        }
    }

    protected function makeFastRoute(): void
    {
        $this->fastRouteDispatcher = simpleDispatcher(function (RouteCollector $router) {
            /*
             * 刷新应用签名
             */
            $router->post('/application/refresh-signature', function (): Response {
                /**
                 * @var SwowRequest $request
                 */
                $request = Context::get(RequestInterface::class);
                $params = json_decode($request->getBody()
                    ->getContents(), true, 512, JSON_THROW_ON_ERROR);
                $response = new Response();
                if (!$application = DB::fetch(sprintf(
                    "select * from application where app_key = '%s' and secret_key = '%s'",
                    $params['app_key'],
                    $params['secret_key']
                ))) {
                    return $response->json([
                        'code' => 1,
                        'msg' => 'Unknown Application Key#',
                        'data' => [],
                    ]);
                }
                /**
                 * @var Signature $signature
                 */
                $signature = make(Signature::class, [
                    'options' => [
                        'signatureSecret' => $params['secret_key'],
                        'signatureApiKey' => $params['app_key'],
                    ],
                ]);
                $timestamps = (string) time();
                $nonce = $signature->generateNonce();
                $payload = md5(Arr::get($application, 'app_name'));
                $clientSignature = $signature->generateSignature(
                    $timestamps,
                    $nonce,
                    $payload,
                    $params['secret_key']
                );

                return $response->json([
                    'code' => 0,
                    'msg' => 'Ok!',
                    'data' => [
                        'nonce' => $nonce, 'timestamps' => $timestamps,
                        'signature' => $clientSignature,
                        'appKey' => Arr::get($application, 'app_key'),
                        'payload' => $payload,
                        'secretKey' => Arr::get($application, 'secret_key'),
                    ],
                ]);
            });
            /*
             * 创建应用
             */
            $router->post('/application/create', function (): Response {
                $appKey = Str::random();
                $secretKey = Str::random(32);
                /**
                 * @var SwowRequest $request
                 */
                $request = Context::get(RequestInterface::class);
                $params = json_decode($request->getBody()
                    ->getContents(), true, 512, JSON_THROW_ON_ERROR);
                $data = [
                    'status' => 1,
                    'app_key' => $appKey,
                    'app_name' => Arr::get($params, 'appName'),
                    'secret_key' => $secretKey,
                    'step' => (int) Arr::get($params, 'step', 0),
                    'retry_total' => (int) Arr::get($params, 'retryTotal', 5),
                    'link_url' => Arr::get($params, 'linkUrl'),
                    'remark' => Arr::get($params, 'remark'),
                    'created_at' => Carbon::now()
                        ->toDateTimeString(),
                ];
                /**
                 * @var Command $command
                 */
                $command = make(Command::class);
                $command->insert('application', $data);
                $id = DB::run(function (PDO $PDO) use ($command): int {
                    $statement = $PDO->prepare($command->getSql());

                    $this->bindValues($statement, $command->getParams());

                    $statement->execute();

                    return (int) $PDO->lastInsertId();
                });

                /**
                 * @var Signature $signature
                 */
                $signature = make(Signature::class, [
                    'options' => [
                        'signatureSecret' => $secretKey,
                        'signatureApiKey' => $appKey,
                    ],
                ]);
                $timestamps = (string) time();
                $nonce = $signature->generateNonce();
                $payload = md5(Arr::get($params, 'appName'));
                $clientSignature = $signature->generateSignature(
                    $timestamps,
                    $nonce,
                    $payload,
                    $secretKey
                );
                $response = new Response();

                return $response->json($id ? [
                    'code' => 0,
                    'msg' => 'Ok!',
                    'data' => [
                        'nonce' => $nonce, 'timestamps' => $timestamps,
                        'signature' => $clientSignature,
                        'appKey' => $appKey,
                        'payload' => $payload,
                        'secretKey' => $secretKey,
                    ],
                ] : [
                    'code' => 1,
                    'msg' => '创建应用失败!',
                    'data' => [],
                ]);
            });
            $router->addMiddleware(AuthMiddleware::class, function (RouteCollector $router) {
                $router->post('/nsq/publish', function (): Response {
                    $response = new Response();
                    /**
                     * @var SwowRequest $request
                     */
                    $request = Context::get(RequestInterface::class);
                    $params = json_decode($request->getBody()
                        ->getContents(), true, 512, JSON_THROW_ON_ERROR);
                    $config = $this->container()
                        ->get(ConfigInterface::class)
                        ->get(sprintf('nsq.%s', 'default'));
                    /**
                     * @var Nsq $nsq
                     */
                    $nsq = make(Nsq::class, [$this->container(), $config]);
                    $serializer = $this->container()
                        ->get(SymfonySerializer::class);
                    $ret = DB::fetch('select * from task where id = ? limit 1;', [$params['task_id']]);
                    if (!$ret) {
                        $response->json([
                            'code' => 1,
                            'msg' => sprintf('Unknown Task [%s]#', $params['task_id']),
                            'data' => [],
                        ]);

                        return $response;
                    }
                    $content = json_decode($ret['content'], true, 512, JSON_THROW_ON_ERROR);
                    $serializerObject = make($content['class'], [
                        'identity' => $ret['id'],
                        'timeout' => $ret['timeout'],
                        'step' => $ret['step'],
                        'name' => $ret['name'],
                        'retryTimes' => $ret['retry_times'],
                    ]);
                    $json = $serializer->serialize($serializerObject);
                    $json = json_encode(array_merge([
                        'body' => json_decode(
                            $json,
                            true,
                            512,
                            JSON_THROW_ON_ERROR
                        ),
                    ], ['class' => $serializerObject::class]), JSON_THROW_ON_ERROR);
                    $delay = strtotime($ret['runtime']) - time();
                    if ($delay > 0) {
                        /**
                         * 加入延迟任务统计
                         *
                         * @var Incr $incr
                         */
                        $incr = make(Incr::class);
                        $incr->eval([Statistical::TASK_DELAY, 24 * 60 * 60]);
                    }
                    $bool = $nsq->publish(ManageJobCommand::TOPIC_PREFIX . 'task', $json, $delay > 0 ? $delay : 0.0);

                    return $response->json($bool ? [
                        'code' => 0,
                        'msg' => 'Ok!',
                        'data' => [],
                    ] : [
                        'code' => 1,
                        'msg' => '推送nsq失败!',
                        'data' => [],
                    ]);
                });
                /*
                 * 投递dag任务
                 */
                $router->post('/task/dag', function (): Response {
                    $response = new Response();
                    /**
                     * @var SwowRequest $request
                     */
                    $request = Context::get(RequestInterface::class);
                    $params = json_decode($request->getBody()
                        ->getContents(), true, 512, JSON_THROW_ON_ERROR);
                    if (!DB::fetch('select * from workflow where id = ? and status = ?  limit 1;', [Task::TASK_TODO, $params['task_id']])) {
                        $response->json([
                            'code' => 1,
                            'msg' => sprintf('Unknown Workflow [%s] Or Workflow Is Finished#', $params['task_id']),
                            'data' => [],
                        ]);

                        return $response;
                    }
                    $config = $this->container()
                        ->get(ConfigInterface::class)
                        ->get(sprintf('nsq.%s', 'default'));
                    /**
                     * @var Nsq $nsq
                     */
                    $nsq = make(Nsq::class, [$this->container(), $config]);
                    $bool = $nsq->publish(
                        ManageJobCommand::TOPIC_PREFIX . 'dag',
                        json_encode([$params['id']], JSON_THROW_ON_ERROR)
                    );

                    $json = $bool ? [
                        'code' => 0,
                        'msg' => 'ok!',
                        'data' => ['workflowId' => (int) $params['id']],
                    ] : [
                        'code' => 1,
                        'msg' => 'Workflow Published Nsq Failed!',
                        'data' => [],
                    ];

                    return $response->json($json);
                });
                /*
                 * 创建任务
                 * task
                 */
                $router->post('/task/create', function (): Response {
                    $response = new Response();
                    /**
                     * @var SwowRequest $request
                     */
                    $request = Context::get(RequestInterface::class);
                    $params = json_decode($request->getBody()
                        ->getContents(), true, 512, JSON_THROW_ON_ERROR);
                    $appKey = $request->getHeaderLine('app_key');
                    $application = $request->getHeader('application');
                    $taskNo = Arr::get($params, 'taskNo');
                    $content = Arr::get($params, 'content');
                    $timeout = Arr::get($params, 'timeout');
                    $name = Arr::get($params, 'name');

                    $runtime = Arr::get($params, 'runtime');
                    $runtime = $runtime ? Carbon::parse($runtime)
                        ->toDateTimeString() : Carbon::now()
                        ->toDateTimeString();
                    if (current(DB::fetch(sprintf(
                        "select count(*) from task where app_key = '%s' and task_no = '%s'",
                        $appKey,
                        $taskNo
                    ))) > 0) {
                        $json = [
                            'code' => 1,
                            'msg' => '请勿重复提交!',
                            'data' => [],
                        ];
                    } else {
                        $appKey = Arr::get($application, 'app_key');
                        /*
                        $running = Carbon::parse($runtime)
                            ->lte(Carbon::now()
                            ->toDateTimeString()) ? Task::TASK_ING : Task::TASK_TODO;
                        */
                        $data = [
                            'app_key' => $appKey,
                            'task_no' => $taskNo,
                            'status' => Task::TASK_TODO,
                            'step' => Arr::get($application, 'step'),
                            'runtime' => $runtime,
                            'content' => is_array($content) ? json_encode($content, JSON_THROW_ON_ERROR) : $content,
                            'timeout' => $timeout,
                            // $content  =  { "class": "\\Job\\SimpleJob\\","_params":{"startDate":"xx","endDate":"xxx"}},
                            'created_at' => Carbon::now()
                                ->toDateTimeString(),
                            'name' => $name,
                        ];
                        /**
                         * @var Command $command
                         */
                        $command = make(Command::class);
                        $command->insert('task', $data);
                        $id = DB::run(function (PDO $PDO) use ($command): int {
                            $statement = $PDO->prepare($command->getSql());

                            $this->bindValues($statement, $command->getParams());

                            $statement->execute();

                            return (int) $PDO->lastInsertId();
                        });
                        $delay = strtotime($runtime) - time();
                        $config = $this->container()
                            ->get(ConfigInterface::class)
                            ->get(sprintf('nsq.%s', 'default'));
                        /**
                         * @var Nsq $nsq
                         */
                        $nsq = make(Nsq::class, [$this->container(), $config]);
                        $serializer = $this->container()
                            ->get(SymfonySerializer::class);

                        $content = json_decode(Arr::get($data, 'content'), true, 512, JSON_THROW_ON_ERROR);
                        $serializerObject = make($content['class'], [
                            'identity' => $id,
                            'timeout' => Arr::get($data, 'timeout'),
                            'step' => Arr::get($data, 'step'),
                            'name' => Arr::get($data, 'name'),
                            'retryTimes' => 1,
                        ]);
                        $json = $serializer->serialize($serializerObject);
                        $json = json_encode(array_merge([
                            'body' => json_decode(
                                $json,
                                true,
                                512,
                                JSON_THROW_ON_ERROR
                            ),
                        ], ['class' => $serializerObject::class]), JSON_THROW_ON_ERROR);
                        $bool = $nsq->publish(ManageJobCommand::TOPIC_PREFIX . 'task', $json, $delay);
                        if ($delay > 0) {
                            /**
                             * 加入延迟任务统计
                             *
                             * @var Incr $incr
                             */
                            $incr = make(Incr::class);
                            $incr->eval([Statistical::TASK_DELAY, 24 * 60 * 60]);
                        }
                        $json = $bool ? [
                            'code' => 0,
                            'msg' => 'ok!',
                            'data' => ['taskId' => $id],
                        ] : [
                            'code' => 1,
                            'msg' => 'Unknown!',
                            'data' => [],
                        ];
                    }

                    return $response->json($json);
                });
                /*
                 * 查看任务详情
                 */
                $router->get('/task/detail', function (): Response {
                    /**
                     * @var SwowRequest $request
                     */
                    $request = Context::get(RequestInterface::class);
                    $swowResponse = new Response();
                    $params = $request->getQueryParams();
                    $client = new Client();
                    $config = $this->container()
                        ->get(ConfigInterface::class)
                        ->get('task_server');
                    $response = $client->get(
                        sprintf('%s:%s/%s', $config['host'], $config['port'], 'detail'),
                        [
                            'query' => ['coroutine_id' => $params['coroutine_id'] ?? 0],
                        ]
                    );

                    return $swowResponse->json(json_decode($response->getBody()
                        ->getContents(), true, 512, JSON_THROW_ON_ERROR));
                });
                /*
                 * 取消任务
                 */
                $router->post('/task/cancel', function () {
                    /**
                     * @var SwowRequest $request
                     */
                    $request = Context::get(RequestInterface::class);
                    $swowResponse = new Response();
                    $params = json_decode($request->getBody()
                        ->getContents(), true, 512, JSON_THROW_ON_ERROR);
                    $client = new Client();
                    $config = $this->container()
                        ->get(ConfigInterface::class)
                        ->get('task_server');
                    $response = $client->post(
                        sprintf('%s:%s/%s', $config['host'], $config['port'], 'cancel'),
                        [
                            RequestOptions::JSON => [
                                'coroutine_id' => $params['coroutine_id'],
                                'id' => $params['id'],
                            ],
                        ]
                    );

                    return $swowResponse->json(json_decode($response->getBody()
                        ->getContents(), true, 512, JSON_THROW_ON_ERROR));
                });
            });
        }, [
            'routeCollector' => RouteCollector::class,
        ]);
    }

    protected function dispatcher(SwowRequest $request): Response
    {
        $channel = new Channel();
        Coroutine::run(function () use ($request, $channel) {
            \Swow\defer(function () {
                Context::destroy(RequestInterface::class);
            });
            Context::set(RequestInterface::class, $request);
            $uri = $request->getPath();
            $method = $request->getMethod();
            if (false !== $pos = strpos($uri, '?')) {
                $uri = substr($uri, 0, $pos);
            }
            $uri = rawurldecode($uri);
            $routeInfo = $this->fastRouteDispatcher->dispatch($method, $uri);
            $response = null;
            switch ($routeInfo[0]) {
                case Dispatcher::NOT_FOUND:
                    $response = new Response();
                    $response->text(file_get_contents(BASE_PATH . '/storage/404.php'));
                    break;
                case Dispatcher::METHOD_NOT_ALLOWED:
                    //$allowedMethods = $routeInfo[1];
                    $response = new Response();
                    $response->error(Status::NOT_ALLOWED, 'Method Not Allowed');
                    break;
                case Dispatcher::FOUND: // 找到对应的方法
                    [ , $handler, $vars ] = $routeInfo;
                    if (is_array($handler) && $handler['middlewares']) {
                        //middleware
                        /**
                         * @var AuthMiddleware $middleware
                         */
                        $middleware = $this->container()
                            ->get($handler['middlewares'][0]);
                        try {
                            $check = $middleware->process(Context::get(RequestInterface::class));
                            if (!$check) {
                                $response = new Response();
                                $response->error(Status::UNAUTHORIZED, 'UNAUTHORIZED');
                                break;
                            }
                            $response = call($handler[0], $vars);
                            break;
                        } catch (Throwable $exception) {
                            $this->logger->error(serendipity_format_throwable($exception));
                            $response = new Response();
                            $response->error(Status::INTERNAL_SERVER_ERROR);
                            break;
                        }
                    }
                    $response = call($handler[0], $vars);
                    break;
            }
            $channel->push($response);
        });

        return $channel->pop();
    }

    protected function getCustomData(): string
    {
        return '';
    }

    protected function getResponseString(Response $response): string
    {
        return (string) $response->getBody();
    }

    protected function getRequestString(SwowRequest $request): string
    {
        $data = array_merge(
            $request->getQueryParams(),
            json_decode(
                $request->getBodyAsString() !== '' ? $request->getBodyAsString() : '{}',
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        );

        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
