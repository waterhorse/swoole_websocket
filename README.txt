运行环境：
Linux 下安装PHP环境及swoole扩展
运行websocket_swoole.php脚本
1.html 、 2.html 、 3.html 分别代表三个用户 隔壁老王、张三、李四
打开三个页面 选择接收者输入消息点击发送

这是一个非常简陋的一对一聊天，希望对大家有所帮助
代码里有注释，整个流程就不说了
大家看完整个代码之后，我觉得对于swoole的websocket有几点想对大家说的：
1、如果swoole设置了多个worker进程，客户端连接时fd会随机分配到不同的worker进程，不同进程之间fd不能互通，所以我目前只设置了一个worker进程保证fd都在一个进程里，如果仍然想要设置多个进程的，我觉得swoole_server->bind (文档里有介绍) 这个方法也许能解决多个进程的问题，但是我没怎么研究过只是猜测
2、这里聊天的用户都是在同一张信息表里的，如果要实现不同身份的用户聊天（即用户与客服也许不在一张信息表里），这时就要在发送消息时多一个身份识别的标识了，并且swoole那边存储fd的数组需要分开存储了(避免id冲突)
3、如果要是实现的不是一对一聊天的话，单纯是要实现群发给所有在线用户消息的时候，建议用这个swoole_server->connection_list 该方法是swoole自己存储的所有fd，只有遍历该方法里的所有fd进行发消息就实现了群发消息


这是我自己在做聊天功能的时候一些总结，逻辑可能不够严谨，只是分享给大家一起来优化。
