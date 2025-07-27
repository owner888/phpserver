<?php
class Connection
{
    public $socket;
    public $lastActive;
    public $id;
    public $writeBuffer = '';
    public $readBuffer = '';
    public $readEvent = null;
    public $writeEvent = null;
    private $maxBufferSize = 10485760; // 10MB
    private $connectionTimeout = 60;   // 60秒

    public function __construct($socket)
    {
        $this->socket = $socket;
        $this->id = (int)$socket;
        $this->lastActive = time();
    }

    public function updateActive()
    {
        $this->lastActive = time();
    }

    public function isIdle() 
    {
        return empty($this->writeBuffer) && empty($this->readBuffer);
    }
    /**
     * 检查连接是否在最近60秒内有活动
     * @return bool
     */
    public function isActive()
    {
        return $this->lastActive > time() - $this->connectionTimeout; // 60秒内有活动
    }

    /**
     * 向写缓冲区添加数据
     * @param string $data 要发送的数据
     */
    public function send($data)
    {
        // 限制缓冲区大小
        if (strlen($this->writeBuffer) > $this->maxBufferSize) 
        {
            // 缓冲区过大，可能连接有问题
            return false;
        }
        $this->writeBuffer .= $data;
        return true;
    }  

    /**
     * 读取数据到读缓冲区
     */
    public function read()
    {
        $data = '';
        if ($this->socket && is_resource($this->socket)) 
        {
            $data = fread($this->socket, 8192);
            if ($data === false || $data === '') 
            {
                return false; // 读取失败或连接关闭
            }
            $this->readBuffer .= $data;
        }
        return $data;
    }

    /**
     * 尝试将写缓冲区数据写入 socket
     * 在事件回调中定期调用
     */
    public function flush()
    {
        if ($this->socket && is_resource($this->socket) && !empty($this->writeBuffer)) 
        {
            $bytesWritten = fwrite($this->socket, $this->writeBuffer);
            if ($bytesWritten === false) 
            {
                return false; // 写入失败
            }
            $this->writeBuffer = substr($this->writeBuffer, $bytesWritten);
        }
        return true;
    }

    public function isValid()
    {
        return $this->socket && is_resource($this->socket);
    }

    public function reset()
    {
        $this->writeBuffer = '';
        $this->readBuffer = '';
        $this->lastActive = time();
    }

    public function close()
    {
        if ($this->readEvent) 
        {
            $this->readEvent->del();
            $this->readEvent = null;
        }
        if ($this->writeEvent) 
        {
            $this->writeEvent->del();
            $this->writeEvent = null;
        }
        if ($this->socket && is_resource($this->socket)) 
        {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}