<?php

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/HttpParser.php';
require_once __DIR__ . '/Connection.php';

// ab 测试
// ab -n100000 -c100 -k http://127.0.0.1:2345/

// 解析命令
$command = trim($argv[1] ?? '');
$available_commands = [
    'start',
    'stop',
    'reload',
    'status',
];
$usage = "Usage: php index.php {" . implode('|', $available_commands) . "}\n";
if (empty($command) || !in_array($command, $available_commands)) 
{
    exit($usage);
}

if (!extension_loaded('event')) 
{
    exit("Event extension is not loaded.");
}

if (!class_exists('EventBase')) 
{
    exit("Class EventBase() is not available.");
}

$worker = new Worker(new Logger(), new HttpParser());

switch ($command) {
    case 'start':
        $worker->start();
        break;
    case 'stop':
    case 'reload':
    case 'status':
        $worker->sendSignalToMaster($command);
        break;
}

class Worker
{
    public $count = 4;  // 子进程数，2 最高, 可以达到 2W5; 4 低一点，2W4; 8 更低，只有 1W 多
    public $localSocket = 'tcp://0.0.0.0:2345'; // 监听地址
    public $onMessage = null; // 处理函数
    
    private $masterPidFile = 'masterPidFile.pid'; // 主进程pid
    private $masterStatusFile = 'masterStatusFile.status'; // 主进程状态文件
    private $forkArr = [];          // 子进程数组
    private $socket = null;         // 监听 socket
    private $masterStop = 0;        // 主进程是否停止
    private $exiting = false;       // 进程是否退出中
    private $maxConnections = 1024; // 最大连接数
    private $connectionCount = 0;   // 每个子进程到连接数
    private $requestNum = 0;        // 每个子进程总请求数
    private $logger;
    private $httpParser;
    private $connections = []; // 用于管理所有 Connection 实例
    private $eventBase;

    public function __construct($logger, $httpParser)
    {
        $this->logger = $logger;
        $this->httpParser = $httpParser;

        if (!$this->onMessage) 
        {
            // 默认处理
            $this->onMessage = function($worker, $connection, $parsed)
            {
                $worker->logger->log("处理连接: {$connection->id}");
                // var_dump($_GET, $_POST, $_COOKIE, $_SESSION, $_SERVER, $_FILES);
                // var_dump($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING']);
                // 发送数据给客户端
                $worker->sendData($connection, "hello world \n");
            };
        }
    }

    /**
     * 主进程启动
     */
    public function start()
    {
        if (file_exists($this->masterPidFile)) 
        {
            $pid = file_get_contents($this->masterPidFile);
            if ($pid && posix_kill($pid, 0)) 
            {
                exit("当前程序已经在运行，请不要重复启动\n");
            }
            // 如果进程不存在但PID文件还在，清理PID文件
            unlink($this->masterPidFile);
        }
        // 判断当前程序是否已经启动
        $masterPidFileExist = is_file($this->masterPidFile);
        if ($masterPidFileExist) 
        {
            exit("当前程序已经在运行，请不要重启启动\n");
        }

        // 保存主进程pid到文件用于stop,reload,status等命令操作
        $masterPid = posix_getpid();
        file_put_contents($this->masterPidFile, $masterPid);

        // 注册主进程信号，pcntl_signal第三个参数设置成false，才会有信号时被pcntl_wait调用
        pcntl_signal(SIGINT,  [$this, 'masterSignalHandler'], false); // 退出，用于stop命令或主进程窗口按下ctrl+c
        pcntl_signal(SIGUSR1, [$this, 'masterSignalHandler'], false); // 自定义信号1，用于reload命令
        pcntl_signal(SIGUSR2, [$this, 'masterSignalHandler'], false); // 自定义信号2，用户status命令

        // 主进程创建tcp服务器
        $errno = 0;
        $errmsg = '';
        $socket = stream_socket_server($this->localSocket, $errno, $errmsg);

        // 尝试打开 KeepAlive TCP 和禁用 Nagle 算法
        if (function_exists('socket_import_stream')) 
        {
            $socketImport = socket_import_stream($socket);
            @socket_set_option($socketImport, SOL_SOCKET, SO_KEEPALIVE, 1);
            @socket_set_option($socketImport, SOL_TCP, TCP_NODELAY, 1);
        }

        // Non blocking.
        stream_set_blocking($socket, 0);
        $this->socket = $socket;

        // 创建count个子进程，用于接受请求和处理数据
        while(count($this->forkArr) < $this->count) 
        {
            $this->fork();
        }

        // 主进程接受信号和监听子进程信号
        while(true)
        {
            pcntl_signal_dispatch(); // 信号分发
            $status = 0;
            // 阻塞直至获取子进程退出或中断信号或调用一个信号处理器，或者没有子进程时返回错误
            $pid = pcntl_wait($status, WUNTRACED); 
            pcntl_signal_dispatch();
            if ($pid > 0) {
                // 子进程退出
                $this->logger->log("子进程退出pid: {$pid}, 状态: {$status}");
                unset($this->forkArr[$pid]);
                // 关闭还是重启
                if (!$this->masterStop) 
                {
                    // 如果主进程没有停止，重启子进程
                    $this->logger->log("主进程未停止，重启子进程");
                    $this->logger->log("子进程退出状态: {$status}");
                    // 检查子进程退出状态
                    // pcntl_wifexited 检查子进程是否正常退出
                    // pcntl_wexitstatus 获取子进程的退出状态码
                    // 如果子进程异常退出，重启一个新子进程
                    // 如果子进程是因为信号退出的，pcntl_wifsignaled
                    // pcntl_wtermsig 获取子进程终止的信号编号
                    // pcntl_wstopsig 获取子进程停止的信号编号
                    // pcntl_wifstopped 检查子进程是否停止
                    // pcntl_wifcontinued 检查子进程是否继续运行
                    // pcntl_wifexited 检查子进程是否正常退出
                    // pcntl_wexitstatus 获取子进程的退出状态码
                    // pcntl_wifsignaled 检查子进程是否因为信号退出
                    // pcntl_wtermsig 获取子进程终止的信号编号
                    // pcntl_wstopsig 获取子进程停止的信号编号  
                    // 如果子进程退出状态为 0，表示正常退出
                    if (pcntl_wifexited($status) && pcntl_wexitstatus($status) == 0) 
                    {
                        $this->logger->log("子进程正常退出，重启一个新子进程");
                    } 
                    else 
                    {
                        $this->logger->log("子进程异常退出，重启一个新子进程");
                    }
                    // 重启
                    $this->fork();
                } else {
                    $this->logger->log("主进程已停止，子进程退出");
                }
            } else {
                // 主进程退出状态并且没有子进程时退出
                if ($this->masterStop && empty($this->forkArr)) {
                    unlink($this->masterPidFile);
                    fclose($this->socket);
                    $this->logger->log("主进程退出");
                    exit(0);
                }
            }
        }
    }

    /**
     * 主进程信号处理函数
     * @param int $sigNo
     */
    // 处理 SIGINT, SIGUSR1, SIGUSR2 信号
    // SIGINT: 退出，SIGUSR1: 重启，SIGUSR2: 保存状态信息
    // 其他信号: 可以添加其他处理逻辑
    // 例如 SIGTERM: 优雅退出，清理资源等
    // 例如 SIGUSR2: 保存状态信息到文件
    // 例如 SIGCHLD: 子进程退出时处理
    // 例如 SIGPIPE: 忽略管道破裂信号
    // 例如 SIGALRM: 定时器信号
    // 例如 SIGHUP:  重新加载配置文件
    // 例如 SIGQUIT: 退出并生成核心转储文件
    // 例如 SIGTSTP: 暂停进程
    // 例如 SIGCONT: 恢复暂停的进程
    // 例如 SIGTTIN: 后台进程尝试读取终端时处理
    // 例如 SIGTTOU: 后台进程尝试写入终端
    // 例如 SIGXCPU: 进程超过 CPU 时间限制时处理
    // 例如 SIGXFSZ: 进程超过文件大小限制时处理
    // 例如 SIGPROF: 进程超过配置的 CPU 时间限制时处理
    // 例如 SIGVTALRM: 进程超过虚拟时间限制时处理
    // 例如 SIGWINCH: 窗口大小改变时处理
    // 例如 SIGPOLL: 轮询事件发生时处理
    public function masterSignalHandler($sigNo)
    {
        switch ($sigNo) {
            case SIGINT:
                // 退出，先发送子进程信号关闭子进程，再等待主进程退出
                foreach ($this->forkArr as $pid) 
                {
                    $this->logger->log("优雅关闭子进程 pid: {$pid}");
                    posix_kill($pid, SIGKILL);
                }
                $this->masterStop = 1; // 将主进程状态置成退出
                break;
            case SIGUSR1:
                // 重启，优雅关闭所有子进程
                foreach ($this->forkArr as $pid) 
                {
                    $this->logger->log("关闭子进程 pid: {$pid}");
                    posix_kill($pid, SIGTERM); // 用 SIGTERM 而不是 SIGKILL
                    // 这里可以使用 SIGKILL 强制关闭子进程，但不推荐，因为
                    // SIGTERM 可以让子进程优雅退出，清理资源等
                    // posix_kill($pid, SIGKILL); // 强制关闭子进程
                }
                break;
            case SIGUSR2:
                $this->logger->log("将状态信息保存至文件: {$this->masterStatusFile}");
                // 将状态信息保存至文件
                $str = "---------------------STATUS---------------------\n";
                $str .= 'PHP version:' . PHP_VERSION . "\n";
                $str .= 'processes num:' . $this->count . "\n";
                $str .= "---------------------PROCESS STATUS---------------------\n";
                $str .= "pid\n";

                foreach ($this->forkArr as $childPid) 
                {
                    $str .= $childPid."\n";
                }
                file_put_contents($this->masterStatusFile, $str);
                break;
            default:
                // 处理所有其他信号
        }
    }

    // 新增方法
    private function tryGracefulExit()
    {
        try {
            foreach ($this->connections as $connection) {
                // 检查是否还有未发送/未接收的数据
                if (!$connection->isIdle()) {
                    return; // 有活跃连接，暂不退出
                }
            }
            // 所有连接都空闲，安全退出
            $this->logger->log("所有连接处理完毕，进程安全退出");
            exit(0);
        } catch (\Throwable $e) {
            $this->logger->log("优雅退出异常: " . $e->getMessage());
            exit(1); // 异常退出
        }
    }

    /**
     * 创建子进程
     */
    public function fork()
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            die('子进程创建失败');
        } else if ($pid == 0) {
            // 以下代码在子进程中运行

            // 子进程注册优雅终止信号：SIGTERM
            pcntl_signal(SIGTERM, function() {
                $this->logger->log("子进程收到 SIGTERM，准备优雅退出");
                $this->exiting = true;
                if ($this->socket) 
                {
                    fclose($this->socket);
                    $this->socket = null;
                }
                // 检查连接
                $this->tryGracefulExit();
                exit(0);
            }, false);
            
            // 创建 EventBase 实例
            $this->eventBase = new EventBase();
            $base = $this->eventBase;

            // // 定时统计输出
            // $statEvent = new Event(
            //     $base,
            //     -1,
            //     Event::TIMEOUT | Event::PERSIST,
            //     function() {
            //         $this->logger->log("当前连接数: {$this->connectionCount}, 总请求数: {$this->requestNum}");
            //     }
            // );
            // $statEvent->add(5); // 每5秒输出一次
            $timeoutEvent = new Event(
                $base,
                -1,
                Event::TIMEOUT | Event::PERSIST, function() {
                    foreach ($this->connections as $id => $connection) {
                        if (!$connection->isActive() && !$this->testConnection($connection)) 
                        {
                            $this->logger->log("连接超时关闭: $id");
                            $connection->close();
                            unset($this->connections[$id]);
                            $this->connectionCount--;
                        }
                    }
                }
            );
            $timeoutEvent->add(10); // 每10秒检查一次

            $statEvent = new Event(
                $base,
                -1,
                Event::TIMEOUT | Event::PERSIST, function() {
                    $this->logger->log(sprintf(
                        "Memory usage: %s MB, Peak: %s MB",
                        round(memory_get_usage() / 1024 / 1024, 2),
                        round(memory_get_peak_usage() / 1024 / 1024, 2)
                    ));
                }
            );
            $statEvent->add(10); // 每分钟记录一次

            // 创建 Event 实例
            $event = new Event(
                $base,
                $this->socket,
                Event::READ | Event::PERSIST,
                [$this, "acceptConnect"],
                [$base] // 传递 EventBase
            );
            // 添加事件
            $event->add();
            // 开始事件循环
            // $base->loop();
            // 事件循环前后分发信号，实现优雅退出
            while (true) {
                pcntl_signal_dispatch();
                $base->loop(EventBase::LOOP_ONCE | EventBase::LOOP_NONBLOCK);
                pcntl_signal_dispatch();
                // 检查是否需要退出
                if ($this->exiting && count($this->connections) === 0) 
                {
                    $this->logger->log("所有连接已关闭，进程退出");
                    break;
                }
            }
        } else {
            // 主进程将子进程pid保存到数组
            $this->logger->log("创建子进程pid: {$pid}");
            $this->forkArr[$pid] = $pid;
        }
    }

    /**
     * 测试连接是否正常
     * 通过尝试向连接写入和读取数据、检查socket状态来判断连接是否有效
     * 
     * @param Connection $connection 要测试的连接对象
     * @return bool 连接正常返回true，连接异常返回false
     */
    private function testConnection(Connection $connection)
    {
        // 如果连接已经不是有效资源，直接返回false
        if (!$connection->isValid()) {
            return false;
        }
        
        // 检查socket是否可读可写
        $read = [$connection->socket];
        $write = [$connection->socket];
        $except = [];
        
        // 使用select检查连接状态，设置超时为0，立即返回结果
        if (@stream_select($read, $write, $except, 0, 0) === false) {
            // select失败，连接可能已断开
            return false;
        }
        
        // 如果近期有活动，可以认为连接是活跃的
        if ($connection->isActive()) {
            return true;
        }
        
        // 对于HTTP连接，可以通过发送HTTP ping来测试
        // 但这可能不适用于所有协议，取决于你的应用场景
        // 这里我们只简单检查socket状态
        
        // 检查是否存在错误
        $errno = 0;
        $errstr = '';
        $status = @socket_get_status($connection->socket);
        
        // 如果有错误或已关闭，连接无效
        if (isset($status['eof']) && $status['eof']) {
            return false;
        }
        
        // 如果有未处理的数据，说明连接还是活跃的
        if (!empty($connection->readBuffer) || !empty($connection->writeBuffer)) {
            return true;
        }
        
        // 尝试从socket读取数据(非阻塞)
        $oldBlock = stream_get_blocking($connection->socket);
        stream_set_blocking($connection->socket, 0);
        $data = @fread($connection->socket, 1);
        stream_set_blocking($connection->socket, $oldBlock);
        
        // 如果读取失败或读到EOF，连接可能已断开
        if ($data === false || ($data === '' && feof($connection->socket))) {
            return false;
        }
        
        // 连接正常
        return true;
    }

    /**
     * 子进程接受请求
     * @param $socket
     * @param $events
     * @param $arg
     */
    public function acceptConnect($socket, $events, $args)
    {
        $this->logger->log("acceptConnect");
        try {
            $base = $args[0]; // 获取传递的 EventBase 实例
            $newSocket = @stream_socket_accept($socket, 0, $remote_address); // 第二个参数设置0，不阻塞，未获取到会警告
            // 有一个连接过来时，子进程都会触发本函数，但只有一个子进程获取到连接并处理
            if (!$newSocket) return;

            if ($this->connectionCount >= $this->maxConnections) 
            {
                $this->logger->log("连接数达到上限，拒绝新连接");
                @fclose($newSocket);
                return;
            }

            $this->connectionCount++;

            stream_set_blocking($newSocket, 0);
            // 兼容 hhvm
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($newSocket, 0);
            }
            if (function_exists('stream_set_write_buffer')) {
                stream_set_write_buffer($newSocket, 0);
            }

            $connection = new Connection($newSocket);

            // 注册读事件
            $readEvent = new Event(
                $base,
                $newSocket,
                Event::READ | Event::PERSIST, // 监听可读事件并保持持久化
                [$this, "acceptData"],
                [$base, $newSocket]
            );
            // 添加事件
            $readEvent->add();
            $connection->readEvent = $readEvent;
            
            // 写事件先不注册，只有 send 时才注册
            $connection->writeEvent = null;
            // // 注册写事件
            // $writeEvent = new Event(
            //     $base,
            //     $newSocket,
            //     Event::WRITE | Event::PERSIST,
            //     function($fd, $events, $args) {
            //         $connection = $args[0];
            //         $connection->flush();
            //         if (empty($connection->writeBuffer)) {
            //             $connection->event->del();
            //         }
            //     },
            //     [$connection]
            // );
            // $writeEvent->add();
            // $connection->event = $writeEvent;
            $this->connections[$connection->id] = $connection;
        } catch (\Throwable $e) {
            $this->logger->log("acceptConnect异常: " . $e->getMessage());
        }
    }

    /**
     * 子进程处理数据
     * @param $newSocket
     * @param $events
     * @param $arg
     */
    public function acceptData($newSocket, $events, $args)
    {
        try {
            $id = (int)$newSocket;
            if (!isset($this->connections[$id])) return;
            $connection = $this->connections[$id];
            $connection->updateActive();
        
            $buffer = @fread($newSocket, 65535);

            if ($buffer === '' || $buffer === false) 
            {
                if (feof($newSocket) || !is_resource($newSocket) || $buffer === false) 
                {
                    $this->logger->log("客户端关闭连接");
                    $connection->close();
                    unset($this->connections[$id]);
                    $this->connectionCount--;
                    return;
                }
            }
            $this->requestNum++;
            $parsed = $this->httpParser->parse($buffer);
            call_user_func_array($this->onMessage, [$this, $connection, $parsed]); // 调用处理函数

            // TCP 服务器
            // $buffer = fread($newSocket, 1024);
            // if ($buffer === '' || $buffer === false)
            // {
            //     if (feof($newSocket) || !is_resource($newSocket) || $buffer === false)
            //     {
            //         $this->logger->log("客户端关闭连接");
            //         // 删除事件对象
            //         if (isset($this->connectionEvents[$socketId]))
            //         {
            //             $this->connectionEvents[$socketId]->del();
            //             unset($this->connectionEvents[$socketId]);
            //         }
            //         @fclose($newSocket);
            //     }
            // }
            // else
            // {
            //     $this->logger->log("获取客户端数据: " . $buffer);
            //     // 直接发送数据
            //     fwrite($newSocket, "hello client\n");
            // }
        } catch (\Throwable $e) {
            $this->logger->log("acceptData异常: " . $e->getMessage());
        }
    }

    /**
     * 发送数据给客户端
     * @param Connection $connection
     * @param string $sendBuffer
     * @param int $status
     * @param array $headers
     * @return bool
     */
    public function sendData($connection, $sendBuffer, $status = 200, $headers = [])
    {
        $msg = HttpParser::encode($sendBuffer, $status, $headers);
        // fwrite($connection->socket, $msg, 8192);
        $connection->send($msg);
        
        // 注册写事件（仅当有数据时）
        if (!empty($connection->writeBuffer) && $connection->writeEvent === null) 
        {
            $writeEvent = new Event(
                $this->eventBase,
                $connection->socket,
                Event::WRITE | Event::PERSIST,
                function($fd, $events, $args) {
                    $conn = $args[0];
                    $conn->flush();
                    if (empty($conn->writeBuffer)) {
                        $conn->writeEvent->del();
                        $conn->writeEvent = null;
                    }
                },
                [$connection]
            );
            $writeEvent->add();
            $connection->writeEvent = $writeEvent;
        }
        return true;
    }

    /**
     * 发送命令给主进程
     * @param $command
     */
    public function sendSignalToMaster($command)
    {
        $masterPid = file_get_contents($this->masterPidFile);
        if ($masterPid) 
        {
            switch ($command) {
                case 'stop':
                    posix_kill($masterPid, SIGINT);
                    break;
                case 'reload':
                    posix_kill($masterPid, SIGUSR1);
                    break;
                case 'status':
                    posix_kill($masterPid, SIGUSR2);
                    sleep(1); // 等待主进程将状态信息放入文件
                    $masterStatus = file_get_contents($this->masterStatusFile);
                    $this->logger->log($masterStatus);
                    unlink($this->masterStatusFile);
                    break;
            }
            exit;
        } 
        else 
        {
            $this->logger->log("主进程不存在或已停止");
            exit;
        }
    }
}
