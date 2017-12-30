<?php

namespace Icinga\Module\Pve\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Pve\Api;

class CommandBase extends Command
{
    private $api;

    protected function api()
    {
        if ($this->api === null) {
            $p = $this->params;
            $scheme = $p->get('use-insecure-http') ? 'HTTP' : 'HTTPS';
            $port = $p->get('port', 8006);

            $this->api = new Api(
                $p->getRequired('host'),
                $port,
                $p->getRequired('realm'),
                $p->getRequired('username'),
                $p->getRequired('password'),
                $scheme
            );

            $curl = $this->api->curl();

            if ($scheme === 'HTTPS') {
                if ($p->get('no-ssl-verify-peer')) {
                    $curl->disableSslPeerVerification();
                }
                if ($p->get('no-ssl-verify-host')) {
                    $curl->disableSslHostVerification();
                }
            }
        }

        return $this->api;
    }
}
