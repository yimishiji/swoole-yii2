<?php

namespace tourze\swoole\yii2;

use swoole_http_request;
use swoole_http_response;
use swoole_http_server;
use tourze\swoole\yii2\web\ErrorHandler;
use tourze\swoole\yii2\web\Request;
use tourze\swoole\yii2\web\Response;
use tourze\swoole\yii2\web\Session;
use tourze\swoole\yii2\web\User;
use tourze\swoole\yii2\web\View;
use Yii;
use yii\base\BootstrapInterface;
use yii\base\Controller;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\Widget;

/**
 * @property swoole_http_request  serverRequest
 * @property swoole_http_response serverResponse
 * @property swoole_http_server   server
 * @property string rootPath
 */
class RpcApplication extends Application
{
    
    /**
     * 初始化流程
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function bootstrap()
    {
        if ( ! static::$webAliasInit)
        {
            $request = $this->getRequest();
            Yii::setAlias('@webroot', dirname($request->getScriptFile()));
            Yii::setAlias('@web', $request->getBaseUrl());
            static::$webAliasInit = true;
        }
        
        //$this->extensionBootstrap();
        //$this->moduleBootstrap();
    }
    
    
    /**
     * 预热一些可以浅复制的对象
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function prepare()
    {
        $this->getLog()->setLogger(Yii::getLogger());
        $this->getSecurity();
        $this->getUrlManager();
        $this->getRequest()->setBaseUrl('');
        $this->getRequest()->setScriptUrl('/index.php');
        $this->getRequest()->setScriptFile('/index.php');
        $this->getRequest()->setUrl(null);
        $this->getResponse();
        foreach ($this->getResponse()->formatters as $type => $class)
        {
            $this->getResponse()->formatters[$type] = Yii::createObject($class);
        }
        //$this->getSession();
        //$this->getAssetManager();
        //$this->getView();
        //$this->getDb();
        //$this->getUser();
        //$this->getMailer();
    }
    
    /**
     * 用于收尾
     * 这里因为用了swoole的task,所以性能很低
     */
    public function afterRun()
    {
        Yii::getLogger()->flush();
    }
    
}
