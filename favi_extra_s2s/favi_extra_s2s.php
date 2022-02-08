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
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2022 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class favi_extra_s2s extends Module
{
    protected $config_form = false;

    public function __construct()
    {
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

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.7');
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        Configuration::updateValue('FAVI_EXTRA_S2S_LIVE_MODE', false);

        $db = Db::getInstance();
        // create FAVI extra log table
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "favi_extra_s2s` (
                `id_favi` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `data` TEXT null,
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

        $tab_name = array('en' => 'FAVI extra', 'cs' => 'FAVI extra');
        foreach(Language::getLanguages(false) as $language) {
            $db->execute(
                'insert into `' . _DB_PREFIX_ . 'tab_lang` (id_tab, id_lang, name)
                values(' . $tab_id . ', ' . $language['id_lang'] . ', "' .
                pSQL($tab_name[$language['iso_code']] ? $tab_name[$language['iso_code']] : $tab_name['en']) . '")'
            );
        }

        if(!Tab::initAccess($tab_id)) {
            return false;
        }

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('actionValidateOrder');
    }

    public function uninstall()
    {
        Configuration::deleteByName('FAVI_EXTRA_S2S_LIVE_MODE');

        $db = Db::getInstance();
        // TODO: delete from tabl_lang
        $tabId = $db->query("SELECT `id_tab` FROM `" . _DB_PREFIX_ . "tab` WHERE `module` = 'favi_extra_s2s'")->fetchAll();
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
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if ((Tools::isSubmit('submitFavi_extra_s2sModule')) === true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        return $output.$this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitFavi_extra_s2sModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enabled'),
                        'name' => 'FAVI_EXTRA_S2S_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Use this module'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-eye"></i>',
                        'desc' => $this->l('Enter a valid tracking ID'),
                        'name' => 'FAVI_EXTRA_S2S_TRACKING_ID',
                        'label' => $this->l('Tracking ID'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'desc' => $this->l('Enter a valid token'),
                        'name' => 'FAVI_EXTRA_S2S_TOKEN',
                        'label' => $this->l('Server-side token'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'FAVI_EXTRA_S2S_ENABLED' => Configuration::get('FAVI_EXTRA_S2S_ENABLED') === "1",
            'FAVI_EXTRA_S2S_TRACKING_ID' => Configuration::get('FAVI_EXTRA_S2S_TRACKING_ID', ''),
            'FAVI_EXTRA_S2S_TOKEN' => Configuration::get('FAVI_EXTRA_S2S_TOKEN', ''),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path.'views/js/back.js');
            $this->context->controller->addCSS($this->_path.'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path.'/views/js/front.js');
        $this->context->controller->addCSS($this->_path.'/views/css/front.css');
    }

    public function hookActionValidateOrder($orderInformation)
    {
        $enabled = Configuration::get('FAVI_EXTRA_S2S_ENABLED') === "1";
        $trackingID = Configuration::get('FAVI_EXTRA_S2S_TRACKING_ID', '');
        if ($enabled && $trackingID) {
            $this->createFaviOrder($orderInformation, $trackingID);
        }
    }

    private function createFaviOrder($orderInformation, $trackingId) {
        $body = array(
            "orderId" => $orderInformation["order"]->id,
            "customer" => array(
                "email" => $orderInformation["customer"]->email,
                "name" => $orderInformation["customer"]->firstname . ' ' . $orderInformation["customer"]->lastname
            )
        );
        // products
        foreach ($orderInformation["order"]->product_list as $item) {
            $body["orderItems"][] = array(
                "product" => array(
                    "id" => $item["id_product"],
                    "name" => $item["name"]
                )
            );
        }
        $this->call('/tracking/' . $trackingId . '/orders', $body);
    }

    private function call($url, $body) {
        $key = Configuration::get('FAVI_EXTRA_S2S_TOKEN', '');
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL,'https://partner-events.favi.cz/api/v1' . $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode($body) );
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-Favi-Partner-Events-Server-Side-Token: $key",
            "Content-Type:application/json"
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $serverResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCode = curl_errno($ch);

        curl_close ($ch);

        switch ($httpCode) {
            case 400:
                // Bad request
            case 422:
                // Unprocessable Entity
                break;
            case 200:
            case 201:
                break;
        }

        $dataToLog = array(
            "httpCode" => $httpCode,
            "request" => $body,
            "response" => $serverResponse,
            "errorCode" => $errorCode
        );
        $this->saveLog($dataToLog);
    }

    private function saveLog($data) {
        file_put_contents('favi.log', json_encode($data) . "\n", FILE_APPEND);
        /*
        $db = Db::getInstance();
        $jsonData = json_encode($data);
        $db->insert(_DB_PREFIX_ . "favi_extra_s2s", array(
            'data' => pSQL($jsonData)
        ));/**/
    }
}
