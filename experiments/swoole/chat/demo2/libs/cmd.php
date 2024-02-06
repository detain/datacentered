<?php
/**
 * 命令id
 *
 * @author zhang
 *
 */

class CMD
{
    
    /**
     * system
     * @var number
     */
    public const CMD_SYSTEM = 1000;
    

    /**
     * login
     * @var number
     */
    public const CMD_LOGIN = 1001;

    /**
     * reg
     * @var number
     */
    public const CMD_REGISTER = 1002;

    /**
     * talk
     * @var number
     */
    public const CMD_TALK = 2001;
        
    /**
     * to all
     * @var number
     */
    public const CMD_TALK_ALL = 2002;

    /**
     * task
     * @var number
     */
    public const CMD_TASK = 3000;
    
    /**
     * online
     * @var number
     */
    public const CMD_ONLINE = 4001;
    
    /**
     * 命令对应的service配置
     *
     * @var type
     */
    public static $config = [
        
        1000 => [
            'service' => 'system',
            'action'  => 'index',
        ],
        
        1001 => [
            'service' => 'user',
            'action'  => 'login',
        ],
        
        1002 => [
            'service' => 'user',
            'action'  => 'register',
        ],
        
        2001 => [
            'service' => 'talk',
            'action'  => 'one',
        ],
        
        2002 => [
            'service' => 'talk',
            'action'  => 'all',
        ],
        
        
        4001 => [
            'service' => 'system',
            'action'  => 'online',
        ],
        
    ];
}
