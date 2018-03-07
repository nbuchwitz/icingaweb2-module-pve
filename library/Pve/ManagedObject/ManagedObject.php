<?php

namespace Icinga\Module\Pve\ManagedObject;

use Icinga\Module\Pve\Api;

abstract class ManagedObject
{
    abstract public static function getColumns();

    abstract public static function fetch(Api $api);
}