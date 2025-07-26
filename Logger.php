<?php
class Logger
{
    public function log($msg, $file = 'worker.log')
    {
        return;
        $time = date('Y-m-d H:i:s');
        file_put_contents($file, "[{$time}] {$msg}\n", FILE_APPEND);
        echo "[{$time}] {$msg}\n";
    }
}