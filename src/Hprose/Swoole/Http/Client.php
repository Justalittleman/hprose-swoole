<?php
/**********************************************************\
|                                                          |
|                          hprose                          |
|                                                          |
| Official WebSite: http://www.hprose.com/                 |
|                   http://www.hprose.org/                 |
|                                                          |
\**********************************************************/

/**********************************************************\
 *                                                        *
 * Hprose/Swoole/Http/Client.php                          *
 *                                                        *
 * hprose swoole http client library for php 5.3+         *
 *                                                        *
 * LastModified: Sep 17, 2016                             *
 * Author: Ma Bingyao <andot@hprose.com>                  *
 *                                                        *
\**********************************************************/

namespace Hprose\Swoole\Http;

use stdClass;
use Exception;
use Hprose\Future;
use swoole_http_client;

class Client extends \Hprose\Client {
    public $type;
    public $host = '';
    public $ip = '';
    public $port = 80;
    public $ssl = false;
    public $keepAlive = true;
    public $keepAliveTimeout = 300;
    public $poolTimeout = 30000;
    public $maxPoolSize = 10;
    public $header = array();
    private $trans;
    // private $requests = array();
    public function __construct($uris = null) {
        parent::__construct($uris);
        $this->trans = new Transporter($this);
    }
    public function setHeader($name, $value) {
        $lname = strtolower($name);
        if ($lname != 'content-type' &&
            $lname != 'content-length' &&
            $lname != 'host') {
            if ($value) {
                $this->header[$name] = $value;
            }
            else {
                unset($this->header[$name]);
            }
        }
    }
    public function setKeepAlive($keepAlive = true) {
        $this->keepAlive = $keepAlive;
        $this->header['Connection'] = $keepAlive ? 'keep-alive' : 'close';
        if ($keepAlive) {
            $this->header['Keep-Ailve'] = $this->keepAliveTimeout;
        }
        else {
            unset($this->header['Keep-Ailve']);
        }
    }
    public function isKeepAlive() {
        return $this->keepAlive;
    }
    public function setKeepAliveTimeout($timeout) {
        $this->keepAliveTimeout = $timeout;
        if ($this->keepAlive) {
            $this->header['Keep-Ailve'] = $timeout;
        }
    }
    public function getKeepAliveTimeout() {
        return $this->keepAliveTimeout;
    }
    public function setMaxPoolSize($value) {
        $this->maxPoolSize = $value;
    }
    public function getMaxPoolSize() {
        return $this->maxPoolSize;
    }
    public function setPoolTimeout($value) {
        $this->poolTimeout = $value;
    }
    public function getPoolTimeout() {
        return $this->poolTimeout;
    }
    public function getHost() {
        return $this->host;
    }
    public function getPort() {
        return $this->port;
    }
    public function isSSL() {
        return $this->ssl;
    }
    protected function setUri($uri) {
        parent::setUri($uri);
        $p = parse_url($uri);
        if ($p) {
            switch (strtolower($p['scheme'])) {
                case 'http':
                    $this->host = $p['host'];
                    $this->port = isset($p['port']) ? $p['port'] : 80;
                    $this->path = isset($p['path']) ? $p['path'] : '/';
                    $this->ssl = false;
                    break;
                case 'https':
                    $this->host = $p['host'];
                    $this->port = isset($p['port']) ? $p['port'] : 443;
                    $this->path = isset($p['path']) ? $p['path'] : '/';
                    $this->ssl = true;
                    break;
                default:
                    throw new Exception("Only support http and https scheme");
            }
        }
        else {
            throw new Exception("Can't parse this uri: " . $uri);
        }
        $this->header['Host'] = $this->host;
        $this->header['Connection'] = $this->keepAlive ? 'keep-alive' : 'close';
        if ($this->keepAlive) {
            $this->header['Keep-Ailve'] = $this->keepAliveTimeout;
        }
        if (filter_var($this->host, FILTER_VALIDATE_IP) === false) {
            $ip = gethostbyname($this->host);
            if ($ip === $this->host) {
                throw new Exception('DNS lookup failed');
            }
            else {
                $this->ip = $ip;
            }
        }
        else {
            $this->ip = $this->host;
        }
    }
    protected function wait($interval, $callback) {
        $future = new Future();
        swoole_timer_after($interval * 1000, function() use ($future, $callback) {
            Future\sync($callback)->fill($future);
        });
        return $future;
    }
    /*
        This method is a private method.
        But PHP 5.3 can't call private method in closure,
        so we comment the private keyword.
    */
    // /*private*/ function privateSendAndReceive($request, stdClass $context, Future $future) {
    //     $self = $this;
    //     $requests = &$this->requests;
    //     $count = &$this->count;
    //     if ($count < $this->maxConnection) {
    //         $count++;
    //         $cli = new swoole_http_client($this->ip, $this->port, $this->ssl);
    //         $cli->on('error', function($cli) use ($future) {
    //             $future->reject(new Exception(socket_strerror($cli->errCode)));
    //         });
    //         $cli->setHeaders($this->header);
    //         $cli->setCookies($this->cookies);
    //         $cli->set(array('keep_alive' => $this->keepAlive,
    //                         'timeout' => $context->timeout / 1000));
    //         $cli->post($this->path, $request,
    //         function($cli) use ($self, $future, &$requests, &$count) {
    //             $self->cookies = $cli->cookies;
    //             if ($cli->errCode === 0) {
    //                 if ($cli->statusCode == 200) {
    //                     $future->resolve($cli->body);
    //                 }
    //                 else {
    //                     $future->reject(new Exception($cli->body));
    //                 }
    //             }
    //             else {
    //                 $future->reject(new Exception(socket_strerror($cli->errCode)));
    //             }
    //             $count--;
    //             if (!empty($requests) && $count < $self->maxConnection) {
    //                 $request = array_pop($requests);
    //                 swoole_event_defer(function() use ($self, $request, $cli) {
    //                     $cli->close();
    //                     $self->privateSendAndReceive($request[0], $request[1], $request[2]);
    //                 });
    //             }
    //         });
    //     }
    //     else {
    //         $requests[] = array($request, $context, $future);
    //     }
    // }
    protected function sendAndReceive($request, stdClass $context) {
        $future = new Future();
        $this->trans->sendAndReceive($request, $future, $context);
        if ($context->oneway) {
            $future->resolve(null);
        }
        return $future;
    }
}
