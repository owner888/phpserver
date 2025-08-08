<?php
/**
 * 事件管理器类
 * 
 * 负责管理和调度基于 libevent 的事件系统
 */
class EventManager
{
    /**
     * 事件基础对象
     * @var EventBase
     */
    private $eventBase;
    
    /**
     * 事件集合
     * @var array
     */
    private $events = [];
    
    /**
     * 定时器事件集合
     * @var array
     */
    private $timers = [];
    
    /**
     * 读事件集合
     * @var array
     */
    private $readEvents = [];
    
    /**
     * 写事件集合
     * @var array
     */
    private $writeEvents = [];
    
    /**
     * 是否已停止
     * @var bool
     */
    private $stopped = false;
    
    /**
     * 日志记录器
     * @var Logger|AsyncLogger
     */
    private $logger;
    
    /**
     * 构造函数
     * 
     * @param EventBase $eventBase 事件基础对象
     * @param mixed $logger 日志记录器
     */
    public function __construct($eventBase, $logger)
    {
        $this->eventBase = $eventBase;
        $this->logger = $logger;
    }
    
    /**
     * 添加定时器事件
     * 
     * @param float $interval 间隔时间(秒)
     * @param callable $callback 回调函数
     * @param array $args 回调参数
     * @param bool $isPersistent 是否持久事件
     * @return string 事件ID
     */
    public function addTimer($interval, $callback, $args = [], $isPersistent = true)
    {
        $flags = Event::TIMEOUT;
        if ($isPersistent) {
            $flags |= Event::PERSIST;
        }
        
        $event = new Event(
            $this->eventBase,
            -1,
            $flags,
            function() use ($callback, $args) {
                try {
                    call_user_func_array($callback, $args);
                } catch (\Throwable $e) {
                    $this->logger->log("定时器事件异常: " . $e->getMessage());
                }
            }
        );
        
        $event->add($interval);
        
        $id = spl_object_hash($event);
        $this->events[$id] = $event;
        $this->timers[$id] = [
            'interval' => $interval,
            'callback' => $callback,
            'args' => $args,
            'persistent' => $isPersistent
        ];
        
        return $id;
    }
    
    /**
     * 添加一次性定时器
     * 
     * @param float $delay 延迟时间(秒)
     * @param callable $callback 回调函数
     * @param array $args 回调参数
     * @return string 事件ID
     */
    public function setTimeout($delay, $callback, $args = [])
    {
        return $this->addTimer($delay, $callback, $args, false);
    }
    
    /**
     * 添加读事件
     * 
     * @param resource $socket Socket资源
     * @param callable $callback 回调函数
     * @param array $args 回调参数
     * @return string 事件ID
     */
    public function addReadEvent($socket, $callback, $args = [])
    {
        $event = new Event(
            $this->eventBase,
            $socket,
            Event::READ | Event::PERSIST,
            function($fd, $events) use ($callback, $args) {
                try {
                    call_user_func_array($callback, array_merge([$fd, $events], $args));
                } catch (\Throwable $e) {
                    $this->logger->log("读事件异常: " . $e->getMessage());
                }
            }
        );
        
        $event->add();
        
        $id = spl_object_hash($event);
        $this->events[$id] = $event;
        $this->readEvents[$id] = [
            'socket' => $socket,
            'callback' => $callback,
            'args' => $args
        ];
        
        return $id;
    }
    
    /**
     * 添加写事件
     * 
     * @param resource $socket Socket资源
     * @param callable $callback 回调函数
     * @param array $args 回调参数
     * @return string 事件ID
     */
    public function addWriteEvent($socket, $callback, $args = [])
    {
        $event = new Event(
            $this->eventBase,
            $socket,
            Event::WRITE | Event::PERSIST,
            function($fd, $events) use ($callback, $args) {
                try {
                    call_user_func_array($callback, array_merge([$fd, $events], $args));
                } catch (\Throwable $e) {
                    $this->logger->log("写事件异常: " . $e->getMessage());
                }
            }
        );
        
        $event->add();
        
        $id = spl_object_hash($event);
        $this->events[$id] = $event;
        $this->writeEvents[$id] = [
            'socket' => $socket,
            'callback' => $callback,
            'args' => $args
        ];
        
        return $id;
    }
    
    /**
     * 移除事件
     * 
     * @param string $id 事件ID
     * @return bool 是否成功移除
     */
    public function removeEvent($id)
    {
        if (!isset($this->events[$id])) {
            return false;
        }
        
        $event = $this->events[$id];
        $event->del();
        
        unset($this->events[$id]);
        unset($this->timers[$id]);
        unset($this->readEvents[$id]);
        unset($this->writeEvents[$id]);
        
        return true;
    }
    
    /**
     * 清理指定socket的所有事件
     * 
     * @param resource $socket Socket资源
     * @return int 移除的事件数量
     */
    public function removeSocketEvents($socket)
    {
        $count = 0;
        
        foreach ($this->readEvents as $id => $data) {
            if ($data['socket'] === $socket) {
                $this->removeEvent($id);
                $count++;
            }
        }
        
        foreach ($this->writeEvents as $id => $data) {
            if ($data['socket'] === $socket) {
                $this->removeEvent($id);
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * 开始事件循环
     * 
     * @param int $flags 循环标志
     * @param float $timeout 超时时间(秒)
     * @return bool 是否成功
     */
    public function loop($flags = 0)
    {
        if ($this->stopped) {
            return false;
        }
        
        return $this->eventBase->loop($flags); 
    }
    
    /**
     * 执行单次事件循环
     * 
     * @return bool 是否成功
     */
    public function dispatch()
    {
        if ($this->stopped) {
            return false;
        }
        
        return $this->eventBase->loop(EventBase::LOOP_ONCE | EventBase::LOOP_NONBLOCK);
    }
    
    /**
     * 停止事件循环
     * 
     * @return bool 是否成功
     */
    public function stop()
    {
        $this->stopped = true;
        return $this->eventBase->stop();
    }
    
    /**
     * 重新设置停止标志
     * 
     * @return void
     */
    public function reset()
    {
        $this->stopped = false;
    }
    
    /**
     * 获取事件数量
     * 
     * @return int 事件数量
     */
    public function getEventCount()
    {
        return count($this->events);
    }
    
    /**
     * 获取定时器数量
     * 
     * @return int 定时器数量
     */
    public function getTimerCount()
    {
        return count($this->timers);
    }
    
    /**
     * 获取事件基础对象
     * 
     * @return EventBase 事件基础对象
     */
    public function getEventBase()
    {
        return $this->eventBase;
    }
    
    /**
     * 清理所有事件
     * 
     * @return int 清理的事件数量
     */
    public function clearAllEvents()
    {
        $count = count($this->events);
        
        foreach ($this->events as $id => $event) {
            $event->del();
        }
        
        $this->events = [];
        $this->timers = [];
        $this->readEvents = [];
        $this->writeEvents = [];
        
        return $count;
    }
}