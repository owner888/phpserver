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
    private $forkArr = []; // 子进程 pid 数组
    private $socket = null; // 监听 socket
    private $masterStop = 0; // 主进程是否停止
    private $maxConnections = 1024; // 最大连接数
    private $connectionCount = 0; //每个子进程到连接数
    private $requestNum = 0; //每个子进程总请求数
    private $connectionEvents = []; // 保存连接事件
    private $connectionLastActive = []; // 记录连接最后活跃时间
    private $logger;
    private $httpParser;
    private $connections = []; // 用于管理所有 Connection 实例

    public function __construct($logger, $httpParser)
    {
        $this->logger = $logger;
        $this->httpParser = $httpParser;

        if (!$this->onMessage) 
        {
            // 默认处理
            $this->onMessage = function($worker, $connection)
            {
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
            // sleep(1);
            pcntl_signal_dispatch(); // 信号分发
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED); // 堵塞直至获取子进程退出或中断信号或调用一个信号处理器，或者没有子进程时返回错误
            pcntl_signal_dispatch();
            if ($pid > 0) {
                // 子进程退出
                $this->logger->log("子进程退出pid: {$pid}, 状态: {$status}");
                unset($this->forkArr[$pid]);
                // 关闭还是重启
                if (!$this->masterStop) 
                {
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
     * 主进程处理信号
     * @param $sigNo
     */
    public function masterSignalHandler($sigNo)
    {
        switch ($sigNo) {
            case SIGINT:
                // 退出，先发送子进程信号关闭子进程，再等待主进程退出
                foreach ($this->forkArr as $pid) {
                    $this->logger->log("优雅关闭子进程pid: {$pid}");
                    posix_kill($pid, SIGKILL);
                }
                $this->masterStop = 1; // 将主进程状态置成退出
                break;
            case SIGUSR1:
                // 重启，关闭当前存在但子进程，主进程会监视退出的子进程并重启一个新子进程
                foreach ($this->forkArr as $pid) {
                    $this->logger->log("关闭子进程pid: {$pid}");
                    posix_kill($pid, SIGKILL);
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

                foreach ($this->forkArr as $childPid) {
                    $str .= $childPid."\n";
                }
                file_put_contents($this->masterStatusFile, $str);
                break;
            default:
                // 处理所有其他信号
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
                  
            // 子进程注册 SIGTERM 信号
            pcntl_signal(SIGTERM, function() {
                $this->logger->log("子进程收到 SIGTERM，准备优雅退出");
                // 清理资源
                foreach ($this->connectionEvents as $event) {
                    $event->del();
                }
                if ($this->socket) {
                    fclose($this->socket);
                }
                exit(0);
            }, false);
            
            // 创建 EventBase 实例
            $base = new EventBase();

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
                Event::TIMEOUT | Event::PERSIST,
                function() {
                    $now = time();
                    foreach ($this->connectionLastActive as $id => $last) {
                        if ($now - $last > 60) { // 超过60秒未活跃
                            if (isset($this->connectionEvents[$id])) {
                                $this->connectionEvents[$id]->del();
                                unset($this->connectionEvents[$id]);
                            }
                            // 关闭socket
                            // 这里假设你有保存socket对象，可以用 $id 找到
                            $this->logger->log("连接超时关闭: $id");
                            $this->connectionCount--;
                            unset($this->connectionLastActive[$id]);
                        }
                    }
                }
            );
            $timeoutEvent->add(10); // 每10秒检查一次

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
            }
        } else {
            // 主进程将子进程pid保存到数组
            $this->logger->log("创建子进程pid: {$pid}");
            $this->forkArr[$pid] = $pid;
        }
    }

    /**
     * 子进程接受请求
     * @param $socket
     * @param $events
     * @param $arg
     */
    public function acceptConnect($socket, $events, $args)
    {
        try {
            $base = $args[0]; // 获取传递的 EventBase 实例
            $newSocket = @stream_socket_accept($socket, 0, $remote_address); // 第二个参数设置0，不堵塞，未获取到会警告
            // 有一个连接过来时，子进程都会触发本函数，但只有一个子进程获取到连接并处理
            if (!$newSocket) {
                return;
            }

            if ($this->connectionCount >= $this->maxConnections) 
            {
                $this->logger->log("连接数达到上限，拒绝新连接");
                @fclose($newSocket);
                return;
            }

            // 记录连接最后活跃时间
            $this->connectionLastActive[(int)$newSocket] = time();

            $this->logger->log("acceptConnect");
            $this->connectionCount++;

            stream_set_blocking($newSocket, 0);
            // 兼容 hhvm
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($newSocket, 0);
            }
            if (function_exists('stream_set_write_buffer')) {
                stream_set_write_buffer($newSocket, 0);
            }

            // 创建新事件监听新连接
            $event = new Event(
                $base, // EventBase 实例
                $newSocket, // 监听的文件描述符
                Event::READ | Event::PERSIST, // 监听可读事件并保持持久化
                [$this, "acceptData"], // 回调函数
                [$base, $newSocket] // 传递 EventBase
            );
            // 添加事件
            $event->add();

            $connection = new Connection($newSocket, $event);
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
            $connection->updateActive();// 更新连接最后活跃时间
            // http 服务器（HTTP1.1 默认使用 keep-alive 保持连接）
            // 限制最大请求体 2MB
            // $buffer = @fread($newSocket, 2 * 1024 * 1024);
            // if (strlen($buffer) > 2 * 1024 * 1024) {
            //     $this->logger->log("请求体过大，已拒绝");
            //     fwrite($newSocket, "HTTP/1.1 413 Payload Too Large\r\n\r\n");
            //     @fclose($newSocket);
            //     return;
            // }

            // HTTP 服务器
            $buffer = @fread($newSocket, 65535); //获取数据
            $this->logger->log("获取客户端数据:{$buffer}");

            if ($buffer === '' || $buffer === false) 
            {
                if (feof($newSocket) || !is_resource($newSocket) || $buffer === false) 
                {
                    $this->logger->log("客户端关闭连接");
                    // 删除事件对象
                    if (isset($this->connectionEvents[$id])) 
                    {
                        $this->connectionEvents[$id]->del();
                        unset($this->connectionEvents[$id]);
                    }
                    @fclose($newSocket); // 关闭连接
                    $this->connectionCount--;
                    return;
                }
            }
            $this->requestNum++;
            $this->httpParser->parse($buffer);
            call_user_func_array($this->onMessage, [$this, $connection]); // 调用处理函数

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
        fwrite($connection->socket, $msg, 8192);
        return true;
    }

    /**
     * 发送命令给主进程
     * @param $command
     */
    public function sendSignalToMaster($command)
    {
        $masterPid = file_get_contents($this->masterPidFile);
        if ($masterPid) {
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
        } else {
            $this->logger->log("主进程不存在或已停止");
            exit;
        }
    }
}
