<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 7 2020
 */

namespace MikoPBX\Core\Workers;

use AGI_AsteriskManager;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Util;
use Phalcon\Di;
use Phalcon\Text;

abstract class WorkerBase extends Di\Injectable implements WorkerInterface
{
    protected AGI_AsteriskManager $am;
    protected int $maxProc=0;

    /**
     * Workers shared constructor
     */
    public function __construct()
    {
        $this->checkCountProcesses();
        $this->savePidFile();
    }

    private function checkCountProcesses(): void{
        $activeProcesses = Util::getPidOfProcess(static::class, getmypid());
        if($this->maxProc === 1){
            if(!empty($activeProcesses)){
                $killApp = Util::which('kill');
                // Завершаем старый процесс.
                Util::mwExec("{$killApp} {$activeProcesses}");
            }
        }elseif ($this->maxProc > 1){
            // Лимит процессов может быть превышен. Удаление лишних процессов.
            $processes = explode(' ', $activeProcesses);
            // Получим количество лишних процессов.
            $countProc = count($processes) - $this->maxProc;
            $killApp   = Util::which('kill');
            while ($countProc >= 0){
                if(!isset($processes[$countProc])){
                    break;
                }
                // Завершаем старый процесс.
                Util::mwExec("{$killApp} {$processes[$countProc]}");
                $countProc--;
            }
        }
    }

    private function savePidFile(): void {
        $activeProcesses = Util::getPidOfProcess(static::class);
        file_put_contents($this->getPidFile(), $activeProcesses);
    }

    /**
     * Create PID file for worker
     */
    public function getPidFile(): string
    {
        $name = str_replace("\\", '-', static::class);
        return "/var/run/{$name}.pid";
    }

    /**
     * Ping callback for keep alive check
     *
     * @param BeanstalkClient $message
     */
    public function pingCallBack($message): void
    {
        $message->reply(json_encode($message->getBody() . ':pong'));
    }

    /**
     * Make ping tube from classname and ping word
     *
     * @param string $workerClassName
     *
     * @return string
     */
    public function makePingTubeName(string $workerClassName): string
    {
        return Text::camelize("ping_{$workerClassName}", '\\');
    }

    /**
     * If it was Ping request to check worker, we answer Pong and return True
     * @param $parameters
     *
     * @return bool
     */
    public function replyOnPingRequest($parameters):bool
    {
        $pingTube = $this->makePingTubeName(static::class);
        if ( $pingTube === $parameters['UserEvent']) {
            $this->am->UserEvent("{$pingTube}Pong", []);
            return true;
        }
        return false;
    }
}