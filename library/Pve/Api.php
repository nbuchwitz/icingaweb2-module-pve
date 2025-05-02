<?php

namespace Icinga\Module\Pve;

/**
 * Proxmox VE API class
 *
 * Encapsulates the API communication with a Proxmox VE api endpoints
 * and provides functions for the import sources.
 *
 * @package Pve
 * @author  Nicolai Buchwitz <nb@tipi-net.de>
 */
class Api
{
    /**
     * @var CurlLoader curl loader instance for API calls.
     */
    private $_curl;

    /**
     * @var string hostname of the API server
     */
    private $_host;

    /**
     * @var integer port of the API server
     */
    private $_port;

    /**
     * @var string authentification realm
     */
    private $_realm;

    /**
     * @var string username
     */
    private $_user;

    /**
     * @var int timestamp of last login
     */
    private $_loginTimestamp = 0;

    /**
     * @var array login ticket
     */
    private $_loginTicket;

    /**
     * @var string api token for login
     */
    private $_loginToken;

    /**
     * Api constructor.
     *
     * @param string  $host
     * @param integer $port
     * @param string  $realm
     * @param string  $user
     */
    public function __construct($host, $port, $realm, $user)
    {
        $this->_host = $host;
        $this->_port = $port;
        $this->_realm = $realm;
        $this->_user = $user;
    }

    /**
     * @return CurlLoader
     */
    public function curl()
    {
        if ($this->_curl === null) {
            $this->_curl = new CurlLoader($this->host(), $this->port());
        }

        return $this->_curl;
    }

    /**
     * @return string hostname
     */
    protected function host()
    {
        return $this->_host;
    }

    /**
     * @return string api port
     */
    protected function port()
    {
        return $this->_port;
    }

    /**
     * Builds our base url
     *
     * @return string complete api url
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
        if ($this->_loginTicket == null || $this->_loginTimestamp >= (time() + 7200)) {
            $this->_loginTimestamp = null;
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

        if (isset($data['result']) && !isset($data['result']['error'])) {
            return $data['result'];
        } else {
            return [];
        }
    }

    public function hasQEMUGuestAgent($node, $vmid)
    {
        try {
            return count($this->getQemuGuestAgentCommand($node, $vmid, "info")) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getStorages($node = null)
    {
        $storages = [];

        $data = $this->get("/storage");
        foreach ($data as $storage) {
            if ($storage["disable"] ?? 0 == 1) {
                continue;
            }

            if ($node) {
                $storageNodes = explode(",", $storage["nodes"] ?? []);
                if (!in_array($node, $storageNodes)) {
                    continue;
                }
            }

            $storages[] = (object) [
                "storage_id" => $storage["storage"],
                "content" => $storage["content"],
                "shared" => (int) ($storage["shared"] ?? 0) === 1,
                "nodes" => $storage["nodes"] ?? "",
                "type" => $storage["type"],
            ];

        }

        return $storages;
    }

    private function getPoolDetails($id)
    {
        $data = $this->get("/pools/" . $id);

        return $data ?? [];
    }

    public function getPools($fetchDetails = true)
    {
        $pools = [];
        foreach ($this->get("/pools") as $pl) {
            $pool = ['pool_id' => $pl['poolid']];

            if ($fetchDetails) {
                $details = $this->getPoolDetails($pl['poolid']);
                $pool["comment"] = $details["comment"] ?? "";
            }

            $pools[] = (object) $pool;
        }
        return $pools;
    }

    private function getVmConfigData($host, $vmid)
    {
        $url = sprintf("/nodes/%s/%s/config", $host, $vmid);
        $config = $this->get($url);

        return array(
            "description" => trim(stripslashes($config['description'] ?? "")),
            "os_type" => trim(stripslashes($config['ostype'] ?? "")),
        );
    }

    public function getVMs($guestAgent = false, $config = false, $ha = false)
    {
        $vms = [];

        foreach ($this->get("/cluster/resources?type=vm") as $el) {
            // filter VM templates
            if ($el['template'] === 1) {
                continue;
            }

            $vm = [
                "vm_id" => $el['vmid'],
                "vm_host" => $el['node'],
                "vm_name" => $el['name'],
                "vm_type" => $el['type'],
                "hardware_cpu" => (int) $el['maxcpu'],
                "hardware_memory" => (int) $el['maxmem'] / 1024 * 1024,
                "tags" => $el['tags'] ?? "",
            ];

            if (isset($el['pool'])) {
                $vm['vm_pool'] = $el['pool'];
            }

            // initialize guest agent variable
            if ($guestAgent) {
                $vm['guest_agent'] = false;
            }

            if ($ha) {
                // fetch HA state if enabled in configuration
                $url = sprintf("/nodes/%s/%s/status/current", $el['node'], $el['id']);
                $status = $this->get($url);
                $vm['vm_ha'] = $status['ha']['managed'] === 1;
            }

            $interfaces = [];
            switch ($el['type']) {
            case "qemu":
                if ($config) {
                    $vm = $vm + $this->getVmConfigData($el['node'], $el['id']);
                }

                if ($guestAgent) {
                    $hasAgent = $this->hasQEMUGuestAgent($el['node'], $el['vmid']);

                    if ($hasAgent) {
                        $network = $this->getQemuGuestAgentCommand(
                            $vm['vm_host'],
                            $vm['vm_id'],
                            'network-get-interfaces'
                        );


                        foreach ($network as $row) {
                            // skip loopback interface
                            if (preg_match('/^(lo|Loopback).*/', $row['name'])) {
                                continue;
                            }

                            $ipv4 = [];
                            $ipv6 = [];
                            $ips = $row['ip-addresses'] ?? array();
                            foreach ($ips as $ip) {
                                if ($ip['ip-address-type'] === 'ipv4') {
                                    $ipv4[] = sprintf("%s/%s", $ip['ip-address'], $ip['prefix']);
                                } else {
                                    $ipv6[] = sprintf("%s/%s", $ip['ip-address'], $ip['prefix']);
                                }
                            }

                            $interfaces[$row['name']] = [
                                'hwaddr' => $row['hardware-address'] ?? "",
                                'ipv4' => $ipv4,
                                'ipv6' => $ipv6
                            ];
                        }
                    }

                    $vm['guest_network'] = $interfaces;
                    $vm['guest_agent'] = $hasAgent;
                }
                break;
            case "lxc":
                $url = sprintf("/nodes/%s/%s/config", $el['node'], $el['id']);

                // get network interfaces

                foreach ($this->get($url) as $key => $val) {
                    if ($key == "description" and $config) {
                        $vm['description'] = trim(stripslashes($val));
                    } elseif (preg_match('/^net.*/', $key)) {
                        $interface = ['ip' => [], 'ip6' => [], 'hwaddr' => 'N/A'];

                        // @todo: better way of doing this?
                        foreach (explode(',', $val) as $part) {
                            $elem = explode('=', $part);

                            // ignore interfaces with dynamic configuration
                            if (in_array(
                                $elem['0'],
                                ['ip', 'ip6']
                            ) && ($elem[1] === 'dhcp' || $elem[1] === 'auto')
                            ) {
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
                break;
            }

            ksort($interfaces, SORT_NATURAL);
            $vm['guest_network'] = $interfaces;

            $vms[] = (object) $vm;
        }

        return $vms;
    }

    public function getNodes()
    {
        $nodes = [];

        foreach ($this->get("/nodes") as $el) {
            $node = [
                "name" => $el['node'],
                "cpu" => (int) $el['maxcpu'],
                "memory" => (int) $el['maxmem']
            ];

            $nodes[] = (object) $node;
        }

        return $nodes;
    }

    /**
     * Prepare request headers
     * 
     * This will add the login token if present to the headers
     */
    private function getHeaders()
    {
        $headers = [];

        if ($this->_loginToken) {
            $headers["Authorization"] = "PVEAPIToken=" . $this->_user . "@" . $this->_realm . "!" . $this->_loginToken;
        } else {
            $headers["CSRFPreventionToken"] = $this->_loginTicket['CSRFPreventionToken'];
        }

        return $headers;
    }

    protected function get($url, $body = array())
    {
        return $this->request("get", $url, $body);
    }

    protected function post($url, $body = array())
    {
        return $this->request("post", $url, $body);
    }

    protected function request($method, $url, $body = array(), $additionalHeaders = array())
    {
        if (!$this->_loginToken and !$this->hasValidTicket()) {
            return;
        }

        $headers = $this->getHeaders() + $additionalHeaders;

        $url = $this->makeLocation() . $url;
        switch ($method) {
        case "get":
            return $this->curl()->get($url, $body, $headers)['data'];
        case "post":
            return $this->curl()->post($url, $body, $headers)['data'];
        }
    }

    /**
     * Log in to PVE API with authorization token
     */
    public function loginWithToken($token)
    {
        $this->_loginToken = $token;
    }

    /**
     * Log in to PVE API with password (legacy)
     *
     * This will retrieve a session ticket and pass it with subsequent requests
     */
    public function loginWithPassword($password)
    {
        if ($this->hasValidTicket()) {
            return;
        }

        $body = http_build_query(
            [
                "realm" => $this->_realm,
                "username" => $this->_user,
                "password" => $password
            ]
        );

        $url = $this->makeLocation() . "/access/ticket";
        $result = $this->curl()->post($url, $body);
        unset($body);

        if (isset($result['data'])) {
            $this->_loginTimestamp = time();
            $this->_loginTicket = $result['data'];

            $this->curl()->addCookie("PVEAuthCookie", $this->_loginTicket['ticket']);
        }
    }

    /**
     * Logout, destroy our session
     */
    public function logout()
    {
        $this->_loginTimestamp = null;
        $this->_loginTicket = null;
        $this->curl()->forgetCookie();
    }
}
