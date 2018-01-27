<?php

namespace tourze\swoole\yii2\server;

trait ServerTrait
{

    protected   $masterPid;

    /**
     * 服务是否已启动
     *
     * @return bool
     */
    public function isRunning()
    {
        $masterIsLive = false;
        $pFile = $this->pidFile;

        // pid 文件是否存在
        if (file_exists($pFile)) {
            // 文件内容解析
            $pidFile = file_get_contents($pFile);
            $pids = explode(',', $pidFile);

            $this->masterPid = $pids[0];
            //$this->managerPid = $pids[1];

            $masterIsLive = $this->masterPid && @posix_kill($this->masterPid, 0);
        }

        return $masterIsLive;
    }

    /**
     * stop服务
     */
    public function stop()
    {
        $timeout = 60;
        $startTime = time();
        $this->masterPid && posix_kill($this->masterPid, SIGTERM);

        $result = true;
        while (1) {
            $masterIslive = $this->masterPid && posix_kill($this->masterPid, SIGTERM);

            if ($masterIslive) {
                if (time() - $startTime >= $timeout) {
                    $result = false;
                    break;
                }
                usleep(10000);
                continue;
            }

            break;
        }
        return $result;
    }

    /**
     * reload服务
     *
     * @param bool $onlyTask 是否只重载任务
     */
    public function reload($onlyTask = false)
    {
        $signal = $onlyTask ? SIGUSR2 : SIGUSR1;

        return posix_kill($this->masterPid, $signal);
    }
    
}