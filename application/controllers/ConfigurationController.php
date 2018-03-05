<?php

namespace Icinga\Module\Pve\Controllers;

use dipl\Html\Html;
use dipl\Html\Link;
use Icinga\Module\Director\Objects\IcingaCommand;
use Icinga\Module\Pve\DirectorConfig;
use Icinga\Module\Pve\Web\Controller;
use Icinga\Web\Notification;

class ConfigurationController extends Controller
{
    public function init()
    {
        $this->assertPermission('director/admin');
    }

    public function indexAction()
    {
        $this->addTitle('PVE Configuration')->activateTab();
    }

    public function directorAction()
    {
        $this->addTitle('Director Config Preview')->activateTab();
        if ($this->params->get('action') === 'sync') {
            $this->runFailSafe('sync');
            return;
        }
        $this->actions()->add(Link::create(
            'Sync to Director',
            'pve/configuration/director',
            ['action' => 'sync'],
            ['class' => 'icon-flapping']
        ));
        $this->runFailSafe(function () {
            $config = new DirectorConfig();

            foreach ($config->createServiceCommands() as $command) {
                $this->addCommand($command, $config);
            }
        });
    }

    protected function sync()
    {
        $config = new DirectorConfig();
        if ($config->sync()) {
            Notification::success('Commands have been updated in Icinga Director');
        } else {
            Notification::success('Nothing changed, commands are fine');
        }
        $this->redirectNow($this->url()->without('action'));
    }

    protected function addCommand(IcingaCommand $command, DirectorConfig $config)
    {
        $c = $this->content();
        $name = $command->getObjectName();
        $c->add(Html::tag('h1', null, $name));
        if ($config->commandExists($command)) {
            $link = Link::create(
                $name,
                'director/command',
                ['name' => $name],
                ['data-base-target' => '_next']
            );

            if ($config->commandDiffers($command)) {
                $c->add($this->createHint(
                    Html::sprintf(
                        'The CheckCommand %s exists but differs in your Icinga Director',
                        $link
                    ),
                    'warning'
                ));
            } else {
                $c->add($this->createHint(
                    Html::sprintf(
                        'The CheckCommand definition for %s is fine',
                        $link
                    ),
                    'ok'
                ));
            }
        } else {
            $c->add($this->createHint(
                'Command does not exist in your Icinga Director',
                'warning'
            ));
        }
        $c->add(Html::tag('pre', null, (string)$command));
    }

    protected function createHint($msg, $state)
    {
        return Html::tag('p', ['class' => ['state-hint', $state]], $msg);
    }

    protected function activateTab($name = null)
    {
        if ($name === null) {
            $name = $this->getRequest()->getActionName();
        }
        $this->tabs()->add('director', [
            'label' => 'Director Config',
            'url' => 'pve/configuration/director',
        ])->activate($name);

        return $this;
    }
}
