<?php
namespace Civi\Cxn\Rpc;

use Civi\Cxn\Rpc\Exception\CxnException;
use Psr\Log\NullLogger;

class RegistrationClient {
  /**
   * @var string
   */
  protected $caCert;

  /**
   * @var CxnStore\CxnStoreInterface
   */
  protected $cxnStore;

  /**
   * @var string
   */
  protected $siteUrl;

  /**
   * @var Http\HttpInterface
   */
  protected $http;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $log;

  /**
   * @param string $caCert
   *   The CA certificate data, or NULL ot disable certificate validation.
   * @param CxnStore\CxnStoreInterface $cxnStore
   *   The place to store active connections.
   */
  public function __construct($caCert, $cxnStore, $siteUrl) {
    $this->caCert = $caCert;
    $this->cxnStore = $cxnStore;
    $this->siteUrl = $siteUrl;
    $this->http = new Http\PhpHttp();
    $this->log = new NullLogger();
  }

  /**
   * @param array $appMeta
   * @return array
   *   Array($cxnId, $isOk).
   */
  public function register($appMeta) {
    AppMeta::validate($appMeta);
    if ($this->caCert) {
      CA::validate($this->caCert, $appMeta['appCert']);
    }

    $cxn = $this->cxnStore->getByAppId($appMeta['appId']);
    if (!$cxn) {
      $cxn = array(
        'cxnId' => Cxn::createId(),
        'secret' => Message\StdMessage::createSecret(),
        'appId' => $appMeta['appId'],
      );
    }
    $cxn['appUrl'] = $appMeta['appUrl'];
    $cxn['siteUrl'] = $this->siteUrl;
    $cxn['perm'] = $appMeta['perm'];
    Cxn::validate($cxn);
    $this->cxnStore->add($cxn);

    list($respCode, $respData) = $this->doCall($appMeta, 'Cxn', 'register', array(), $cxn);
    $success = $respCode == 200 && $respData['is_error'] == 0;
    $this->log->info($success ? 'Registered cxnId={cxnId} ({appId}, {appUrl})' : 'Failed to register cxnId={cxnId} ({appId}, {appUrl})', array(
      'cxnId' => $cxn['cxnId'],
      'appId' => $cxn['appId'],
      'appUrl' => $cxn['appUrl'],
    ));
    return array($cxn['cxnId'], $success);
  }

  /**
   * @param array $appMeta
   * @return array
   *   Array($cxnId, $isOk).
   */
  public function unregister($appMeta) {
    $cxn = $this->cxnStore->getByAppId($appMeta['appId']);
    if (!$cxn) {
      return array(NULL, NULL);
    }

    $this->log->info('Unregister cxnId={cxnId} ({appId}, {appUrl})', array(
      'cxnId' => $cxn['cxnId'],
      'appId' => $cxn['appId'],
      'appUrl' => $cxn['appUrl'],
    ));

    $e = NULL;
    try {
      if ($this->caCert) {
        CA::validate($this->caCert, $appMeta['appCert']);
      }
      list($respCode, $respData) = $this->doCall($appMeta, 'Cxn', 'unregister', array(), $cxn);
    }
    catch (Exception $e2) {
      // simulate try..finally..
      $e = $e2;
    }

    $this->cxnStore->remove($cxn['cxnId']);

    if ($e) {
      throw $e;
    }

    return array($cxn['cxnId'], $respCode == 200 && $respData['is_error'] == 0);
  }

  /**
   * @return Http\HttpInterface
   */
  public function getHttp() {
    return $this->http;
  }

  /**
   * @param Http\HttpInterface $http
   */
  public function setHttp($http) {
    $this->http = $http;
  }

  /**
   * @return \Psr\Log\LoggerInterface
   */
  public function getLog() {
    return $this->log;
  }

  /**
   * @param \Psr\Log\LoggerInterface $log
   */
  public function setLog($log) {
    $this->log = $log;
  }

  /**
   * @param $appMeta
   * @param $entity
   * @param $action
   * @param $params
   * @param $cxn
   * @return array
   * @throws Exception\InvalidMessageException
   */
  protected function doCall($appMeta, $entity, $action, $params, $cxn) {
    $appCert = new \File_X509();
    $appCert->loadX509($appMeta['appCert']);

    $reqCiphertext = Message\RegistrationMessage::encode($cxn['appId'], $appCert->getPublicKey(), array(
      'cxn' => $cxn,
      'entity' => $entity,
      'action' => $action,
      'params' => $params,
    ));
    list($respHeaders, $respCiphertext, $respCode) = $this->http->send('POST', $cxn['appUrl'], $reqCiphertext);
    list ($respCxnId, $respData) = Message\StdMessage::decode($this->cxnStore, $respCiphertext);
    if ($respCxnId != $cxn['cxnId']) {
      // Tsk, tsk, Mallory!
      throw new \RuntimeException('Received response from incorrect connection.');
    }
    return array($respCode, $respData);
  }

}
