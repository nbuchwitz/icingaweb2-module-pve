<?php

namespace Icinga\Module\Pve\Clicommands;

use Icinga\Application\Benchmark;

/**
 * Fetch information from a vCenter or ESXi host
 *
 * This is mostly for debugging purposes but might also be used for some kind
 * of automation scripts
 */
class FetchCommand extends CommandBase
{
    /**
     * Fetch all available VirtualMachines
     *
     * Mostly for test/debug reasons. Output occurs with default properties
     *
     * USAGE
     *
     * icingacli pve fetch virtualmachines \
     *     --host <pve-host> \
     *     --realm <realm>
     *     --username <username> \
     *     --password <password> \
     *     [options]
     *
     * OPTIONS
     *
     *   --port <pve-port>        Use a different port for api
     *   --no-ssl-verify-peer     Accept certificates signed by unknown CA
     *   --no-ssl-verify-host     Accept certificates not matching the host
     *   --use-insecure-http      Use plaintext HTTP requests
     *   --benchmark              Show resource usage summary
     *   --json                   Dump JSON output
     */
    public function virtualmachinesAction()
    {
        Benchmark::measure('Preparing the API');
        $api = $this->api();
        $api->login();
        Benchmark::measure('Logged in, ready to fetch');
        $objects = $api->getVMs($this->params->get('guest-agent',false),$this->params->get('fetch-network',false));
        Benchmark::measure(sprintf("Got %d VMs", count($objects)));
        $api->logout();
        Benchmark::measure('Logged out');

        if ($this->params->get('json')) {
            echo json_encode($objects);
        } else {
            print_r($objects);
        }
    }

    /**
     * Fetch all available HostSystems
     *
     * Mostly for test/debug reasons. Output occurs with default properties
     *
     * USAGE
     *
     * icingacli pve fetch hostsystems \
     *     --host <pve-host> \
     *     --realm <realm>
     *     --username <username> \
     *     --password <password> \
     *     [options]
     *
     * OPTIONS
     *
     *   --port <pve-port>        Use a different port for api
     *   --no-ssl-verify-peer     Accept certificates signed by unknown CA
     *   --no-ssl-verify-host     Accept certificates not matching the host
     *   --use-insecure-http      Use plaintext HTTP requests
     *   --benchmark              Show resource usage summary
     *   --json                   Dump JSON output
     */
    public function hostsystemsAction()
    {
        Benchmark::measure('Preparing the API');
        $api = $this->api();
        $api->login();
        Benchmark::measure('Logged in, ready to fetch');
        $objects = $api->getNodes();
        Benchmark::measure(sprintf("Got %d Hosts", count($objects)));
        $api->logout();
        Benchmark::measure('Logged out');

        if ($this->params->get('json')) {
            echo json_encode($objects);
        } else {
            print_r($objects);
        }
    }
}
