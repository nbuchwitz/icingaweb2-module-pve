<?php

namespace Icinga\Module\Pve\ManagedObject;


use Icinga\Module\Pve\Api;

class Storage extends ManagedObject
{

    public static function getColumns()
    {
        return array(
            "name",
            "node",
            "storage_name",
            "storage_size",
            "storage_content",
            "storage_enabled",
            "storage_active",
            "storage_shared",
            "storage_type",
        );
    }

    public static function fetch(Api $api)
    {
        $storageList = [];
        $tmp = [];
        foreach ($api->get("/cluster/resources/?type=storage") as $el) {
            $node = $el['node'];
            $storage = $el['storage'];

            $tmp[$node][$storage] = [
                "name" => $el["id"],
                "node" => $node,
            ];
        }

        foreach ($tmp as $node => $storage) {
            $storageDetails = self::fetchStorageDetails($api, $node);
            foreach ($storage as $name => $data) {
                $details = isset($storageDetails[$name]) ? $storageDetails[$name] : [];

                $storageList[] = (object)array_merge($data, $details);
            }
        }

        unset($tmp);

        return $storageList;
    }

    public static function fetchStorageDetails(Api $api, $node)
    {
        $storages = [];

        $data = $api->get(sprintf("/nodes/%s/storage", $node));

        foreach ($data as $el) {
            $content = (array)explode(",", $el['content']);
            asort($content);

            $storages[$el['storage']] = array(
                "storage_name" => $el['storage'],
                "storage_size" => (int)$el['total'] / 1024 / 1024 / 1024,
                "storage_active" => $el['active'] == 1,
                "storage_enabled" => $el['enabled'] == 1,
                "storage_shared" => $el['shared'] == 1,
                "storage_type" => $el['type'],
                "storage_content" => $content,
            );
        }

        asort($storages);

        return $storages;
    }
}