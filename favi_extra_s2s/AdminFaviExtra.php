<?php

class AdminFaviExtra extends AdminTabCore {

    public function display() {
        $helper = new HelperForm();
        $helper->bootstrap = true;

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'ExportForCarriers';
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];
        //$this->addJqueryUI('ui.datepicker');

        echo $helper->generateForm([$this->getForm()]);

        $helperList = new HelperList();
        $helperList->token = isset($_GET['token']) ? $_GET['token'] : Tools::getAdminTokenLite('AdminModules');
        $helperList->identifier = 'id_favi';
        $helperList->show_toolbar = false;
        $helperList->bootstrap = true;
        $helperList->table = 'orders';
        $helperList->simple_header = true;
        $helperList->currentIndex = AdminController::$currentIndex . '';
        echo $helperList->generateList($this->getOrderList(), $this->getFieldList());

        echo '<script>jQuery("#content").removeClass("nobootstrap").addClass("bootstrap")</script>'; // because i don't know how say presta for using bootstrap
    }

    private function getFormValues() {
        $from = Tools::getValue('export_from');
        $to = Tools::getValue('export_to');

        if (!$from) {
            $from = date("Y-m-d", strtotime('-7 day'));
        }
        if (!$to) {
            $to = date("Y-m-d", strtotime('+1 day'));
        }

        return [
            'export_from' => $from,
            'export_to' => $to,
        ];
    }

    private function getForm() {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Orders sent to FAVI'),
                    'icon' => 'icon-cloud',
                ],
                'input' => [
                    [
                        'type' => 'date',
                        'label' => $this->l('From'),
                        'name' => 'export_from',
                        'size' => 10,
                        'required' => true,
                    ],
                    [
                        'type' => 'date',
                        'label' => $this->l('To'),
                        'name' => 'export_to',
                        'size' => 10,
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('View'),
                ],
            ],
        ];
    }

    private function getOrderList() {
        $orderList = [];
        $from = Tools::getValue('export_from');
        $to = Tools::getValue('export_to');

        if (!$from) {
            $from = date("Y-m-d", strtotime('-7 day'));
        }
        if (!$to) {
            $to = date("Y-m-d", strtotime('+1 day'));
        }

        $sql = "SELECT * FROM `" . _DB_PREFIX_ . "favi_extra_s2s` WHERE `date` >= '" . $from . "' && `date` <= '" . $to . "' ORDER BY `id_favi` DESC;";
        try {
            $orders = Db::getInstance()->ExecuteS($sql, true, false);
        } catch (PrestaShopDatabaseException $e) {
            $orders = null;
        }
        if ($orders) {
            foreach ($orders as $order) {
                $orderList[] = [
                    'id_favi' => $order['id_favi'],
                    'data' => $order['data'],
                ];
            }
        }

        return $orderList;
    }

    private function getFieldList() {
        return [
            'id_favi' => [
                'title' => $this->l('#'),
                'remove_onclick' => true,
                'class' => 'fixed-width-xs',
            ],
            'data' => [
                'title' => $this->l('Data'),
                'remove_onclick' => true,
            ],
        ];
    }
}
