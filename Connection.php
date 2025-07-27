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

    public function isActive()
    {
        return $this->lastActive > time() - 60; // 60秒内有活动
    }
    public function send($data)
    {
        $this->writeBuffer .= $data;
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