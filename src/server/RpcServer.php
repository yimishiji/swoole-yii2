<?php

namespace tourze\swoole\yii2\server;

use swoole_http_request;
use swoole_http_response;
use swoole_http_server;
use swoole_server;
use tourze\swoole\yii2\RpcApplication;
use tourze\swoole\yii2\async\Task;
use tourze\swoole\yii2\Container;
use tourze\swoole\yii2\log\Logger;
use Yii;
use yii\base\ErrorException;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use api\components\utils\YMLog;

/**
 * HTTP服务器
 *
 * @package tourze\swoole\yii2\server
 */
class RpcServer extends HttpServer
{
    
    /**
     * @var array 当前配置文件
     */
    public $config = [];
    
    /**
     * @var string 缺省文件名
     */
    public $indexFile = 'index.php';
    
    /**
     * @var bool 是否开启xhprof调试
     */
    public $xhprofDebug = false;
    
    /**
     * @var bool
     */
    public $debug = false;
    
    /**
     * @var string
     */
    public $root;
    
    /**
     * @var swoole_http_server
     */
    public $server;
    
    /**
     * @var string
     */
    public $sessionKey = 'JSESSIONID';
    
    /**
     * Worker启动时触发
     *
     * @param swoole_http_server $serv
     * @param $worker_id
     */
    public function onWorkerStart($serv , $worker_id)
    {
        // 初始化一些变量, 下面这些变量在进入真实流程时是无效的
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_URI'] = $_SERVER['SCRIPT_NAME'] = '';
        
        $this->setProcessTitle($this->name . ': worker');
        // 关闭Yii2自己实现的异常错误
        defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
        // 每个worker都创建一个独立的app实例
        
        // 加载文件和一些初始化配置
        if (isset($this->config['bootstrapFile']))
        {
            foreach ($this->config['bootstrapFile'] as $file)
            {
                require $file;
            }
        }
        $config = [];
        foreach ($this->config['configFile'] as $file)
        {
            $config = ArrayHelper::merge($config, include $file);
        }
        
        if (isset($this->config['bootstrapRefresh']))
        {
            $config['bootstrapRefresh'] = $this->config['bootstrapRefresh'];
        }
        
        // 为Yii分配一个新的DI容器
        if (isset($this->config['persistClasses']))
        {
            Container::$persistClasses = ArrayHelper::merge(Container::$persistClasses, $this->config['persistClasses']);
            Container::$persistClasses = array_unique(Container::$persistClasses);
        }
        Yii::$container = new Container();
        
        if ( ! isset($config['components']['assetManager']['basePath']))
        {
            $config['components']['assetManager']['basePath'] = $this->root . '/assets';
        }
        $config['aliases']['@webroot'] = $this->root;
        $config['aliases']['@web'] = '/';
        $this->app = RpcApplication::$workerApp = new RpcApplication($config);
        Yii::setLogger(new Logger());
        $this->app->setRootPath($this->root);
        $this->app->setServer($this->server);
        $this->app->prepare();
    }
    
    public function onWorkerStop()
    {
    }
    
    /**
     * 处理异步任务
     *
     * @param swoole_server $serv
     * @param mixed $task_id
     * @param mixed $from_id
     * @param string $data
     */
    public function onTask($serv, $task_id, $from_id, $data)
    {
        //echo "New AsyncTask[id=$task_id]".PHP_EOL;
        //$serv->finish("$data -> OK");
        Task::runTask($data, $task_id);
    }
    
    /**
     * 处理异步任务的结果
     *
     * @param swoole_server $serv
     * @param mixed $task_id
     * @param string $data
     */
    public function onFinish($serv, $task_id, $data)
    {
        //echo "AsyncTask[$task_id] Finish: $data".PHP_EOL;
    }
    
    
    /**
     * 执行请求
     *
     * @param swoole_http_request $request
     * @param swoole_http_response $response
     */
    public function onRequest($request, $response)
    {
        if ($this->xhprofDebug)
        {
            xhprof_enable(XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_CPU);
        }
        
        //gzip
        if (isset($this->config['gzip']) && $this->config['gzip']=='on' && method_exists($response, 'gzip')){
            $gzipLevel =(int)($this->config['gzipLevel']??1);
            $gzipLevel = min(9, max(1, $gzipLevel));
            $response->gzip($gzipLevel);
        }
        
        $uri = $request->server['request_uri'];
        $file = $this->root . $uri;
        if ($uri != '/' && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) != 'php')
        {
            // 非php文件, 最好使用nginx来输出
            $response->header('Content-Type', FileHelper::getMimeTypeByExtension($file));
            $response->header('Content-Length', filesize($file));
            $response->end(file_get_contents($file));
        }
        else
        {
            // 准备环境信息
            // 只要进入PHP的处理流程, 都默认转发给Yii来做处理
            // 这样意味着, web目录下的PHP文件, 不会直接执行
            $file = $this->root . '/' . $this->indexFile;
            //echo $file . "\n";
            
            // 备份当前的环境变量
            $backupServerInfo = $_SERVER;
            
            foreach ($request->header as $k => $v)
            {
                $k = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
                $_SERVER[$k] = $v;
            }
            foreach ($request->server as $k => $v)
            {
                $k = strtoupper(str_replace('-', '_', $k));
                $_SERVER[$k] = $v;
            }
            $_GET = [];
            if (isset($request->get))
            {
                $_GET = http_build_query($request->get);
                parse_str($_GET, $_GET);
            }
            $_POST = [];
            if (isset($request->post))
            {
                $_POST = http_build_query($request->post);
                parse_str($_POST, $_POST);
            }
            $_COOKIE = [];
            if (isset($request->cookie))
            {
                $_COOKIE = http_build_query($request->cookie);
                parse_str($_COOKIE, $_COOKIE);
            }
            
            $_SERVER['REQUEST_URI'] = $request->server['request_uri'];
            if (isset($request->server['query_string']) && $request->server['query_string'])
            {
                $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] . '?' . $request->server['query_string'];
            }
            $_SERVER['SERVER_ADDR'] = '127.0.0.1';
            $_SERVER['SERVER_NAME'] = 'localhost';
            $_SERVER['SCRIPT_FILENAME'] = $file;
            $_SERVER['DOCUMENT_ROOT'] = $this->root;
            $_SERVER['DOCUMENT_URI'] = $_SERVER['SCRIPT_NAME'] = '/' . $this->indexFile;
            
            // 使用clone, 原型模式
            // 所有请求都clone一个原生$app对象
            $this->app->getRequest()->setUrl(null);
            $app = clone $this->app;
            Yii::$app =& $app;
            $app->setServerRequest($request);
            $app->setServerResponse($response);
            $app->setErrorHandler(clone $this->app->getErrorHandler());
            $app->setRequest(clone $this->app->getRequest());
            $app->setResponse(clone $this->app->getResponse());
            //$app->setView(clone $this->app->getView());
            //$app->setSession(clone $this->app->getSession());
            //$app->setUser(clone $this->app->getUser());
            // 部分组件是可以复用的, 所以直接引用
            //$app->setUrlManager($this->app->getUrlManager());
            
            try
            {
                $app->run();
                $app->afterRun();
            }
            catch (ErrorException $e)
            {
                $app->afterRun();
                if ($this->debug)
                {
                    echo (string) $e;
                    echo "\n";
                    $response->end('');
                }
                else
                {
                    $app->getErrorHandler()->handleException($e);
                }
            }
            catch (\Exception $e)
            {
                $app->afterRun();
                if ($this->debug)
                {
                    echo (string) $e;
                    echo "\n";
                    $response->end('');
                }
                else
                {
                    $app->getErrorHandler()->handleException($e);
                }
            }
            // 还原环境变量
            Yii::$app = $this->app;
            unset($app);
            $_SERVER = $backupServerInfo;
        }
        
        //xdebug_stop_trace();
        //xdebug_print_function_stack();
        
        if ($this->xhprofDebug)
        {
            $xhprofData = xhprof_disable();
            $xhprofRuns = new \XHProfRuns_Default();
            $runId = $xhprofRuns->save_run($xhprofData, 'xhprof_test');
            echo "http://127.0.0.1/xhprof/xhprof_html/index.php?run=" . $runId . '&source=xhprof_test'."\n";
        }
    }
    
    
    /**
     * @param swoole_server $server
     */
    public function onServerStart($server)
    {
        swoole_timer_tick(2000, function() {
            echo " swoole_timer_tick output time: ".microtime(true)." \n";
        });
    

    
        echo " rpc servicer 304 serverstart";
        parent::onServerStart($server);
    }
    
    public function onServerStop()
    {
        echo " rpc servicer 310 onServerStop";
        parent::onServerStop();
    }
}
