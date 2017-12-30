<?php

namespace Icinga\Module\Pve;

use Icinga\Exception\ConfigurationError;

/**
 * Class Api
 *
 * This is your main entry point when working with this library
 */
class Api
{
    /** @var CurlLoader */
    private $curl;

    /** @var string */
    private $host;

    /** @var integer */
    private $port;

    /** @var string */
    private $realm;

    /** @var string */
    private $user;

    /** @var string */
    private $pass;

    /** @var int */
    private $loginTimestamp = 0;

    /** @var array */
    private $loginTicket;

    /**
     * Api constructor.
     *
     * @param string $host
     * @param integer $port
     * @param string $user
     * @param string $realm
     * @param string $pass
     */
    public function __construct($host, $port, $realm, $user, $pass)
    {
        $this->host = $host;
        $this->port = $port;
        $this->realm = $realm;
        $this->user = $user;
        $this->pass = $pass;
    }

    /**
     * @return CurlLoader
     */
    public function curl()
    {
        if ($this->curl === null) {
            $this->curl = new CurlLoader($this->host(), $this->port());
        }

        return $this->curl;
    }

    /**
     * @return string
     */
    protected function host()
    {
        return $this->host;
    }

    /**
     * @return string
     */
    protected function port()
    {
        return $this->port;
    }

    /**
     * Builds our base url
     *
     * @return string
     */
    protected function makeLocation()
    {
        return sprintf("https://%s:%s/api2/json", $this->host(), $this->port());
    }

    /**
     * Checks if we have a valid ticket for authentication and if it is still valid
     *
     * @return bool
     */
    protected function hasValidTicket()
    {
        if ($this->loginTicket == null || $this->loginTimestamp >= (time() + 7200)) {
            $this->loginTimestamp = null;
            $this->curl()->forgetCookie();
            return false;
        } else {
            return true;
        }
    }

    public function getVMs()
    {
        $nodes = [];

        foreach ($this->get("/cluster/resources?type=vm") as $el) {
            // filter VM templates
            if ($el['template'] === 1) {
                continue;
            }

            $node = [
                "vmid" => $el['vmid'],
                "host" => $el['node'],
                "name" => $el['name'],
                "pool" => $el['pool'],
                "type" => $el['type'],
                "cpu" => (int)$el['maxcpu'],
                "memory" => (int)$el['maxmem'],
            ];

            $nodes[] = (object)$node;
        }

        return $nodes;
    }

    public function getNodes()
    {
        $nodes = [];

        foreach ($this->get("/nodes") as $el) {
            $node = [
                "name" => $el['node'],
                "cpu" => (int)$el['maxcpu'],
                "memory" => (int)$el['maxmem']
            ];

            $nodes[] = (object)$node;
        }

        return $nodes;
    }

    protected function get($url, $body = array())
    {
        if (!$this->hasValidTicket()) {
            return;
        }

        $url = $this->makeLocation() . $url;
        return $this->curl()->get($url, $body)['data'];
    }

    /**
     * Log in to to API
     *
     * This will retrieve a session ticket and pass it with subsequent requests
     */
    public function login()
    {
        if ($this->hasValidTicket()) {
            return;
        }

        $body = http_build_query([
            "realm" => $this->realm,
            "username" => $this->user,
            "password" => $this->pass
        ]);

        $url = $this->makeLocation() . "/access/ticket";
        $result = $this->curl()->post($url, $body);
        unset($body);

        if (isset($result['data'])) {
            $this->loginTimestamp = time();
            $this->loginTicket = $result['data'];

            $this->curl()->addCookie("PVEAuthCookie", $this->loginTicket['ticket']);
        }
    }

    /**
     * Logout, destroy our session
     */
    public function logout()
    {
        $this->loginTimestamp = null;
        $this->loginTicket = null;
        $this->curl()->forgetCookie();
    }
}
