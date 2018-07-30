<?php

namespace Icinga\Module\Pve;

use Icinga\Application\Config;
use Icinga\Module\Director\Db;
use Icinga\Module\Director\Objects\IcingaCommand;
use Icinga\Module\Pve\ProvidedHook\Director\ImportSource;

class DirectorConfig
{
    /** @var Db */
    protected $db;

    public function commandExists(IcingaCommand $command)
    {
        return IcingaCommand::exists($command->getObjectName(), $this->db);
    }

    public function commandDiffers(IcingaCommand $command)
    {
        return IcingaCommand::load($command->getObjectName(), $this->db)
            ->replaceWith($command)
            ->hasBeenModified();
    }

    public function sync()
    {
        $result = false;

        foreach ($this->createServiceCommands() as $command) {
            $sync = $this->syncCommand($command);
            $result = $result || $sync;
        }

        return $result;
    }

    public function syncCommand(IcingaCommand $command)
    {
        $db = $this->db;

        $name = $command->getObjectName();
        if ($command::exists($name, $db)) {
            $new = $command::load($name, $db)
                ->replaceWith($command);
            if ($new->hasBeenModified()) {
                $new->store();

                return true;
            } else {
                return false;
            }
        } else {
            $command->store($db);

            return true;
        }
    }

    private function connectionArguments()
    {
        return [
            '-e' => (object)[
                'value' => '$pve_endpoint$',
                'required' => true,
                'description' => 'Hostname of PVE API endpoint',
            ],
            '-u' => (object)[
                'value' => '$pve_user$',
                'description' => 'PVE API user with realm (e.g. monitoring@pve)',
                'required' => true,
            ],
            '-p' => (object)[
                'value' => '$pve_password$',
                'description' => 'PVE API password',
                'required' => true,
            ],
            '-k' => (object)[
                'set_if' => '$pve_insecure_connection$',
                'description' => 'Don\'t verify certificates',
                'required' => false,
            ],
        ];
    }

    private function nodeArguments()
    {
        return [
            '-n' => (object)[
                'value' => '$pve_node$',
                'description' => 'Node to check (necessary for all modes except cluster)',
                'required' => false,
            ],
        ];
    }

    private function tresholdArguments()
    {
        return [
            '-w' => (object)[
                'value' => '$pve_warning$',
                'description' => 'Warning treshold',
                'required' => false,
            ],
            '-c' => (object)[
                'value' => '$pve_critical$',
                'description' => 'Critical treshold',
                'required' => false,
            ],
        ];
    }

    private function createServiceTemplate()
    {
        return IcingaCommand::create([
            'methods_execute' => 'PluginCheck',
            'object_name' => "PVE check command",
            'object_type' => 'template',
            'command' => 'check_pve.py',
            'arguments' => $this->connectionArguments(),
            'vars' => [],
        ], $this->db());
    }

    /**
     * @return IcingaCommand
     */
    private function serviceCommand($name, $args, $vars)
    {
        return IcingaCommand::create([
            'methods_execute' => 'PluginCheck',
            'object_name' => $name,
            'object_type' => 'object',
            'imports' => 'PVE check command',
            'arguments' => $args,
            'vars' => $vars,
        ], $this->db());
    }

    private function nodeServiceCommand($name, $args, $vars, $tresholds = true)
    {
        $args += $this->nodeArguments();

        if ($tresholds) {
            $args += $this->tresholdArguments();
        }

        return $this->serviceCommand($name, $args, $vars);
    }

    /**
     * Returns list of all available check commands
     *
     * @return IcingaCommand[]
     */
    public function createServiceCommands()
    {
        $commands = array(
            $this->createServiceTemplate(),
            $this->createClusterServiceCommand(),
            $this->createSubscriptionServiceCommand(),
            $this->createUpdateServiceCommand(),
            $this->createStorageServiceCommand(),
            $this->createReplicationCommand(),
            $this->createVMServiceCommand(),
            $this->createCPUServiceCommand(),
            $this->createMemoryServiceCommand(),
            $this->createIOwaitServiceCommand(),
        );

        return $commands;
    }

    /**
     * @return IcingaCommand
     */
    protected function createClusterServiceCommand()
    {
        $name = "PVE cluster health check command";
        $args = [
            '--mode' => (object)['value' => 'cluster']
        ];

        return $this->serviceCommand($name, $args, []);
    }

    /**
     * @return IcingaCommand
     */
    protected function createUpdateServiceCommand()
    {
        $name = "PVE software updates check command";
        $args = [
            '--mode' => (object)['value' => 'updates'],

        ];

        return $this->nodeServiceCommand($name, $args, [], false);
    }

    /**
     * @return IcingaCommand
     */
    protected function createSubscriptionServiceCommand()
    {
        $name = "PVE subscription check command";
        $args = [
            '--mode' => (object)['value' => 'subscription'],

        ];

        return $this->nodeServiceCommand($name, $args, []);
    }

    /**
     * @return IcingaCommand
     */
    protected function createStorageServiceCommand()
    {
        $name = "PVE storage check command";
        $args = [
            '--mode' => (object)['value' => 'storage'],
            '--name' => (object)[
                'value' => '$pve_resource_name$',
                'description' => 'Name of storage to check',
                'required' => true,

            ],
            '-M' => (object)[
                'set_if' => '$pve_unit_mb$',
                'description' => 'Set unit of tresholds and values to MB',
                'required' => false,
            ],
        ];

        return $this->nodeServiceCommand($name, $args, []);
    }

    /**
     * @return IcingaCommand
     */
    protected function createReplicationCommand()
    {
        $name = "PVE replication check command";
        $args = [
            '--mode' => (object)['value' => 'replication']
        ];

        return $this->nodeServiceCommand($name, $args, []);
    }

    /**
     * @return IcingaCommand
     */
    protected function createVMServiceCommand()
    {
        $name = "PVE vm check command";
        $args = [
            '--mode' => (object)['value' => 'vm'],
            '--name' => (object)[
                'value' => '$pve_resource_name$',
                'description' => 'Name of vm to check',
            ],
            '--vmid' => (object)[
                'value' => '$pve_vmid$',
                'description' => 'VMID of vm to check',
            ],
            '--ignore-vm-status' => (object)[
                'set_if' => '$pve_ignore_vm_status$',
                'description' => 'Ignore VM status'
            ],
            '--expected-vm-status' => (object)[
                'value' => '$pve_expected_vm_status$',
                'description' => 'Expected VM status (running, paused or stopped)',
            ],
            '-M' => (object)[
                'set_if' => '$pve_unit_mb$',
                'description' => 'Set unit of tresholds and values to MB',
                'required' => false,
            ],
        ];

        return $this->nodeServiceCommand($name, $args, []);
    }

    /**
     * @return IcingaCommand
     */
    protected function createCPUServiceCommand()
    {
        $name = "PVE cpu check command";
        $args = [
            '--mode' => (object)['value' => 'cpu'],
            '-M' => (object)[
                'set_if' => '$pve_unit_mb$',
                'description' => 'Set unit of tresholds and values to MB',
                'required' => false,
            ],
        ];

        return $this->nodeServiceCommand($name, $args, []);
    }

    /**
     * @return IcingaCommand
     */
    protected function createMemoryServiceCommand()
    {
        $name = "PVE memory check command";
        $args = [
            '--mode' => (object)['value' => 'memory'],
            '-M' => (object)[
                'set_if' => '$pve_unit_mb$',
                'description' => 'Set unit of tresholds and values to MB',
                'required' => false,
            ],
        ];

        return $this->nodeServiceCommand($name, $args, []);
    }

    /**
     * @return IcingaCommand
     */
    protected function createIOwaitServiceCommand()
    {
        $name = "PVE iowait check command";
        $args = [
            '--mode' => (object)['value' => 'io_wait'],
        ];

        return $this->nodeServiceCommand($name, $args, []);
    }

    public function db()
    {
        if ($this->db === null) {
            $this->db = $this->initializeDb();
        }

        return $this->db;
    }

    protected function initializeDb()
    {
        $resourceName = Config::module('director')->get('db', 'resource');
        return Db::fromResourceName($resourceName);
    }
}
