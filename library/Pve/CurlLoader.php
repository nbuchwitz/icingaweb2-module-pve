<?php

namespace Icinga\Module\Pve;

use Exception;

/**
 * Class CurlLoader
 *
 * This class fires all our SOAP requests and fetches WSDL files. This has been
 * implemented for debug reasons and to be able to work with various kinds of
 * HTTP or SOCKS proxies
 */
class CurlLoader
{
    /** @var resource */
    private $curl;

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var string */
    private $user;

    /** @var string */
    private $pass;

    /** @var bool */
    private $verifySslPeer = true;

    /** @var bool */
    private $verifySslHost = true;

    /** @var bool */
    private $persistCookies = false;

    /** @var string */
    private $cookieFile;

    /** @var array */
    private $cookies = array();

    /**
     * CurlLoader constructor.
     *
     * Please note that only the host and port is required.
     *
     * @param string $host
     * @param integer $port
     * @param string $user
     * @param string $pass
     */
    public function __construct($host, $port, $user = null, $pass = null)
    {
        if ($this->persistCookies) {
            $this->cookieFile = sprintf("/tmp/pve/cookie-%s", $host);
            if (file_exists($this->cookieFile)) {
                $this->cookies[] = file_get_contents($this->cookieFile);
            }
        }
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
    }

    /**
     * @param bool $disable
     * @return $this
     */
    public function disableSslPeerVerification($disable = true)
    {
        $this->verifySslPeer = !$disable;
        return $this;
    }

    /**
     * @param bool $disable
     * @return $this
     */
    public function disableSslHostVerification($disable = true)
    {
        $this->verifySslHost = !$disable;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasCookie()
    {
        return !empty($this->cookies);
    }

    /**
     * Discard our Cookie
     */
    public function forgetCookie()
    {
        $this->cookies = array();
        if ($this->persistCookies) {
            unlink($this->cookieFile);
        }
    }

    /**
     * @param $url
     * @return string
     */
    public function url($url)
    {
        return sprintf('https://%s:%d/%s', $this->host, $this->port, $url);
    }

    /**
     * @param $url
     * @param null $body
     * @return mixed
     */
    public function get($url, $body = null, $headers = array())
    {
        return $this->request('get', $url, $body, $headers);
    }

    /**
     * @param $url
     * @param null $body
     * @param array $headers
     * @return mixed
     */
    public function post($url, $body = null, $headers = array())
    {
        return $this->request('post', $url, $body, $headers);
    }

    /**
     * @param $method
     * @param $url
     * @param null $body
     * @param array $headers
     * @return mixed
     * @throws Exception
     */
    protected function request($method, $url, $body = null, $headers = array())
    {
        $sendHeaders = array('Host: ' . $this->host);
        foreach ($this->cookies as $cookie) {
            $sendHeaders[] = 'Cookie: ' . $cookie;
        }
        foreach ($headers as $key => $val) {
            $sendHeaders[] = "$key: $val";
        }

        $this->debugRequest($method, $url, $sendHeaders, $body);
        $curl = $this->curl();
        $opts = array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $sendHeaders,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => $this->verifySslPeer,
            CURLOPT_SSL_VERIFYHOST => $this->verifySslHost ? 2 : 0,
            CURLOPT_HEADERFUNCTION => array($this, 'processHeaderLine'),
        );

        if ($this->user !== null) {
            $opts[CURLOPT_USERPWD] = sprintf('%s:%s', $this->user, $this->pass);
        }

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($curl, $opts);

        $res = curl_exec($curl);
        if ($res === false) {
            throw new Exception('CURL ERROR: ' . curl_error($curl));
        }

        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($statusCode === 401) {
            throw new Exception(
                'Unable to authenticate, please check your API credentials'
            );
        } elseif ($statusCode === 500) {
            // do nothing
        } elseif ($statusCode >= 400) {
            throw new Exception(
                "Got $statusCode: $url " . var_export($res, 1)
            );
        }

        return json_decode($res, true);
    }

    public function addCookie($name, $value)
    {
        $this->cookies[] = sprintf("%s=%s", $name, $value);
    }

    /**
     * @param $method
     * @param $url
     * @param $headers
     * @param $body
     */
    protected function debugRequest($method, $url, $headers, $body)
    {
        if (true) {
            return;
        }

        // Testing:
        echo "--> Sending Reqest\n";
        printf("%s %s\n", $method, $url);
        echo implode("\n", $headers);
        echo "\n\n";
        print_r($body);
        echo "\n\n--\n";
    }

    /**
     * Internal callback method, should not be used directly
     *
     * Returns the number of processed bytes and handles eventual Cookies
     *
     * @param $curl
     * @param $header
     * @return int
     */
    public function processHeaderLine($curl, $header)
    {
        $len = strlen($header);
        $header = explode(':', $header, 2);

        if (count($header) < 2) {
            return $len;
        }

        if ($header[0] === 'Set-Cookie') {
            $cookie = trim($header[1]);
            if ($this->persistCookies) {
                file_put_contents($this->cookieFile, $cookie);
            }
            $this->cookies[] = $cookie;
        }

        return $len;
    }

    /**
     * @throws Exception
     *
     * @return resource
     */
    protected function curl()
    {
        if ($this->curl === null) {
            $this->curl = curl_init(sprintf('https://%s:%d', $this->host, $this->port));
            if (!$this->curl) {
                throw new Exception('CURL INIT ERROR: ' . curl_error($this->curl));
            }
        }

        return $this->curl;
    }
}
