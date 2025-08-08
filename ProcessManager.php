<?php

/**
 * 进程管理器类
 * 
 * 负责创建、监控和管理子进程，处理进程间通信和信号处理
 */
class ProcessManager
{
    /**
     * 主进程PID文件路径
     * @var string
     */
    private $masterPidFile;
    
    /**
     * 主进程状态文件路径
     * @var string
     */
    private $masterStatusFile;
    
    /**
     * 子进程PID数组
     * @var array
     */
    private $forkArr = [];
    
    /**
     * 主进程停止标志
     * @var int
     */
    private $masterStop = 0;
    
    /**
     * 日志记录器
     * @var Logger
     */
    private $logger;
    
    /**
     * 子进程数量
     * @var int
     */
    private $count;
    
    /**
     * 监听的socket
     * @var resource|null
     */
    private $socket = null;
    
    /**
     * Worker实例
     * @var Worker
     */
    private $worker;
    
    /**
     * 重载标志文件
     * @var string
     */
    private $reloadFlagFile;
    
    /**
     * 构造函数
     * 
     * @param Logger $logger 日志记录器
     * @param int $count 子进程数量
     * @param string $masterPidFile 主进程PID文件
     * @param string $masterStatusFile 主进程状态文件
     */
    public function __construct($logger, $count, $masterPidFile = 'masterPidFile.pid', $masterStatusFile = 'masterStatusFile.status')
    {
        $this->logger = $logger;
        $this->count = $count;
        $this->masterPidFile = $masterPidFile;
        $this->masterStatusFile = $masterStatusFile;
        $this->reloadFlagFile = __DIR__ . '/reload_flag';
    }
    
    /**
     * 设置Worker实例
     * 
     * @param Worker $worker Worker实例
     * @return $this
     */
    public function setWorker(Worker $worker)
    {
        $this->worker = $worker;
        return $this;
    }
    
    /**
     * 设置监听socket
     * 
     * @param resource $socket 监听socket
     * @return $this
     */
    public function setSocket($socket)
    {
        $this->socket = $socket;
        return $this;
    }
    
    /**
     * 启动进程管理器
     * 
     * @return void
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

        // 保存主进程pid到文件用于stop,reload,status等命令操作
        $masterPid = posix_getpid();
        file_put_contents($this->masterPidFile, $masterPid);

        // 注册主进程信号
        $this->registerMasterSignals();

        // 创建count个子进程，用于接受请求和处理数据
        while(count($this->forkArr) < $this->count) 
        {
            $this->forkWorker();
        }

        // 主进程接受信号和监听子进程
        $this->masterLoop();
    }
    
    /**
     * 注册主进程信号处理器
     * 
     * @return void
     */
    private function registerMasterSignals()
    {
        pcntl_signal(SIGINT,  [$this, 'masterSignalHandler'], false); // 退出
        pcntl_signal(SIGUSR1, [$this, 'masterSignalHandler'], false); // 重载
        pcntl_signal(SIGUSR2, [$this, 'masterSignalHandler'], false); // 状态
    }
    
    /**
     * 主进程信号处理函数
     * 
     * @param int $sigNo 信号编号
     * @return void
     */
    public function masterSignalHandler($sigNo)
    {
        switch ($sigNo) {
            case SIGINT:
                // 退出，先发送子进程信号关闭子进程，再等待主进程退出
                foreach ($this->forkArr as $pid) 
                {
                    $this->logger->log("优雅关闭子进程 pid: {$pid}");
                    posix_kill($pid, SIGTERM);
                }
                $this->masterStop = 1; // 将主进程状态置成退出
                break;
                
            case SIGUSR1:
                // 重载，优雅关闭所有子进程
                $this->logger->log("收到重载信号，准备重载服务器");
                
                // 清除 opcode 缓存
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                }
                
                // 创建标记文件，告知子进程需要重载代码
                file_put_contents($this->reloadFlagFile, time());
                
                foreach ($this->forkArr as $pid) 
                {
                    $this->logger->log("关闭子进程 pid: {$pid}");
                    posix_kill($pid, SIGTERM);
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
                // 处理其他信号
                break;
        }
    }
    
    /**
     * 主进程主循环
     * 
     * @return void
     */
    public function masterLoop()
    {
        while (true)
        {
            $status = 0;

            // 阻塞直至获取子进程退出或中断信号或调用一个信号处理器
            $pid = pcntl_wait($status, WUNTRACED);

            // 在每次循环只需要调用一次信号分发
            pcntl_signal_dispatch();

            if ($pid > 0) {
                // 子进程退出
                $this->logger->log("子进程退出 pid: {$pid}, 状态: {$status}");
                unset($this->forkArr[$pid]);
                
                // 关闭还是重启
                if (!$this->masterStop) 
                {
                    // 检查子进程退出状态
                    if (pcntl_wifexited($status) && pcntl_wexitstatus($status) == 0) 
                    {
                        $this->logger->log("子进程正常退出，重启一个新子进程");
                    } 
                    else 
                    {
                        $this->logger->log("子进程异常退出，重启一个新子进程");
                    }
                    // 重启子进程
                    $this->forkWorker();
                } else {
                    $this->logger->log("主进程已停止，子进程退出");
                }
            } else {
                // 主进程退出状态并且没有子进程时退出
                if ($this->masterStop && empty($this->forkArr)) {
                    $this->cleanupMaster();
                    exit(0);
                }
            }
        }
    }
    
    /**
     * 创建子进程
     * 
     * @return int 子进程PID，如果是子进程则返回0
     */
    public function forkWorker()
    {
        $pid = pcntl_fork();
        
        if ($pid == -1) {
            $this->logger->log("子进程创建失败");
            die('子进程创建失败');
        } 
        else if ($pid == 0) {
            // 子进程
            
            // 子进程启动时清除代码缓存
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
            
            // 检查是否需要重载代码
            if (file_exists($this->reloadFlagFile)) {
                // 重新包含关键文件
                $this->reloadFiles();
                // 删除标记文件
                @unlink($this->reloadFlagFile);
            }
            
            // 注册子进程信号
            $this->registerWorkerSignals();
            
            // 启动工作进程
            if ($this->worker) {
                $this->worker->runWorker($this->socket);
                exit(0);
            } else {
                $this->logger->log("子进程启动失败：Worker未设置");
                exit(1);
            }
        } 
        else {
            // 主进程
            $this->logger->log("创建子进程 pid: {$pid}");
            $this->forkArr[$pid] = $pid;
            return $pid;
        }
    }
    
    /**
     * 重新加载核心文件
     * 
     * @return void
     */
    private function reloadFiles()
    {
        foreach ([
            __DIR__ . '/Logger.php',
            __DIR__ . '/AsyncLogger.php',
            __DIR__ . '/HttpParser.php',
            __DIR__ . '/Connection.php',
            __DIR__ . '/MiddlewareManager.php',
            __DIR__ . '/Context.php',
            __DIR__ . '/WebSocketParser.php',
            __DIR__ . '/EventManager.php', 
            __DIR__ . '/Middleware/HealthCheckMiddleware.php',
            __DIR__ . '/Middleware/WebSocketMiddleware.php',
            __DIR__ . '/Middleware/AuthMiddleware.php',
            // 可以添加其他需要重新加载的文件
        ] as $file) {
            if (file_exists($file)) {
                include_once $file;
            }
        }
    }
    
    /**
     * 注册子进程信号处理器
     * 
     * @return void
     */
    private function registerWorkerSignals()
    {
        // 子进程注册优雅终止信号：SIGTERM
        pcntl_signal(SIGTERM, function() {
            $this->logger->log("子进程收到 SIGTERM，准备优雅退出");
            if ($this->worker) {
                $this->worker->stopWorker();
            }
            exit(0);
        }, false);
        
        // 忽略 SIGPIPE 信号
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }
    
    /**
     * 清理主进程资源
     * 
     * @return void
     */
    private function cleanupMaster()
    {
        $this->logger->log("主进程退出，清理资源");
        if (file_exists($this->masterPidFile)) {
            unlink($this->masterPidFile);
        }
        if ($this->socket && is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
    
    /**
     * 发送命令给主进程
     * 
     * @param string $command 命令 (stop/reload/status)
     * @return bool 是否成功
     */
    public function sendCommand($command)
    {
        if (!file_exists($this->masterPidFile)) {
            $this->logger->log("主进程不存在或已停止");
            return false;
        }
        
        $masterPid = file_get_contents($this->masterPidFile);
        if (!$masterPid) {
            $this->logger->log("无法获取主进程PID");
            return false;
        }
        
        switch ($command) {
            case 'stop':
                $this->logger->log("发送停止命令到主进程 {$masterPid}");
                posix_kill($masterPid, SIGINT);
                break;
                
            case 'reload':
                $this->logger->log("发送重载命令到主进程 {$masterPid}");
                posix_kill($masterPid, SIGUSR1);
                break;
                
            case 'status':
                $this->logger->log("发送状态命令到主进程 {$masterPid}");
                posix_kill($masterPid, SIGUSR2);
                sleep(1); // 等待主进程将状态信息放入文件
                if (file_exists($this->masterStatusFile)) {
                    $masterStatus = file_get_contents($this->masterStatusFile);
                    $this->logger->log($masterStatus);
                    unlink($this->masterStatusFile);
                } else {
                    $this->logger->log("无法获取主进程状态");
                }
                break;
                
            default:
                $this->logger->log("未知命令: {$command}");
                return false;
        }
        
        return true;
    }
    
    /**
     * 获取子进程数组
     * 
     * @return array 子进程PID数组
     */
    public function getWorkerPids()
    {
        return $this->forkArr;
    }
    
    /**
     * 获取子进程数量
     * 
     * @return int 子进程数量
     */
    public function getWorkerCount()
    {
        return count($this->forkArr);
    }
}