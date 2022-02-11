<?php

class AdminFaviExtra extends AdminTabCore {

    const CREATE_ORDER_PATTERN = '~^\/tracking\/[a-z0-9]+\/orders$~';
    const CANCEL_ORDER_PATTERN = '~^\/tracking\/[a-z0-9]+\/cancel-order$~';

    const TYPE_CREATE_ORDER = 'create';
    const TYPE_CANCEL_ORDER = 'cancel';
    const TYPE_UNKNOWN = 'unknown';

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

        echo $helper->generateForm([$this->getForm()]);

        $helperList = new HelperList();
        $helperList->token = isset($_GET['token']) ? $_GET['token'] : Tools::getAdminTokenLite('AdminFaviExtra');
        $helperList->identifier = 'id_favi';
        $helperList->show_toolbar = false;
        $helperList->bootstrap = true;
        $helperList->table = 'FaviOrders';
        $helperList->simple_header = true;
        $helperList->currentIndex = AdminController::$currentIndex . '';
//        $helperList->actions = [
//            'cancel',
//        ];
        echo $helperList->generateList($this->getOrderList(), $this->getFieldList());

        echo '<script>jQuery("#content").removeClass("nobootstrap").addClass("bootstrap")</script>'; // because i don't know how say presta for using bootstrap
    }

    private function getFormValues() {
        $from = Tools::getValue('export_from');
        $to = Tools::getValue('export_to');
        $orderId = Tools::getValue('order_id');

        if (!$from) {
            $from = date("Y-m-d", strtotime('-7 day'));
        }
        if (!$to) {
            $to = date("Y-m-d", strtotime('+1 day'));
        }

        return [
            'export_from' => $from,
            'export_to' => $to,
            'order_id' => $orderId,
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
                        'required' => false,
                    ],
                    [
                        'type' => 'date',
                        'label' => $this->l('To'),
                        'name' => 'export_to',
                        'size' => 10,
                        'required' => false,
                    ],
                    [
                        'col' => 3,
                        'type' => 'text',
                        'label' => $this->l('Order'),
                        'name' => 'order_id',
                        'size' => 10,
                        'required' => false,
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
        $orderId = (int)trim(Tools::getValue('order_id'));

        if (!$from) {
            $from = date("Y-m-d", strtotime('-7 day'));
        }
        if (!$to) {
            $to = date("Y-m-d", strtotime('+1 day'));
        }

        if ($orderId) {
            $sql = "SELECT * FROM `" . _DB_PREFIX_ . "favi_extra_s2s` WHERE `id_order` = '" . $orderId . "' ORDER BY `id_favi` DESC;";
        } else {
            $sql = "SELECT * FROM `" . _DB_PREFIX_ . "favi_extra_s2s` WHERE `date` >= '" . $from . "' && `date` <= '" . $to . "' ORDER BY `id_favi` DESC;";
        }
        try {
            $orders = Db::getInstance()->ExecuteS($sql, true, false);
        } catch (PrestaShopDatabaseException $e) {
            $orders = null;
        }
        if ($orders) {
            foreach ($orders as $order) {
                $data = json_decode($order['data']);
                $orderList[] = [
                    'id_favi' => $order['id_favi'],
                    'id_order' => $order['id_order'],
                    'date' => $order['date'],
                    'httpCode' => $data->httpCode,
                    'response' => json_encode($data->response),
                    'customer' => $data->request->customer->name . "\n<" . $data->request->customer->email . '>',
                    'type' => $this->getCallType($data->url),
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
            'id_order' => [
                'title' => $this->l('Order'),
                'remove_onclick' => true,
                'class' => 'fixed-width-xs',
            ],
            'date' => [
                'title' => $this->l('Date'),
                'remove_onclick' => true,
                'class' => 'fixed-width-xl',
            ],
            'customer' => [
                'title' => $this->l('Customer'),
                'remove_onclick' => true,
            ],
            'httpCode' => [
                'title' => $this->l('HTTP code'),
                'remove_onclick' => true,
                'class' => 'fixed-width-xs',
            ],
            'response' => [
                'title' => $this->l('FAVI response'),
                'remove_onclick' => true,
            ],
            'type' => [
                'title' => $this->l('Type'),
                'remove_onclick' => true,
            ],
        ];
    }

    private function getCallType($url) {
        if (preg_match(self::CREATE_ORDER_PATTERN, $url)) {
            return self::TYPE_CREATE_ORDER;
        } elseif (preg_match(self::CANCEL_ORDER_PATTERN, $url)) {
            return self::TYPE_CANCEL_ORDER;
        }

        return self::TYPE_UNKNOWN;
    }
}
