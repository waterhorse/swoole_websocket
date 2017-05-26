<?php
/*
 * fengjk
 swoole Task运行实例
 Task简介
 Swoole的业务逻辑部分是同步阻塞运行的，如果遇到一些耗时较大的操作，例如访问数据库、广播消息等，就会影响服务器的响应速度。因此Swoole提供了Task功能，将这些耗时操作放到另外的进程去处理，当前进程继续执行后面的逻辑.
 运行Task,需要在swoole服务中配置参数 task_worker_num,即可开启task功能。此外，必须给swoole_server绑定两个回调函数：onTask和onFinish。这两个回调函数分别用于执行Task任务和处理Task任务的返回结果。
*/



class newlife_server
{
    private $serv;

    /**
     * [__construct description]
     * 构造方法中,初始化 $serv 服务
     */
    public function __construct() {
        $this->serv = new swoole_websocket_server("0.0.0.0", 9999);

        //初始化swoole服务
        $this->serv->set(array(
            'reactor_num' => 10,
            'worker_num'  => 1, //目前worker进程只设置一个，如果多个进程，客户端连接会随机分配到不同进程，不同进程之间无法通信
            'daemonize'   => 0, //是否作为守护进程,此配置一般配合log_file使用
            'max_request' => 1000,
            'log_file'    => './swoole.log',    //日志记录文件
            'task_worker_num' => 10             //task进程
        ));

        //设置监听
        $this->serv->on('Start', array($this, 'onStart'));
        $this->serv->on('Connect', array($this, 'onConnect'));
        $this->serv->on("Receive", array($this, 'onReceive'));
        $this->serv->on("Close", array($this, 'onClose'));
        $this->serv->on("Task", array($this, 'onTask'));
        $this->serv->on("Finish", array($this, 'onFinish'));
        $this->serv->on("Open", array($this, 'onOpen'));
        $this->serv->on("Message", array($this, 'onMessage'));


        //开启
        $this->serv->start();
    }
    //swoole开启
    public function onStart($serv) {
        echo SWOOLE_VERSION . " onStart\n";
    }
    //有客户端进行连接会调用该方法
    public function onConnect($serv, $fd) {
        echo $fd."Client Connect.\n";
    }

    public function onReceive($serv, $fd, $from_id, $data) {

    }

    public function onOpen($serv, $request){

    }

    /**
     * @param $serv     swoole对象
     * @param $frame    $frame->fd是客户端对应的一个标识（可看作能找到客户端的一个地址，断线重连fd会变化）
     *                  $frame->data是客户端发送过来的数据
     */
    public function onMessage($serv, $frame){
        //将客户端发送过来的数据进行json转换成对象
        $data = json_decode($frame->data);
        //$serv->fds 不是 $serv对象原有属性，是我自己增加的一个属性用来存储客户端的fd的一个数组
        if(isset($serv->fds)){
            foreach($serv->fds as $k => $v){
                //判断fd与用户自身id  是否都一致，不一致则删除冗余数据（主要考虑有客户端1产生的fd存储起来下线了该fd无效了，而客户端2产生的fd刚好与用户1产生的fd相同）
                if(($v == $frame->fd) && ($k != $data->id)){
                    unset($serv->fds[$k]);

                }
            }
        }
        //$serv->fds 用于存储客户端fd,以用户id做下标存储自己的fd
        $serv->fds[$data->id] = $frame->fd;

        switch($data->do){
            case 'connect':
                //上线存储fd，表示上线以便其他用户能及时找到该用户
                $serv->fds[$data->id] = $frame->fd;
                //记录该用户与谁在聊天，保证聊天唯一性（1 VS 1）
                $serv->talk[$data->id] = $data->toUser;
                break;
            case 'changeToUser':
                //更换聊天对象，保证聊天唯一性（1 VS 1）,目前模拟的是一对一聊天窗口，如果需要一对多聊天窗口则可以 $serv->talk[$data->id] = array('用户1','用户2',...)
                $serv->talk[$data->id] = $data->toUser;
                break;
            case 'send':
                //判断接收者的聊天对象是不是自己，如果不是则不能发送直接入库标记未读，是则发送.(也可以不限制，如果接收者正在聊天的对象不是自己，也可发过去作为未读消息，但是我在这里是限制住)
                if($serv->talk[$data->toUser] == $data->id){
                    //$flag 用来标记该条消息对方是否已读  1已读  0未读
                    //第一步：判断接收者是否已在线，如果在线进行下一步判断，否则直接将消息插入数据库并标记为未读消息
                    if(isset($serv->fds[$data->toUser])){
                        //获取接收者fd
                        $toUser = $serv->fds[$data->toUser];
                        //$serv->connection_info()该方法为swoole内置方法，用于判断fd是否存在或有效（即可用来判断当前fd是否还保持连接），如果还有效则发送消息，无效则不发送标记为未读消息插入数据库
                        if($serv->connection_info($toUser)){
                            echo "TOUSER--->".$toUser."\n";
                            $senddata = array('Error'=>0, 'Msg'=>$data->msg);
                            //$serv->push()发送数据，第一个参数为fd， 第二个为要发送的数据
                            $serv->push($toUser, json_encode($senddata));
                            //标记为已读
                            $flag = 1;
                        }else{
                            //无效fd进行删除
                            unset($serv->fds[$data->toUser]);
                            //标记用户未读
                            $flag = 0;

                        }
                    }else{
                        //标记用户未读
                        $flag = 0;
                    }
                }else{
                    //标记用户未读
                    $flag = 0;
                }


                //不管对方在不在线都要对聊天记录插入数据库进行操作,建议用task进行异步操作进行
                //$serv->task(...);
                break;
            default:
                $senddata = array('Error'=>0, 'Msg'=>'操作无效');
                //无效操作回馈发送者
                $serv->push($frame->fd, json_encode($senddata));
                break;
        }



    }
    public function onClose($serv, $fd) {
        //echo "Client Close.\n";
    }

    public function onTask($serv, $task_id, $from_id, $data) {

        //记录聊天记录...

    }

    public function onFinish($serv,$task_id, $data) {

    }

}

//实例化对象
$obj = new newlife_server();



