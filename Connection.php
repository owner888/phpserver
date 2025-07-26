<?php
class Connection
{
    public $socket;
    public $event;
    public $lastActive;
    public $id;

    public function __construct($socket, $event)
    {
        $this->socket = $socket;
        $this->event = $event;
        $this->id = (int)$socket;
        $this->lastActive = time();
    }

    public function updateActive()
    {
        $this->lastActive = time();
    }

    public function close()
    {
        if ($this->event) {
            $this->event->del();
        }
        if ($this->socket && is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }
}