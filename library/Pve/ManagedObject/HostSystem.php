<?php

namespace Icinga\Module\Pve\ManagedObject;

use Icinga\Module\Pve\Api;

class HostSystem extends ManagedObject
{

    public static function getColumns()
    {
        return array(
            "name",
            "cpu_model",
            "cpu_speed",
            "cpu_cores",
            "cpu_sockets",
            "cpu_hvm",
            "memory_size",
            "pve_version",
            "kernel_version",
            "storages"
        );
    }

    public static function fetch(Api $api, $fetchStorages = true)
    {
        $nodes = [];

        foreach ($api->get("/nodes") as $el) {
            $nodeStatus = self::fetchNodeStatus($api, $el['node']);

            $node = [
                "name" => $el['node'],
                "cpu_model" => $nodeStatus['cpuinfo']['model'],
                "cpu_speed" => $nodeStatus['cpuinfo']['mhz'],
                "cpu_cores" => $nodeStatus['cpuinfo']['cpus'],
                "cpu_sockets" => $nodeStatus['cpuinfo']['sockets'],
                "cpu_hvm" => $nodeStatus['cpuinfo']['hvm'] == 1,
                "memory_size" => (int)$nodeStatus['memory']['total'] / 1024 / 1024 / 1024,
                "pve_version" => $nodeStatus['pveversion'],
                "kernel_version" => $nodeStatus['kversion'],
            ];

            if ($fetchStorages) {
                $node['storages'] = Storage::fetchStorageDetails($api, $el['node']);
            }

            $nodes[] = (object)$node;
        }

        return $nodes;
    }

    private static function fetchNodeStatus(Api $api, $node)
    {
        $data = $api->get(sprintf("/nodes/%s/status", $node));

        return $data;
    }
}