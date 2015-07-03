<?php
namespace Civi\Cxn\Rpc;

use Civi\Cxn\Rpc\Exception\GarbledMessageException;
use Civi\Cxn\Rpc\Exception\InvalidMessageException;
use Civi\Cxn\Rpc\Message\GarbledMessage;
use Civi\Cxn\Rpc\Message\StdMessage;

class ApiClient extends Agent {
  /**
   * @var array
   */
  protected $appMeta;

  /**
   * @var string
   */
  protected $cxnId;

  /**
   * @var Http\HttpInterface
   */
  protected $http;

  /**
   * @param array $appMeta
   * @param CxnStore\CxnStoreInterface $cxnStore
   */
  public function __construct($appMeta, $cxnStore, $cxnId) {
    parent::__construct(NULL, $cxnStore);
    $this->appMeta = $appMeta;
    $this->cxnId = $cxnId;
    $this->http = new Http\PhpHttp();
  }

  /**
   * @param string $entity
   * @param string $action
   * @param array $params
   * @throws GarbledMessageException
   * @throws InvalidMessageException
   * @return mixed
   */
  public function call($entity, $action, $params) {
    $this->log->debug("Send API call: {entity}.{action} over {cxnId}", array(
      'entity' => $entity,
      'action' => $action,
      'cxnId' => $this->cxnId,
    ));
    $cxn = $this->cxnStore->getByCxnId($this->cxnId);
    $req = new StdMessage($cxn['cxnId'], $cxn['secret'],
      array($entity, $action, $params));
    list($respHeaders, $respCiphertext, $respCode) = $this->http->send('POST', $cxn['siteUrl'], $req->encode(), array(
      'Content-type' => Constants::MIME_TYPE,
    ));
    $respMessage = $this->decode(array(StdMessage::NAME, GarbledMessage::NAME), $respCiphertext);
    if ($respMessage instanceof GarbledMessage) {
      throw new GarbledMessageException($respMessage);
    }
    elseif ($respMessage instanceof StdMessage) {
      if ($respMessage->getCxnId() != $cxn['cxnId']) {
        // Tsk, tsk, Mallory!
        throw new InvalidMessageException('Received response from incorrect connection.');
      }
      return $respMessage->getData();
    }
    else {
      throw new InvalidMessageException('Unrecognized message type.');
    }
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

}
