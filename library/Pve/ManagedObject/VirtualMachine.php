<?php

namespace Icinga\Module\Pve\ManagedObject;

use Icinga\Module\Pve\Api;

class VirtualMachine extends ManagedObject
{
    protected static function getMappedValues()
    {
        return array(
            "cluster" => [
                "name",
                "id" => ["name" => "vmid"],
                "node",
                "pool",
                "type",
            ],
            "node" => [
                "cpu_cores" => ["name" => "cores"],
                "cpu_sockets" => ["name" => "sockets"],
                "cpu_numa" => ["name" => "numa", "type" => "boolean"],
                "memory_size" => ["name" => "memory"],
                "description",
                "autostart" => ["name" => "onboot", "type" => "boolean"],
                "os_type" => ["name" => "ostype"],
                "guest_agent" => ["name" => "agent", "type" => "boolean"]
            ],
        );
    }

    public static function getApiColumn($column)
    {
        if (is_array($column)) {
            return $column["name"];
        } else {
            return $column;
        }
    }

    public static function isBooleanColumn($column)
    {
        if (is_array($column) && isset($column["type"])) {
            return $column["type"] === "boolean";
        } else {
            return false;
        }
    }

    public static function getColumns()
    {
        $columns = [];
        foreach (self::getMappedValues() as $scope => $list) {
            foreach ($list as $column => $alias) {
                if (is_int($column)) {
                    $columns[] = $alias;
                } else {
                    $columns[] = $column;
                }
            }
        }

        return $columns;
    }

    public static function fetch(Api $api)
    {
        $mapped = self::getMappedValues();
        $vms = [];

        foreach ($api->get("/cluster/resources?type=vm") as $vm) {
            if ($vm['template'] === 1 || !isset($vm['node']) || !isset($vm['id'])) {
                continue;
            }

            $object = [];
            $sources = array(
                "cluster" => $vm,
                "node" => self::fetchVMDetails($api, $vm['node'], $vm['id'])
            );

            foreach ($sources as $source => $data) {
                foreach (($mapped[$source]) as $c => $mapping) {
                    $column = is_int($c) ? $mapping : $c;
                    $alias = self::getApiColumn($mapping);

                    $object[$column] = isset($data[$alias]) ? $data[$alias] : "";

                    if (self::isBooleanColumn($mapping)) {
                        $object[$column] = (bool)$object[$column];
                    }
                }
            }

            $vms[] = (object)$object;
        }

        return $vms;
    }

    protected static function fetchVMDetails(Api $api, $node, $id)
    {
        $data = $api->get(sprintf("/nodes/%s/%s/config", $node, $id));

        return $data;
    }
}