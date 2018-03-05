<?php

namespace Icinga\Module\Pve\ManagedObject;

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
            "memory_size",
            "pve_version",
            "kernel_version"
        );
    }
}