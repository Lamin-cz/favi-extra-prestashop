<?php
/**
 * 2007-2022 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2022 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class favi_extra_s2s extends Module {

    public function __construct() {
        $this->name = 'favi_extra_s2s';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Roman Pospíšilík <roman.pospisilik@gmail.com>';
        $this->author_uri = 'https://github.com/Lamin-cz/';
        $this->need_instance = 1;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('FAVI extra');
        $this->description = $this->l('FAVI Partner Events Tracking');

        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => '1.7'];
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install() {
        Configuration::updateValue('FAVI_EXTRA_S2S_LIVE_MODE', false);

        $db = Db::getInstance();
        // create FAVI extra log table
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "favi_extra_s2s` (
                `id_favi` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `id_order` int(11) unsigned NOT NULL,
                `date` datetime NOT NULL
                `data` TEXT NULL,
                PRIMARY KEY (`id_favi`)
            ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8;"
        );

        // create admin tab under Orders
        $db->execute(
            'insert into `' . _DB_PREFIX_ . 'tab` (id_parent, class_name, module, position)
            select id_parent, "AdminFaviExtra", "favi_extra_s2s", coalesce(max(position) + 1, 0)
            from `' . _DB_PREFIX_ . 'tab` pt where id_parent=(select if (id_parent>0, id_parent, id_tab) from `' .
            _DB_PREFIX_ . 'tab` as tp where tp.class_name="AdminOrders") group by id_parent'
        );
        $tab_id = $db->insert_id();

        $tab_name = ['en' => 'FAVI extra', 'cs' => 'FAVI extra'];
        foreach (Language::getLanguages(false) as $language) {
            $db->execute(
                'insert into `' . _DB_PREFIX_ . 'tab_lang` (id_tab, id_lang, name)
                values(' . $tab_id . ', ' . $language['id_lang'] . ', "' .
                pSQL($tab_name[$language['iso_code']] ? $tab_name[$language['iso_code']] : $tab_name['en']) . '")'
            );
        }

        if (!Tab::initAccess($tab_id)) {
            return false;
        }

        return parent::install() &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('actionValidateOrder');
    }

    public function uninstall() {
        Configuration::deleteByName('FAVI_EXTRA_S2S_LIVE_MODE');

        $db = Db::getInstance();
        $tabId = $db->query("SELECT `id_tab` FROM `" . _DB_PREFIX_ . "tab` WHERE `module` = 'favi_extra_s2s'")
            ->fetchAll();
        foreach ($tabId as $item) {
            $db->execute(
                "DELETE FROM `" . _DB_PREFIX_ . "tab_lang` WHERE `id_tab` = " . $item["id_tab"] . ";"
            );
        }

        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'tab` WHERE module = "favi_extra_s2s";'
        );

        $db->execute(
            "DROP TABLE IF EXISTS `" . _DB_PREFIX_ . "favi_extra_s2s`;"
        );

        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent() {
        /**
         * If values have been submitted in the form, process.
         */
        if ((Tools::isSubmit('submitFavi_extra_s2sModule')) === true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        return $output . $this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm() {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitFavi_extra_s2sModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm()]);
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm() {
        $orderStates = OrderState::getOrderStates($this->context->language->id);
        $orderStatesForForm = [];

        foreach ($orderStates as $orderState) {
            $orderStatesForForm[] = [
                'id_order_state' => $orderState['id_order_state'],
                'name' => $orderState['name'],
            ];
        }
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Enabled'),
                        'name' => 'FAVI_EXTRA_S2S_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Use this module'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                    ],
                    [
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-eye"></i>',
                        'desc' => $this->l('Enter a valid tracking ID'),
                        'name' => 'FAVI_EXTRA_S2S_TRACKING_ID',
                        'label' => $this->l('Tracking ID'),
                        'required' => true,
                    ],
                    [
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'desc' => $this->l('Enter a valid token'),
                        'name' => 'FAVI_EXTRA_S2S_TOKEN',
                        'label' => $this->l('Server-side token'),
                        'required' => true,
                    ],
                    [
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-flag"></i>',
                        'desc' => $this->l('https://partner-events.favi.xx/api/'),
                        'name' => 'FAVI_EXTRA_S2S_DOMAIN',
                        'label' => $this->l('API domain suffix (e.g. cz)'),
                        'required' => true,
                    ],
                    [
                        'type' => 'checkbox',
                        'label' => $this->l('Cancel order states'),
                        'desc' => $this->l('Select order statuses that cancel the order'),
                        'name' => 'FAVI_EXTRA_S2S_CANCEL_STATES',
                        'multiple' => true,
                        'required' => true,
                        'values' => [
                            'query' => $orderStatesForForm,
                            'id' => 'id_order_state',
                            'name' => 'name',
                            'desc' => $this->l('Please select'),
                        ],
                        'expand' => [
                            'print_total' => count($orderStatesForForm),
                            'default' => 'show',
                            'show' => ['text' => $this->l('Show'), 'icon' => 'plus-sign-alt'],
                            'hide' => ['text' => $this->l('Hide'), 'icon' => 'minus-sign-alt'],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues() {
        $fields = [
            'FAVI_EXTRA_S2S_ENABLED' => Configuration::get('FAVI_EXTRA_S2S_ENABLED') === "1",
            'FAVI_EXTRA_S2S_TRACKING_ID' => Configuration::get('FAVI_EXTRA_S2S_TRACKING_ID', ''),
            'FAVI_EXTRA_S2S_TOKEN' => Configuration::get('FAVI_EXTRA_S2S_TOKEN', ''),
            'FAVI_EXTRA_S2S_DOMAIN' => Configuration::get('FAVI_EXTRA_S2S_DOMAIN', ''),
        ];

        foreach ($this->getCancelStates() as $state) {
            $fields['FAVI_EXTRA_S2S_CANCEL_STATES_' . $state] = true;
        }

        return $fields;
    }

    private function getCancelStates() {
        $cancelStates = unserialize(Configuration::get('FAVI_EXTRA_S2S_CANCEL_STATES'));
        return $cancelStates ? $cancelStates : [];
    }

    /**
     * Save form data.
     */
    protected function postProcess() {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            if (strpos($key, 'FAVI_EXTRA_S2S_CANCEL_STATES') === false) {
                Configuration::updateValue($key, Tools::getValue($key));
            }
        }
        $orderStates = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'FAVI_EXTRA_S2S_CANCEL_STATES') !== false) {
                $orderStates[] = (int)str_replace('FAVI_EXTRA_S2S_CANCEL_STATES_', '', $key);
            }
        }

        Configuration::updateValue('FAVI_EXTRA_S2S_CANCEL_STATES', serialize($orderStates));
    }

    /**
     * Add the CSS & JavaScript files you want to be loaded in the BO.
     */
    public function hookBackOfficeHeader() {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path . 'views/js/back.js');
            $this->context->controller->addCSS($this->_path . 'views/css/back.css');
        }
    }

    public function hookActionValidateOrder($orderInformation) {
        $enabled = Configuration::get('FAVI_EXTRA_S2S_ENABLED') === "1";
        $trackingID = Configuration::get('FAVI_EXTRA_S2S_TRACKING_ID', '');
        if ($enabled && $trackingID) {
            $this->createFaviOrder($orderInformation, $trackingID);
        }
    }

    public function hookActionOrderStatusUpdate($orderInformation) {
        $enabled = Configuration::get('FAVI_EXTRA_S2S_ENABLED') === "1";
        $trackingId = Configuration::get('FAVI_EXTRA_S2S_TRACKING_ID', '');
        $order = isset($orderInformation['order']) ? $orderInformation['order'] : null;
        if ($enabled && $trackingId && $order) {
            $cancelStates = $this->getCancelStates();
            if (in_array((int)$order->getCurrentState(), $cancelStates)) {
                $this->cancelFaviOrder($order->id, $trackingId);
            }
        }
        file_put_contents('log.txt', json_encode($orderInformation));
    }

    private function createFaviOrder($orderInformation, $trackingId) {
        $body = [
            "orderId" => $orderInformation["order"]->id,
            "customer" => [
                "email" => $orderInformation["customer"]->email,
                "name" => $orderInformation["customer"]->firstname . ' ' . $orderInformation["customer"]->lastname,
            ],
        ];
        // products
        foreach ($orderInformation["order"]->product_list as $item) {
            $body["orderItems"][] = [
                "product" => [
                    "id" => $item["id_product"],
                    "name" => $item["name"],
                ],
            ];
        }
        $this->call('/tracking/' . $trackingId . '/orders', $body, $orderInformation["order"]->id);
    }

    private function cancelFaviOrder($orderId, $trackingId) {
        $body = [
            "orderId" => $orderId,
        ];

        $this->call('/tracking/' . $trackingId . '/cancel-order', $body, $orderId);
    }

    private function call($url, $body, $orderId) {
        $key = Configuration::get('FAVI_EXTRA_S2S_TOKEN', '');
        $domain = Configuration::get('FAVI_EXTRA_S2S_DOMAIN', '');

        if (!$key || !$domain) {
            return;
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://partner-events.favi.' . $domain . '/api/v1' . $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-Favi-Partner-Events-Server-Side-Token: $key",
            "Content-Type: application/json",
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $serverResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCode = curl_errno($ch);

        curl_close($ch);

        switch ($httpCode) {
            case 400:
                // Bad request
            case 422:
                // Unprocessable Entity
                break;
            case 200:
            case 201:
                // success
                break;
        }

        $dataToLog = [
            "httpCode" => $httpCode,
            "request" => $body,
            "response" => $serverResponse,
            "errorCode" => $errorCode,
            "url" => $url,
        ];
        $this->saveLog($dataToLog, $orderId);
    }

    private function saveLog($data, $orderId) {
        $db = Db::getInstance();
        $jsonData = json_encode($data);
        $date = date("Y-m-d H:i:s");
        $db->execute(
            "INSERT INTO `" . _DB_PREFIX_ . "favi_extra_s2s` (`id_order`, `date`, `data`) VALUES ($orderId, '$date', '" . pSQL(
                $jsonData
            ) . "');"
        );
    }
}
