<?php
/**
 * This file is part of Swow-Cloud/Job
 * @license  https://github.com/serendipity-swow/serendipity-job/blob/master/LICENSE
 */

declare(strict_types=1);

namespace SwowCloud\Job\Console;

use Carbon\Carbon;
use Exception;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Codec\Json;
use Hyperf\Utils\Coroutine as HyperfCo;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Spatie\Emoji\Emoji;
use Swow\Coroutine as SwowCo;
use Swow\Coroutine\Exception as CoroutineException;
use Swow\Http\Exception as HttpException;
use Swow\Http\Server as HttpServer;
use Swow\Http\Status as HttpStatus;
use Swow\Socket\Exception as SocketException;
use SwowCloud\Contract\StdoutLoggerInterface;
use SwowCloud\Job\Constant\Task;
use SwowCloud\Job\Contract\EventDispatcherInterface;
use SwowCloud\Job\Contract\SerializerInterface;
use SwowCloud\Job\Crontab\CrontabDispatcher;
use SwowCloud\Job\Db\DB;
use SwowCloud\Job\Event\CrontabEvent;
use SwowCloud\Job\Kernel\Consul\RegisterServices;
use SwowCloud\Job\Kernel\Http\Request as SwowCloudRequest;
use SwowCloud\Job\Kernel\Http\Response;
use SwowCloud\Job\Kernel\Provider\KernelProvider;
use SwowCloud\Job\Nsq\Consumer\AbstractConsumer;
use SwowCloud\Job\Nsq\Consumer\JobConsumer;
use SwowCloud\Nsq\Message;
use SwowCloud\Nsq\Nsq;
use SwowCloud\Nsq\Result;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputOption;
use Throwable;
use function SwowCloud\Job\Kernel\serendipity_json_decode;
use const Swow\Errno\EMFILE;
use const Swow\Errno\ENFILE;
use const Swow\Errno\ENOMEM;

/**
 * @command php bin/job job:start --host=127.0.0.1 --port=9764
 */
final class JobCommand extends Command
{
    /**
     * @var string
     */
    public static $defaultName = 'job:start';

    protected const COMMAND_PROVIDER_NAME = 'Job';

    public const TOPIC_SUFFIX = 'job';

    protected ?ConfigInterface $config = null;

    protected ?StdoutLoggerInterface $stdoutLogger = null;

    protected ?SerializerInterface $serializer = null;

    protected ?Nsq $subscriber = null;

    private ContainerInterface $container;

    protected function configure(): void
    {
        $this
            ->setDescription('Start Job,Support view cancel tasks.')
            ->setDefinition([
                new InputOption(
                    'host',
                    'host',
                    InputOption::VALUE_REQUIRED,
                    'Configure HttpServer host',
                    '127.0.0.1'
                ),
                new InputOption(
                    'port',
                    'p',
                    InputOption::VALUE_REQUIRED,
                    'Configure HttpServer port numbers',
                    9764
                ),
                new InputOption(
                    'consul-service-name',
                    'csn',
                    InputOption::VALUE_REQUIRED,
                    'Consul service center service name',
                    'job_service_name'
                ),
            ])
            ->setHelp(
                <<<'EOF'
                    The <info>%command.name%</info> command consumes tasks

                        <info>php %command.full_name%</info>
                        
                    Use the --host Configure HttpServer host:
                        <info>php %command.full_name% --host=127.0.0.1</info>
                    Use the --port Configure HttpServer port numbers:
                        <info>php %command.full_name% --port=9764</info>
                    Use the --consul-service-name Consul service center service name:
                        <info>php %command.full_name% --consul-service-name=job_service_name</info>
                    EOF
            );
    }

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct();
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function handle(): int
    {
        $this->config = $this->container->get(ConfigInterface::class);
        $this->stdoutLogger = $this->container->get(StdoutLoggerInterface::class);
        $this->bootStrap();
        $this->stdoutLogger->info(str_repeat(Emoji::flagsForFlagChina() . '  ', 10));
        $port = (int) $this->input->getOption('port');
        $host = $this->input->getOption('host');
        $name = $this->input->getOption('consul-service-name');
        $this->stdoutLogger->info(sprintf('%s JobConsumer Successfully Processed# Host:[%s]  Port:[%s]  Name:[%s] %s', Emoji::manSurfing(), $host, $port, $name, Emoji::rocket()));
        $this->makeServer($host, $port, $name);

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function makeServer(string $host, int $port, string $name): void
    {
        $server = new HttpServer();
        $server->bind($host, $port)->listen();
        $serviceId = $this->registerConsul(...func_get_args());
        $this->subscribe($serviceId);
        while (true) {
            try {
                $connection = $server->acceptConnection();
                HyperfCo::create(static function () use ($connection) {
                    try {
                        while (true) {
                            $request = null;
                            try {
                                /** @var SwowCloudRequest $request */
                                $request = $connection->recvHttpRequest(make(SwowCloudRequest::class));
                                switch ($request->getPath()) {
                                    case '/detail':
                                    {
                                        $coroutineId = $request->get('coroutine_id');
                                        $coroutine = SwowCo::get((int) $coroutineId);
                                        $data = [
                                            'state' => $coroutine?->getStateName(),
                                            // 当前协程
                                            'trace_list' => Json::encode($coroutine?->getTrace()),
                                            // 协程函数调用栈
                                            'executed_file_name' => $coroutine?->getExecutedFilename(),
                                            // 获取执行文件名
                                            'executed_function_name' => $coroutine?->getExecutedFunctionName(),
                                            // 获取执行的函数名称
                                            'executed_function_line' => $coroutine?->getExecutedLineno(),
                                            // 获得执行的文件行数
                                            'vars' => $coroutine?->getDefinedVars(),
                                            // 获取定义的变量
                                            'round' => $coroutine?->getRound(),
                                            // 获取协程切换次数
                                            'elapsed' => $coroutine?->getElapsed(),
                                            // 获取协程运行的时间以便于分析统计或找出僵尸协程
                                        ];
                                        $response = new Response();
                                        $response->json([
                                            'code' => 0,
                                            'msg' => 'ok!',
                                            'data' => $data,
                                        ]);
                                        $connection->sendHttpResponse($response);
                                        break;
                                    }
                                    case '/Health':
                                        $response = new Response();
                                        $response->text('Im Ok');
                                        $connection->sendHttpResponse($response);
                                        break;
                                    case '/cancel':
                                        $params = serendipity_json_decode(
                                            $request->getBody()
                                                ->getContents()
                                        );
                                        $coroutine = SwowCo::get((int) $params['coroutine_id']);
                                        $response = new Response();
                                        if (!$coroutine instanceof SwowCo) {
                                            $response->json([
                                                'code' => 1,
                                                'msg' => 'Unknown!',
                                                'data' => [],
                                            ]);
                                            $connection->sendHttpResponse($response);
                                            break;
                                        }
                                        if ($coroutine === SwowCo::getCurrent()) {
                                            $connection->respond(
                                                Json::encode([
                                                    'code' => 1,
                                                    'msg' => '参数错误!',
                                                    'data' => [],
                                                ])
                                            );
                                            break;
                                        }
                                        $coroutine->kill();
                                        DB::execute(
                                            sprintf(
                                                "update task set status  = %s,memo = '%s' where coroutine_id = %s and status = %s and id = %s",
                                                Task::TASK_CANCEL,
                                                sprintf(
                                                    '客户度IP:%s取消了任务,请求时间:%s.',
                                                    $connection->getPeerAddress(),
                                                    Carbon::now()
                                                        ->toDateTimeString()
                                                ),
                                                $params['coroutine_id'],
                                                Task::TASK_ING,
                                                $params['id']
                                            )
                                        );
                                        if ($coroutine->isAvailable()) {
                                            $response->json([
                                                'code' => 1,
                                                'msg' => 'Not fully killed, try again later...',
                                                'data' => [],
                                            ]);
                                        } else {
                                            $response->json([
                                                'code' => 0,
                                                'msg' => 'Killed',
                                                'data' => [],
                                            ]);
                                        }
                                        $connection->sendHttpResponse($response);
                                        break;
                                    default:
                                    {
                                        $connection->error(HttpStatus::NOT_FOUND);
                                    }
                                }
                            } catch (HttpException $exception) {
                                $connection->error($exception->getCode(), $exception->getMessage());
                            }
                            if (!$request || !$request->getKeepAlive()) {
                                break;
                            }
                        }
                    } catch (Exception) {
                        // you can log error here
                    } finally {
                        $connection->close();
                    }
                });
            } catch (SocketException|CoroutineException $exception) {
                if (in_array($exception->getCode(), [EMFILE, ENFILE, ENOMEM], true)) {
                    sleep(1);
                } else {
                    break;
                }
            }
        }
    }

    protected function subscribe(string $serviceId): void
    {
        HyperfCo::create(
            function () use ($serviceId) {
                /* 测试多个消费者并发代码 */
                for ($i = 0; $i < 1; $i++) {
                    HyperfCo::create(function () use ($i, $serviceId) {
                        $subscriber = make(Nsq::class, [
                            $this->container,
                            $this->config->get(sprintf('nsq.%s', 'default')),
                        ]);
                        $channel = sprintf('JobConsumer-%s-%d', $serviceId, $i);
                        $consumer = $this->makeConsumer(JobConsumer::class, AbstractConsumer::TOPIC_PREFIX . self::TOPIC_SUFFIX, $channel, $serviceId);
                        $this->stdoutLogger->debug($channel . ' Started#');
                        $subscriber->subscribe(
                            AbstractConsumer::TOPIC_PREFIX . self::TOPIC_SUFFIX,
                            'JobConsumer' . $i,
                            function (Message $message) use ($consumer) {
                                try {
                                    $result = $consumer->consume($message);
                                } catch (Throwable $error) {
                                    // Segmentation fault
                                    $this->stdoutLogger->error(
                                        sprintf(
                                            'Consumer failed to consume %s,reason: %s,file: %s,line: %s',
                                            'Consumer',
                                            $error->getMessage(),
                                            $error->getFile(),
                                            $error->getLine()
                                        )
                                    );
                                    $result = Result::DROP;
                                }

                                return $result;
                            }
                        );
                    });
                }
            }
        );
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function makeConsumer(
        string $class,
        string $topic,
        string $channel,
        string $serviceId = '',
        string $redisPool = 'default',
    ): AbstractConsumer {
        /**
         * @var AbstractConsumer $consumer
         */
        $consumer = ApplicationContext::getContainer()
            ->get($class);
        $consumer->setTopic($topic);
        $consumer->setChannel($channel);
        $consumer->setRedisPool($redisPool);
        $consumer->setServiceId($serviceId);

        return $consumer;
    }

    protected function bootStrap(): void
    {
        $this->showLogo();
        KernelProvider::create(self::COMMAND_PROVIDER_NAME)
            ->bootApp();
        HyperfCo::create(fn () => $this->dispatchCrontab());
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function dispatchCrontab(): void
    {
        if ($this->config->get('crontab.enable')) {
            $this->container->get(EventDispatcherInterface::class)
                ->dispatch(
                    new CrontabEvent(),
                    CrontabEvent::CRONTAB_REGISTER
                );
            $this->container->get(CrontabDispatcher::class)
                ->handle();
        }
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function registerConsul(string $host, int $port, string $name): string
    {
        $register = $this->container->get(RegisterServices::class);

        if (in_array($host, ['127.0.0.1', '0.0.0.0'])) {
            $host = $this->getInternalIp();
        }
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException(sprintf('Invalid host %s', $host));
        }
        if (!is_numeric($port) || ($port < 0 || $port > 65535)) {
            throw new InvalidArgumentException(sprintf('Invalid port %s', $port));
        }
        $register->register($host, $port, ['protocol' => 'http'], $name, $this::class);

        return $register->getServiceId();
    }

    protected function getInternalIp(): string
    {
        /** @var mixed|string $ip */
        $ip = gethostbyname(gethostname());
        if (is_string($ip)) {
            return $ip;
        }
        throw new RuntimeException('Can not get the internal IP.');
    }
}
