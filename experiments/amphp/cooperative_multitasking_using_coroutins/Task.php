<?php

/**
 * Class Task
 */
class Task
{
    protected $taskId;
    protected $coroutine;
    protected $sendValue = null;
    protected $beforeFirstYield = true;

    /**
     * Task constructor.
     *
     * @param            $taskId
     * @param \Generator $coroutine
     */
    public function __construct($taskId, Generator $coroutine)
    {
        $this->taskId = $taskId;
        $this->coroutine = $coroutine;
    }

    public function getTaskId()
    {
        return $this->taskId;
    }

    /**
     * @param $sendValue
     */
    public function setSendValue($sendValue)
    {
        $this->sendValue = $sendValue;
    }

    /**
     * @return mixed
     */
    public function run()
    {
        if ($this->beforeFirstYield) {
            $this->beforeFirstYield = false;
            return $this->coroutine->current();
        }
        $retval = $this->coroutine->send($this->sendValue);
        $this->sendValue = null;
        return $retval;
    }

    /**
     * @return bool
     */
    public function isFinished()
    {
        return !$this->coroutine->valid();
    }
}
