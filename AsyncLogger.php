<?php
class AsyncLogger
{
    private $logQueue = [];
    private $event = null;
    private $eventBase = null;
    private $flushInterval = 1.0; // 每秒刷新一次
    private $maxQueueSize = 1000; // 队列最大条目数
    
    public function __construct($eventBase)
    {
        $this->eventBase = $eventBase;
        // 创建定时事件，定期刷新日志
        $this->event = new Event(
            $this->eventBase,
            -1,
            Event::TIMEOUT | Event::PERSIST,
            [$this, 'flushLogs']
        );
        $this->event->add($this->flushInterval);
    }
    
    public function log($msg, $file = 'worker.log')
    {
        $time = date('Y-m-d H:i:s');
        $formattedMsg = "[{$time}] {$msg}\n";
        
        // 将日志放入队列
        $this->logQueue[] = ['msg' => $formattedMsg, 'file' => $file];
        
        // 输出到标准输出（这部分是同步的，但通常很快）
        echo $formattedMsg;
        
        // 如果队列过大，立即刷新
        if (count($this->logQueue) >= $this->maxQueueSize) {
            $this->flushLogs();
        }
    }
    
    public function flushLogs()
    {
        if (empty($this->logQueue)) {
            return;
        }
        
        // 按文件分组日志
        $groupedLogs = [];
        foreach ($this->logQueue as $log) {
            if (!isset($groupedLogs[$log['file']])) {
                $groupedLogs[$log['file']] = '';
            }
            $groupedLogs[$log['file']] .= $log['msg'];
        }
        
        // 批量写入文件
        foreach ($groupedLogs as $file => $content) {
            file_put_contents($file, $content, FILE_APPEND);
        }
        
        // 清空队列
        $this->logQueue = [];
    }
    
    // 确保程序结束前写入所有日志
    public function __destruct()
    {
        $this->flushLogs();
    }
}