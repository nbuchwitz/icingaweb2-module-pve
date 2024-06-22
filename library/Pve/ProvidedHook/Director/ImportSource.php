<?php

namespace Icinga\Module\Pve\ProvidedHook\Director;

use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Pve\Api;


/**
 * Class ImportSource
 *
 * This is where we provide an Import Source for the Icinga Director
 */
class ImportSource extends ImportSourceHook
{
    /** @var  Api */
    protected $api;

    /**
     * Default authentification type. For now this is legacy, but will be changed to token in the future.
     * @var string
     * */
    public static $defaultAuthType = "legacy";

    public function getName()
    {
        return 'Proxmox Virtual Environment (PVE)';
    }

    public function getAuthType()
    {
        return $this->getSetting("auth_type", self::$defaultAuthType);
    }

    private function isTokenAuth()
    {
        return $this->getAuthType() === "token";
    }

    public function fetchData()
    {
        $data = [];

        $api = $this->api();
        if ($this->isTokenAuth()) {
            $api->loginWithToken($this->getSetting("token"));
        } else {
            $api->loginWithPassword($this->getSetting('password'));
        }
       
        switch ($this->getSetting("object_type")) {
            case "VirtualMachine":
                $fetchGuestAgent = $this->getSetting('vm_guest_agent') === 'y';
                $fetchDescription = $this->getSetting('vm_description') === 'y';
                $fetchHaState = $this->getSetting('vm_ha') === 'y';

                $data = $api->getVMs($fetchGuestAgent, $fetchDescription, $fetchHaState);

                break;
            case "HostSystem":
                $data = $api->getNodes();

                break;
            case "Pools":
                $data = $api->getPools();

                break;
        }
        $api->logout();

        return $data;
    }

    public function listColumns()
    {
        return array_keys((array)current($this->fetchData()));
    }

    /**
     * @inheritdoc
     */
    public static function getDefaultKeyColumnName()
    {
        return 'vm_name';
    }

    public static function addSettingsFormFields(QuickForm $form)
    {
        if (!function_exists('curl_version')) {
            $form->addError($form->translate(
                'The PHP CURL extension (php-curl) is not installed/enabled'
            ));

            return;
        }

        $form->addElement('select', 'object_type', [
            'label' => $form->translate('Object Type'),
            'description' => $form->translate(
                'The managed PVE object type this Import Source should fetch'
            ),
            'multiOptions' => $form->optionalEnum([
                'VirtualMachine' => 'Virtual Machines',
                'HostSystem' => 'Host Systems',
                'Pools' => 'Pools',
            ]),
            'class' => 'autosubmit',
            'required' => true,
        ]);

        $vm = ($form->getSentOrObjectSetting('object_type', 'HostSystem') === 'VirtualMachine');

        if ($vm) {
            static::addBoolean($form, 'vm_guest_agent', [
                'label' => $form->translate('Fetch Guest Agent data'),
                'description' => $form->translate(
                    'Fetch additional data from the QEMU guest agent (additional user permissions needed: VM.Monitor)'
                ),
            ], 'n');

            static::addBoolean($form, 'vm_ha', [
                'label' => $form->translate('Fetch VM HA state'),
                'description' => $form->translate(
                    'Fetch VM HA state. This will result in an additional query for each VM, thus can be slow for larger environments.'
                ),
            ], 'n');

            static::addBoolean($form, 'vm_description', [
                'label' => $form->translate('Fetch VM description'),
                'description' => $form->translate(
                    'Fetch VM description from configuration. This will result in an additional query for each VM, thus can be slow for larger environments.'
                ),
            ], 'n');
        }

        static::addBoolean($form, 'ssl_verify_peer', [
            'label' => $form->translate('Verify Peer'),
            'description' => $form->translate(
                'Whether we should check that our peer\'s certificate has'
                . ' been signed by a trusted CA. This is strongly recommended.'
            )
        ], 'y');
        static::addBoolean($form, 'ssl_verify_host', array(
            'label' => $form->translate('Verify Host'),
            'description' => $form->translate(
                'Whether we should check that the certificate matches the'
                . 'configured host'
            )
        ), 'y');

        $form->addElement('text', 'host', array(
            'label' => $form->translate('PVE host'),
            'description' => $form->translate(
                'In most cases you want to use a fully qualified domain name (and should'
                . ' match it\'s certificate). Alternatively, an IP address is fine.'
                . ' Please use <host>:<port> in case you\'re not using default'
                . ' HTTP(s) ports'
            ),
            'required' => true,
        ))->add;

        $form->addElement('text', 'port', array(
            'label' => $form->translate('PVE port'),
            'description' => $form->translate(
                'Default port is 8006'
            ),
            'placeholder' => '8006',
            'required' => true,
        ));


        $form->addElement('select', 'auth_type', [
            'label' => $form->translate('Authentification Type'),
            'description' => $form->translate(
                'Authentification type can be either token based or legacy password'
            ),
            'multiOptions' => $form->optionalEnum([
                'token' => 'Token',
                'legacy' => 'Password',
            ]),
            'class' => 'autosubmit',
            'required' => true,
        ]);

        $form->addElement('select', 'realm', [
            'label' => $form->translate('Realm'),
            'multiOptions' => [
                'pam' => $form->translate('Linux PAM standard authentication'),
                'pve' => $form->translate('Proxmox VE authentication server'),
            ],
            'class' => 'autosubmit',
            'value' => 'pam',
            'required' => true,
        ]);


        $form->addElement('text', 'username', array(
            'label' => $form->translate('Username'),
            'description' => $form->translate(
                'Will be used for API authentication against your PVE host'
            ),
            'required' => true,
        ));

        $legacyAuth = ($form->getSentOrObjectSetting('auth_type', self::$defaultAuthType) === 'legacy');

        if ($legacyAuth) {
            $form->addElement('password', 'password', array(
                'label' => $form->translate('Password'),
                'description' => $form->translate(
                    'Password for the given user'
                ),
                'required' => true,
            ));
        } else {
            $form->addElement('text', 'token', array(
                'label' => $form->translate('API Token'),
                'description' => $form->translate(
                    'API token for the given user'
                ),
                'required' => true,
            ));
        }

        $form->addDisplayGroup(
            array(
                "object_type",
                "vm_guest_agent",
                "vm_ha",
                "vm_description",
            ),
            "object_config",
            array("legend" => "Import object configuration")
        );

        $form->addDisplayGroup(
            array(
                "host",
                "port",
                "ssl_verify_peer", "ssl_verify_host",
                "realm",
                "auth_type",
                "username", "token", "password",
            ),
            "pve_config",
            array("legend" => "Server configuration")
        );

        $form->setDisplayGroupDecorators(array(
            'FormElements',
            'Fieldset',
        ));
    }

    protected static function addBoolean($form, $key, $options, $default = null)
    {
        if ($default === null) {
            return $form->addElement('OptionalYesNo', $key, $options);
        } else {
            $form->addElement('YesNo', $key, $options);
            return $form->getElement($key)->setValue($default);
        }
    }

    protected static function optionalBoolean($form, $key, $label, $description)
    {
        return static::addBoolean($form, $key, array(
            'label' => $label,
            'description' => $description
        ));
    }

    protected function api()
    {
        if ($this->api === null) {
            $port = $this->getSetting('port', '8006');

            $this->api = new Api(
                $this->getSetting('host'),
                $port,
                $this->getSetting('realm'),
                $this->getSetting('username'),
            );

            $curl = $this->api->curl();

            if ($this->getSetting('ssl_verify_peer', 'y') === 'n') {
                $curl->disableSslPeerVerification();
            }
            if ($this->getSetting('ssl_verify_host', 'y') === 'n') {
                $curl->disableSslHostVerification();
            }
        }

        return $this->api;
    }
}
