<?php

/**
 * Proxy future that implements OAuth1 request signing. For references, see:
 *
 *  RFC 5849: http://tools.ietf.org/html/rfc5849
 *  Guzzle: https://github.com/guzzle/guzzle/blob/master/src/Guzzle/Plugin/Oauth/OauthPlugin.php
 *
 */
final class PhutilOAuth1Future extends FutureProxy {

  private $uri;
  private $data;
  private $consumerKey;
  private $consumerSecret;
  private $signatureMethod;
  private $privateKey;
  private $method = 'POST';
  private $token;
  private $tokenSecret;
  private $nonce;
  private $timestamp;
  private $realm;
  private $hasConstructedFuture;

  public function setTimestamp($timestamp) {
    $this->timestamp = $timestamp;
    return $this;
  }

  public function setNonce($nonce) {
    $this->nonce = $nonce;
    return $this;
  }

  public function setTokenSecret($token_secret) {
    $this->tokenSecret = $token_secret;
    return $this;
  }

  public function setToken($token) {
    $this->token = $token;
    return $this;
  }

  public function setPrivateKey(PhutilOpaqueEnvelope $private_key) {
    $this->privateKey = $private_key;
    return $this;
  }

  public function setSignatureMethod($signature_method) {
    $this->signatureMethod = $signature_method;
    return $this;
  }

  public function setConsumerKey($consumer_key) {
    $this->consumerKey = $consumer_key;
    return $this;
  }

  public function setConsumerSecret($consumer_secret) {
    $this->consumerSecret = $consumer_secret;
    return $this;
  }

  public function setMethod($method) {
    $this->method = $method;
    return $this;
  }

  public function __construct($uri, $data = array()) {
    $this->uri = new PhutilURI((string)$uri);
    $this->data = $data;
    $this->setProxiedFuture(new HTTPSFuture($uri, $data));
  }

  public function getSignature() {
    $params = $this->data
            + $this->uri->getQueryParams()
            + $this->getOAuth1Headers();

    return $this->sign($params);
  }

  public function getProxiedFuture() {
    $future = parent::getProxiedFuture();

    if (!$this->hasConstructedFuture) {
      $future->setMethod($this->method);

      $oauth_headers = $this->getOAuth1Headers();
      $oauth_headers['oauth_signature'] = $this->getSignature();

      $full_oauth_header = array();
      foreach ($oauth_headers as $header => $value) {
        $full_oauth_header[] = $header.'="'.urlencode($value).'"';
      }
      $full_oauth_header = 'OAuth '.implode(", ", $full_oauth_header);

      $future->addHeader('Authorization', $full_oauth_header);

      $this->hasConstructedFuture = true;
    }

    return $future;
  }

  protected function didReceiveResult($result) {
    return $result;
  }

  private function getOAuth1Headers() {
    if (!$this->nonce) {
      $this->nonce = Filesystem::readRandomCharacters(32);
    }
    if (!$this->timestamp) {
      $this->timestamp = time();
    }

    $oauth_headers = array(
      'oauth_consumer_key' => $this->consumerKey,
      'oauth_signature_method' => $this->signatureMethod,
      'oauth_timestamp' => $this->timestamp,
      'oauth_nonce' => $this->nonce,
      'oauth_version' => '1.0',
    );

    if ($this->token) {
      $oauth_headers['oauth_token'] = $this->token;
    }

    return $oauth_headers;
  }

  private function sign(array $params) {
    ksort($params);

    $pstr = array();
    foreach ($params as $key => $value) {
      $pstr[] = rawurlencode($key).'='.rawurlencode($value);
    }
    $pstr = implode('&', $pstr);

    $sign_uri = clone $this->uri;
    $sign_uri->setFragment('');
    $sign_uri->setQueryParams(array());

    $sign_uri->setProtocol(phutil_utf8_strtolower($sign_uri->getProtocol()));
    $protocol = $sign_uri->getProtocol();
    switch ($protocol) {
      case 'http':
        if ($sign_uri->getPort() == 80) {
          $sign_uri->setPort(null);
        }
        break;
      case 'https':
        if ($sign_uri->getPort() == 443) {
          $sign_uri->setPort(null);
        }
        break;
    }

    $method = rawurlencode(phutil_utf8_strtoupper($this->method));
    $sign_uri = rawurlencode((string)$sign_uri);
    $pstr = rawurlencode($pstr);

    $sign_input = "{$method}&{$sign_uri}&{$pstr}";

    return $this->signString($sign_input);
  }

  private function signString($string) {
    $key = urlencode($this->consumerSecret).'&'.urlencode($this->tokenSecret);

    switch ($this->signatureMethod) {
      case 'HMAC-SHA1':
        if (!$this->consumerSecret) {
          throw new Exception(
            "Signature method 'HMAC-SHA1' requires setConsumerSecret()!");
        }

        $hash = hash_hmac('sha1', $string, $key, true);
        return base64_encode($hash);
      case 'RSA-SHA1':
        if (!$this->privateKey) {
          throw new Exception(
            "Signature method 'RSA-SHA1' requires setPrivateKey()!");
        }

        $cert = @openssl_pkey_get_private($this->privateKey->openEnvelope());
        if (!$cert) {
          throw new Exception('openssl_pkey_get_private() failed!');
        }

        $pkey = @openssl_get_privatekey($cert);
        if (!$pkey) {
          throw new Exception('openssl_get_privatekey() failed!');
        }

        $signature = null;
        $ok = openssl_sign($string, $signature, $pkey, OPENSSL_ALGO_SHA1);
        if (!$ok) {
          throw new Exception('openssl_sign() failed!');
        }

        openssl_free_key($pkey);

        return base64_encode($signature);
      case 'PLAINTEXT':
        if (!$this->consumerSecret) {
          throw new Exception(
            "Signature method 'PLAINTEXT' requires setConsumerSecret()!");
        }
        return $key;
      default:
        throw new Exception("Unknown signature method '{$string}'!");
    }
  }

}