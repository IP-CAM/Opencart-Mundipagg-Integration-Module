<?php

namespace Tests;

class MundipaggAdminTest extends OpenCartTest
{
    public function setUp()
    {
        parent::setUp();
        $this->loadModel('customer/custom_field');
        $this->model_customer_custom_field->addCustomField([
            'custom_field_description' => [1 => ['name' => 'Número']],
            'location' => 'address',
            'type' => 'text',
            'value' => '',
            'validation' => '',
            'custom_field_customer_group' => [
                0 => [
                    'customer_group_id' => 1,
                    'required' => 1
                ]
            ],
            'status' => 1,
            'sort_order' => 1
        ]);
        $this->model_customer_custom_field->addCustomField([
            'custom_field_description' => [1 => ['name' => 'Complemento']],
            'location' => 'address',
            'type' => 'text',
            'value' => '',
            'validation' => '',
            'custom_field_customer_group' => [
                0 => [
                    'customer_group_id' => 1,
                    'required' => 1
                ]
            ],
            'status' => 1,
            'sort_order' => 2
        ]);
    }
    public function testSetupModule()
    {
        $this->login('admin', 'admin');
        $response = $this->dispatchAction(
            'extension/extension/payment/install',
            'GET',
            ['extension'=>'mundipagg']
        );
        $this->assertRegExp('/uninstall.*extension=mundipagg/', $response->getOutput());
        $response = $this->dispatchAction(
            'extension/payment/mundipagg',
            'POST',
            [
                'payment_mundipagg_status' => 1,
                'payment_mundipagg_title' => 'MundiPagg Title',
                'payment_mundipagg_mapping_number' => 1,
                'payment_mundipagg_mapping_complement' => 2,
                'payment_mundipagg_prod_secret_key' => getenv('PROD_SECRET_KEY'),
                'payment_mundipagg_test_secret_key' => getenv('TEST_SECRET_KEY'),
                'payment_mundipagg_test_mode' => 1,
                'payment_mundipagg_prod_public_key' => getenv('PROD_PUBLIC_KEY'),
                'payment_mundipagg_test_public_key' => getenv('TEST_PUBLIC_KEY'),
                'payment_mundipagg_log_enabled' => 1,
                'payment_mundipagg_credit_card_status' => 1,
                'payment_mundipagg_credit_card_payment_title' => 'Cartão de crédito',
                'payment_mundipagg_credit_card_invoice_name' => 'Borracharia',
                'payment_mundipagg_credit_card_operation' => 'Auth',
                'creditCard' => [
                    'Visa' => [
                        'is_enabled' => 1,
                        'installments_up_to' => 12,
                        'installments_without_interest' => 3,
                        'interest' => '2.99'
                    ],
                    'Mastercard' => [
                        'is_enabled' => 1,
                        'installments_up_to' => 12,
                        'installments_without_interest' => 3,
                        'interest' => '2.99'
                    ],
                    'Amex' => [
                        'is_enabled' => 1,
                        'installments_up_to' => 12,
                        'installments_without_interest' => 3,
                        'interest' => '2.99'
                    ],
                    'Diners' => [
                        'is_enabled' => 1,
                        'installments_up_to' => 12,
                        'installments_without_interest' => 3,
                        'interest' => '2.99'
                    ],
                    'Elo' => [
                        'is_enabled' => 1,
                        'installments_up_to' => 12,
                        'installments_without_interest' => 3,
                        'interest' => '2.99'
                    ],
                    'Hipercard' => [
                        'is_enabled' => 1,
                        'installments_up_to' => 12,
                        'installments_without_interest' => 3,
                        'interest' => '2.99'
                    ]
                ],
                'payment_mundipagg_boleto_status' => 1,
                'payment_mundipagg_boleto_title' => 'Boleto',
                'payment_mundipagg_boleto_name' => 'Borracharia',
                'payment_mundipagg_boleto_bank' => 341,
                'payment_mundipagg_boleto_due_date' => '',
                'payment_mundipagg_boleto_instructions' => str_repeat('bla ', 30)
            ]
        );
        $response = $this->dispatchAction('extension/extension/payment');
        $actual = $this->db->query('SELECT value FROM `' . DB_PREFIX . 'setting` where `key` = \'payment_mundipagg_status\' AND value = 1');
        $this->assertInstanceOf('stdClass', $actual);
    }

    public function _tearDown()
    {
        $response = $this->dispatchAction(
            'extension/extension/payment/uninstall',
            'GET',
            ['extension'=>'mundipagg']
        );
    }
}
