<?php

namespace tourze\swoole\yii2\commands;

use tourze\swoole\yii2\server\HttpServer;
use tourze\swoole\yii2\server\RpcServer;
use Yii;
use yii\console\Controller;
use yii\helpers\ArrayHelper;

class SwooleController extends Controller
{

    /**
     * Run swoole http server
     *
     * @param string $app Running app
     * @throws \yii\base\InvalidConfigException
     */
    public function actionHttp($app)
    {
        /** @var HttpServer $server */
        $server = new HttpServer;
        $server->run($app);
    }
    
    /**
     * Run swoole rpc server
     *
     * @param string $app Running app
     * @throws \yii\base\InvalidConfigException
     */
    public function actionRpc($app=null)
    {
        $config = Yii::$app->params['swooleServer'];
        $baseRoot = ArrayHelper::remove($config, 'baseRoot');
        
        $serviceList = $app? [$app] : $config['rpcList'];

        foreach ($serviceList as $name){
            $conf = require($baseRoot.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR.'config/swoole.service.php');
            $server = new RpcServer;
            $server->run($conf);
        }
    }
}
