<?php

/** @var \Icinga\Application\Modules\Module $this */
$section = $this->menuSection(N_('Proxmox VE'))
    ->setUrl('pve/overview')
    ->setPriority(60)
    ->setIcon('cloud');
//$section->add(N_('Overview'))->setUrl('pve/overview');
$section->add(N_('Configuration'))
    ->setUrl('pve/configuration/director')
    ->setPermission('director/admin');