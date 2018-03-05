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

    protected function getQemuGuestAgentCommand($node, $vmid, $command)
    {
        $url = sprintf("/nodes/%s/qemu/%s/agent", $node, $vmid);
        $body = "command=" . urlencode($command);
        $data = $this->post($url, $body);

        if (isset($data['result'])) {
            return $data['result'];
        } else {
            return [];
        }
    }

    public function hasQEMUGuestAgent($node, $vmid)
    {
        return count($this->getQemuGuestAgentCommand($node, $vmid, "info")) > 0;
    }

    public function getHAFlag($node, $type, $vmid)
    {
        $url = sprintf("/nodes/%s/%s/%s/status/current", $node, $type, $vmid);
        $result = $this->get($url);

        return ($result['ha']['managed'] === 1);
    }

    public function getVMs($guestAgent = false, $fetchNetwork = false)
    {
        foreach ($this->get("/cluster/resources?type=vm") as $el) {
            // filter VM templates
            if ($el['template'] === 1) {
                continue;
            }

            $vm = [
                "vmid" => $el['vmid'],
                "hostsystem" => $el['node'],
                "name" => $el['name'],
                "vm_type" => $el['type'],
                "cpu_count" => (int)$el['maxcpu'],
                "memory_size" => (int)$el['maxmem'] / 1048576, // 1024 * 1024
            ];

            $vm['pool'] = isset($el['pool']) ? $el['pool'] : '';
            $vm['ha_flag'] = $this->getHAFlag($el['node'], $el['type'], $el['vmid']);


            // initialize guest agent variable
            if ($guestAgent) {
                $vm['guest_agent'] = false;
            }

            $interfaces = [];
            switch ($el['type']) {
                case "qemu":
                    if ($guestAgent) {
                        $hasAgent = $this->hasQEMUGuestAgent($el['node'], $el['vmid']);

                        if ($hasAgent && $fetchNetwork) {
                            $network = $this->getQemuGuestAgentCommand($el['node'], $el['vmid'],
                                'network-get-interfaces');


                            foreach ($network as $row) {
                                // skip loopback interface
                                if (preg_match('/^lo.*/', $row['name'])) {
                                    continue;
                                }

                                if (array_key_exists('ip-addresses', $row)) {
                                    $ipv4 = [];
                                    $ipv6 = [];
                                    foreach ($row['ip-addresses'] as $ip) {
                                        if ($ip['ip-address-type'] === 'ipv4') {
                                            $ipv4[] = sprintf("%s/%s", $ip['ip-address'], $ip['prefix']);
                                        } else {
                                            $ipv6[] = sprintf("%s/%s", $ip['ip-address'], $ip['prefix']);
                                        }
                                    }
                                }

                                $interfaces[$row['name']] = [
                                    'hwaddr' => $row['hardware-address'],
                                    'ipv4' => $ipv4,
                                    'ipv6' => $ipv6
                                ];
                            }
                        }

                        $vm['guest_agent'] = $hasAgent;
                    }
                    break;
                case "lxc":
                    $url = sprintf("/nodes/%s/%s/config", $el['node'], $el['id']);

                    if ($fetchNetwork) {
                        // get network interfaces
                        foreach ($this->get($url) as $key => $val) {
                            if (preg_match('/^net.*/', $key)) {
                                $interface = ['ip' => [], 'ip6' => []];

                                // @todo: better way of doing this?
                                foreach (explode(',', $val) as $part) {
                                    $elem = explode('=', $part);

                                    // ignore interfaces with dynamic configuration
                                    if (in_array($elem['0'],
                                            ['ip', 'ip6']) && ($elem[1] === 'dhcp' || $elem[1] === 'auto')) {
                                        continue;
                                    }

                                    $interface[$elem[0]] = $elem[1];
                                }

                                // filter empty interfaces (eg. dynamic)
                                if (empty($interface['ip']) && empty($interface['ip6'])) {
                                    continue;
                                }

                                $interfaces[$interface['name']] = [
                                    'hwaddr' => $interface['hwaddr'],
                                    'ipv4' => $interface['ip'],
                                    'ipv6' => $interface['ip6'],
                                ];
                            }
                        }
                    }
                    break;
            }

            if ($fetchNetwork) {
                $vm['network_configuration'] = $interfaces;
            }

            $vms[] = (object)$vm;
        }

        return $vms;
    }

    public function getNodes()
    {
        $nodes = [];

        foreach ($this->get("/nodes") as $el) {
            $node = [
                "name" => $el['node'],
                "cpu_count" => (int)$el['maxcpu'],
                "memory_size" => (int)$el['maxmem'] / 1024 / 1024 / 1024,
            ];

            $nodes[] = (object)$node;
        }

        return $nodes;
    }

    public function getStorage($fetchDetails=false)
    {
        $storageList = [];

        foreach ($this->get("/cluster/resources/?type=storage") as $el) {
            $storage = [
                "name" => $el["id"],
                "storage" => $el["storage"],
                "node" => $el["node"],
                "storage_size" => (int)$el['maxdisk'] / 1024 / 1024 / 1024,
                "offline" => ($el["status"] !== 'available')
            ];

            /**
             * @TODO: implement
             */
            if($fetchDetails) {
                //$details = $this->getStorageDetails($storage["name"]);
            }

            $storageList[] = (object)$storage;
        }

        return $storageList;
    }

    protected function get($url, $body = array())
    {
        return $this->request("get", $url, $body);
    }

    protected function post($url, $body = array())
    {
        $headers = ["CSRFPreventionToken" => $this->loginTicket['CSRFPreventionToken']];

        return $this->request("post", $url, $body, $headers);
    }

    protected function request($method, $url, $body = array(), $header = array())
    {
        if (!$this->hasValidTicket()) {
            return;
        }

        $url = $this->makeLocation() . $url;
        switch ($method) {
            case "get":
                return $this->curl()->get($url, $body)['data'];
                break;
            case "post":
                return $this->curl()->post($url, $body, $header)['data'];
                break;
            default:
                return [];
        }
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
