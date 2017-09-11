<?php

namespace Tests;

use function GuzzleHttp\json_encode;
use function GuzzleHttp\json_decode;

class MundipaggCatalogTest extends OpenCartTest
{
    public function setUp()
    {
        if (!$this->session->getId()) {
            $this->loadModel('account/customer');
            $customer_id = $this->model_account_customer->addCustomer([
                'customer_group_id' => 1,
                'firstname' => 'firstname',
                'lastname' => 'lastname',
                'email' => 'customer@mundipagg.com',
                'telephone' => 'telephone',
                'password' => 'password',
                'custom_field' => array(),
            ]);
            $this->model_account_customer->editPassword('customer@mundipagg.com', 'password');
            $this->login('customer@mundipagg.com', 'password');
        }
    }
    
    public function testAddCart()
    {
        $response = $this->dispatchAction('checkout/cart/add', 'POST', [
            'quantity'   => 1,
            'product_id' => 40
        ]);
        $this->assertRegExp('/Success: You have added/', $response->getOutput());
        $this->session->close();
    }
    
    public function testCheckoutPaymentAddressSave()
    {
        $response = $this->dispatchAction('checkout/payment_address/save', 'POST', [
            'firstname' => 'José',
            'lastname' => 'Das Couves',
            'company' => '',
            'address_1' => 'Rua dos Bobos',
            'address_2' => 'Bairro',
            'city' => 'Neverland',
            'postcode' => '171171171',
            'country_id' => '30',
            'zone_id' => '446',
            'custom_field' => [
                'address' => [
                    1 => 171,
                    2 => 'fundos'
                ]
            ]
        ]);
        $this->assertEquals('[]', $response->getOutput());
        $this->session->close();
    }
    
    public function testCheckoutShippingAddresSave()
    {
        $response = $this->dispatchAction('checkout/shipping_address/save', 'POST', [
            'shipping_address' => 'existing',
            'address_id' => '1',
            'firstname' => '',
            'lastname' => '',
            'company' => '',
            'custom_field' => [
                'address' => [
                    1 => '',
                    2 => ''
                ]
            ],
            'address_1' => '',
            'address_2' => '',
            'city' => '',
            'postcode' => '',
            'country_id' => '222',
            'zone_id' => ''
        ]);
        $this->assertEquals('[]', $response->getOutput());
        $this->session->close();
    }
    
    public function testCheckoutShippingMethodSave()
    {
        $response = $this->dispatchAction('checkout/shipping_method/save', 'POST', [
            'shipping_method' => 'flat.flat',
            'comment' => ''
        ]);
        $this->assertEquals('[]', $response->getOutput());
        $this->session->close();
    }

    public function _testCheckoutPaymentMethodSave()
    {
        $response = $this->dispatchAction('checkout/payment_method/save', 'POST', [
            'shipping_method' => 'flat.flat',
            'comment' => '',
            'agree' => 1
        ]);
        $this->assertRegExp('/route=checkout/', $response->getOutput());
        $this->session->close();
    }

    public function _testCheckoutConfirm()
    {
        $response = $this->dispatchAction('checkout/confirm', 'GET');
        $this->assertRegExp('/Credit card number/', $response->getOutput());
    }

    public function _testCreateOrder()
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.mundipagg.com/core/v1/tokens?appId=' . getenv('TEST_PUBLIC_KEY'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => <<<CARD_BODY
{
  "type": "card",
  "card": {
    "number": "4011185771285580",
    "holder_name": "Tony Stark",
    "exp_month": 1,
    "exp_year": 18,
    "cvv": "651"
  }
}
CARD_BODY
,
            CURLOPT_HTTPHEADER => ['content-type: application/json'],
        ]);
        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return $this->markTestSkipped('cURL Error #:' . $err);
        } else {
            $response = json_decode($response);
        }
        $this->assertTrue(true);
        $response = $this->dispatchAction(
            'extension/payment/mundipagg/processCreditCard',
            'POST',
            [
                'payment-details' => '1|0 ',
                'munditoken' => $response->id
            ]
        );
        $pare_aqui;
    }

    public function _tearDown()
    {
        $response = $this->dispatchAction(
            'customer/customer/delete',
            'POST',
            ['selected'=>[1]]
        );
    }
}