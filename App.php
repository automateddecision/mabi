<?php

namespace MABI;

include_once __DIR__ . '/Slim/Slim.php';
include_once __DIR__ . '/Extension.php';
include_once __DIR__ . '/ErrorResponse.php';
include_once __DIR__ . '/DefaultAppErrors.php';
include_once __DIR__ . '/AnnotationReader.php';

use Illuminate\Cache\ApcStore;
use Illuminate\Cache\ApcWrapper;
use \Slim\Slim;
use Slim\Exception\Stop;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;

Slim::registerAutoloader();

/**
 * todo: docs
 */
class App extends Extension {

  /**
   * @var \Slim\Slim;
   */
  protected $slim;

  /**
   * @var AnnotationReader
   */
  protected $annotationReader;

  /**
   * @var App
   */
  protected static $singletonApp = NULL;

  /**
   * @var ErrorResponseDictionary
   */
  protected $errorResponseDictionary = NULL;

  /**
   * @var \Illuminate\Cache\Repository[]
   */
  protected $cacheRepositories = array();

  /**
   * @return \Slim\Slim
   */
  public function getSlim() {
    return $this->slim;
  }

  /**
   * @return \Slim\Http\Request
   */
  public function getRequest() {
    return $this->slim->request();
  }

  /**
   * @return \Slim\Http\Response
   */
  public function getResponse() {
    return $this->slim->response();
  }

  /**
   * @return \MABI\AnnotationReader
   */
  public function getAnnotationReader() {
    return $this->annotationReader;
  }

  /**
   * @var \MABI\Controller
   */
  protected $activeContoller = NULL;

  /**
   * @return \MABI\ErrorResponseDictionary
   */
  public function getErrorResponseDictionary() {
    return $this->errorResponseDictionary;
  }

  /**
   * todo: docs
   */
  static function getSingletonApp() {
    if (empty(self::$singletonApp)) {
      self::$singletonApp = new App();
    }

    return self::$singletonApp;
  }

  /**
   * @param \MABI\Controller $activeContoller
   */
  public function setActiveContoller($activeContoller) {
    $this->activeContoller = $activeContoller;
  }

  /**
   * @return \MABI\Controller
   */
  public function getActiveContoller() {
    return $this->activeContoller;
  }

  /**
   * todo: docs
   */
  static function clearSingletonApp() {
    self::$singletonApp = NULL;
  }

  public function __construct() {
    if (file_exists(__DIR__ . '/middleware')) {
      array_push($this->middlewareDirectories, __DIR__ . '/middleware');
    }
    $this->slim = new Slim();
    $this->errorResponseDictionary = new DefaultAppErrors();
    $this->annotationReader = new AnnotationReader();
    parent::__construct($this);
  }

  public function setMiddlewareDirectories($middlewareDirectories) {
    parent::setMiddlewareDirectories(array_merge($this->middlewareDirectories, $middlewareDirectories));
  }

  /**
   * todo: docs
   *
   * @param $name    string
   * @param $driver  string
   * @param $config  mixed
   */
  function addCacheRepository($name, $driver, $config) {
    switch (strtolower($driver)) {
      case 'file':
        $cacheStore = new FileStore(new Filesystem(), $config['path']);
        break;
      case 'apc':
        $cacheStore = new ApcStore(new ApcWrapper());
        break;
      // todo: add memcached, etc.
      default:
        return;
    }
    $this->cacheRepositories[$name] = new Repository($cacheStore);
    if($name == 'system') {
      $this->annotationReader->setCacheRepository($this->cacheRepositories[$name]);
    }
  }

  /**
   * @param $name
   *
   * @return \Illuminate\Cache\Repository
   */
  public function getCacheRepository($name) {
    return array_key_exists($name, $this->cacheRepositories) ? $this->cacheRepositories[$name] : null;
  }

  /**
   * Returns a JSON array displaying the error to the client and stops execution
   *
   * Example Error Message Definition:
   * array('ERRORDEF_NO_ACCESS' => array('message' => 'No Access', 'code' => 1007, 'httpcode' => 402));
   *
   * @param $errorKeyOrDefinition string|array
   * @param $replacementArray array
   *
   * @throws \Slim\Exception\Stop
   */
  public function returnError($errorKeyOrDefinition, $replacementArray = array()) {
    if (is_string($errorKeyOrDefinition)) {
      $errorKey = $errorKeyOrDefinition;
    }
    else {
      $errorKeys = array_keys($errorKeyOrDefinition);
      $errorKey = $errorKeys[0];
    }

    $errorResponse = $this->errorResponseDictionary->getErrorResponse($errorKey);
    if (empty($errorResponse)) {
      $errorResponse = ErrorResponse::FromArray($errorKeyOrDefinition[$errorKey]);
    }

    $appCode = $errorResponse->getCode();
    echo json_encode(array(
      'error' => empty($appCode) ? array('message' => $errorResponse->getFormattedMessage($replacementArray)) :
          array('code' => $appCode, 'message' => $errorResponse->getFormattedMessage($replacementArray))
    ));
    $this->getResponse()->status($errorResponse->getHttpcode());
    throw new Stop($errorResponse->getFormattedMessage($replacementArray), $appCode);
  }

  public function errorHandler($e) {
    $this->slim->getLog()->error($e);
    $this->getResponse()->status(500);
    echo json_encode(array(
      'error' => array('code' => 1020, 'message' => 'A system error occurred')
    ));
  }

  public function run() {
    foreach ($this->getControllers() as $controller) {
      $controller->loadRoutes($this->slim);
    }

    if (!$this->slim->config('debug')) {
      $this->slim->error(array($this, 'errorHandler'));
    }

    $this->slim->run();
  }

  public function call() {
    foreach ($this->getControllers() as $controller) {
      $controller->loadRoutes($this->slim);
    }

    if (!$this->slim->config('debug')) {
      $this->slim->error(array($this, 'errorHandler'));
    }

    $this->slim->call();
  }

  public function getIOSModel() {
    $iosModel = IOSModelInterpreter::getIOSDataModel();

    foreach ($this->getModelClasses() as $modelClass) {
      $model = call_user_func($modelClass . '::init', $this);
      IOSModelInterpreter::addModel($iosModel, $model);
    }

    return $iosModel->asXML();
  }
}

