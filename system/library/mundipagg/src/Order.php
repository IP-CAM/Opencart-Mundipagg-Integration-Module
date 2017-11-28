<?php
namespace Mundipagg;

require_once DIR_SYSTEM . 'library/mundipagg/vendor/autoload.php';

use MundiAPILib\MundiAPIClient;
use MundiAPILib\Models\CreateShippingRequest;
use MundiAPILib\Models\CreateOrderRequest;
use MundiAPILib\Models\CreateAddressRequest;
use MundiAPILib\Models\CreateCustomerRequest;
use Mundipagg\Controller\Settings;
use Mundipagg\Controller\Boleto;
use MundiAPILib\Exceptions\ErrorException;

/**
 * @method \MundiAPILib\Controllers\OrdersController getOrders()
 * @method \MundiAPILib\Controllers\CustomersController getCustomers()
 */
class Order
{
    private $orderInterest;
    private $orderInstallments;

    /**
     * @var MundiAPIClient
     */
    private static $apiClient;
    private $openCart;
    /**
     * @var Settings
     */
    private static $settings;
    private $modelOrder;

    private $mundipaggCustomerModel;

    /**
     * @param array $openCart
     */
    public function __construct($openCart)
    {
        $this->openCart = $openCart;
        $this->orderInterest = 0;
    }

    /**
     * @return Settings
     */
    public function getSettings()
    {
        if (!$this->settings) {
            $this->settings = new Settings($this->openCart);
        }
        return $this->settings;
    }

    /**
     * @return MundiAPIClient
     */
    public function getApiClient()
    {
        if (!$this->apiClient) {
            $this->apiClient = new MundiAPIClient(
                $this->getSettings()->getSecretKey(),
                $this->getSettings()->getPassword()
            );
        }
        return $this->apiClient;
    }

    public function __call(string $name, array $arguments)
    {
        if (method_exists($this->getApiClient(), $name)) {
            return call_user_func_array([$this->getApiClient(), $name], $arguments);
        }
    }

    public function getCharge($opencart_id)
    {
        return $this->modelOrder()->getCharge($opencart_id);
    }

    public function setInterest($interest)
    {
        $this->orderInterest = $interest;
    }

    public function setInstallments($installments)
    {
        $this->orderInstallments = $installments;
    }

    /**
     * Create a MundiPagg order
     * @param array $orderData
     * @param array $cart
     * @param string $paymentMethod
     * @param string $cardToken
     * @return object
     */
    public function create($orderData, $cart, $paymentMethod, $cardToken = null)
    {
        $items = $this->prepareItems($cart->getProducts());
        $createAddressRequest = $this->createAddressRequest($orderData);
        $createCustomerRequest = $this->createCustomerRequest($orderData, $createAddressRequest);
        $createShippingRequest = $this->createShippingRequest($orderData, $createAddressRequest, $cart);
        $totalOrderAmount = $orderData['total'];
        if (!empty($orderData['amountWithInterest'])) {
            $totalOrderAmount = $orderData['amountWithInterest'];
        }
        $isAntiFraudEnabled = $this->shouldSendAntiFraud($paymentMethod, $totalOrderAmount);

        $payments = $this->preparePayments($paymentMethod, $cardToken, $totalOrderAmount);


        $CreateOrderRequest = $this->createOrderRequest(
            $items,
            $createCustomerRequest,
            $payments,
            $orderData['order_id'],
            $this->getMundipaggCustomerId($orderData['customer_id']),
            $createShippingRequest,
            $this->getSettings()->getModuleMetaData(),
            $isAntiFraudEnabled
        );

        if (!$CreateOrderRequest->items) {
            return false;
        }

        \Mundipagg\Log::create()
            ->info(\Mundipagg\LogMessages::CREATE_ORDER_MUNDIPAGG_REQUEST, __METHOD__)
            ->withOrderId($orderData['order_id'])
            ->withRequest(json_encode($CreateOrderRequest, JSON_PRETTY_PRINT));

        $order = $this->getOrders()->createOrder($CreateOrderRequest);
        $this->createOrUpdateCharge($orderData, $order);

        \Mundipagg\Log::create()
            ->info(\Mundipagg\LogMessages::CREATE_ORDER_MUNDIPAGG_RESPONSE, __METHOD__)
            ->withOrderId($orderData['order_id'])
            ->withResponse(json_encode($order, JSON_PRETTY_PRINT));

        return $order;
    }

    public function updateCharge($chargeId, $action)
    {
        try {
            $charges = $this->getApiClient()->getCharges();

            \Mundipagg\Log::create()
                ->info(\Mundipagg\LogMessages::UPDATE_CHARGE_MUNDIPAGG_REQUEST, __METHOD__)
                ->withRequest('Action: ' . $action . ',ChargeId: '.$chargeId);

            $response = call_user_func_array(array($charges, $action.'Charge'), array($chargeId));

            \Mundipagg\Log::create()
                ->info(\Mundipagg\LogMessages::UPDATE_CHARGE_MUNDIPAGG_RESPONSE, __METHOD__)
                ->withResponse(json_encode($response, JSON_PRETTY_PRINT));

            return $response;
        } catch (ErrorException $e) {
            \Mundipagg\Log::create()
                ->error($e->getMessage(), __METHOD__)
                ->withLineNumber(__LINE__);
            throw new \Exception($e->getMessage());
        } catch (\Exception $e) {
            $const = "UNABLE_TO_{$action}_MUNDI_CHARGE";
            \Mundipagg\Log::create()
            ->error(\Mundipagg\LogMessages::$const, __METHOD__)
                ->withLineNumber(__LINE__);
                throw new \Exception(\Mundipagg\LogMessages::$const);
        }
    }

    public function modelOrder()
    {
        if (!$this->modelOrder) {
            $this->modelOrder = new Model\Order($this->openCart);
        }
        return $this->modelOrder;
    }

    public function createOrUpdateCharge(array $opencartOrder, $mundipaggOrder)
    {
        try {
            if (!is_object($mundipaggOrder)) {
                throw new \Exception();
            }
            $ModelOrder = $this->modelOrder();

            if (property_exists($mundipaggOrder, 'charges')) {
                foreach ($mundipaggOrder->charges as $charge) {
                    $data = array(
                        'opencart_id'     => $mundipaggOrder->code,
                        'charge_id'       => $charge->id,
                        'payment_method'  => $charge->payment_method,
                        'status'          => $charge->status,
                        'amount'          => $charge->amount,
                    );
                    if (isset($charge->paid_amount)) {
                        $data['paid_amount'] = $charge->paid_amount;
                    }
                    $ModelOrder->saveCharge($data);
                }
            } else {
                $data = array();
                if (property_exists($mundipaggOrder, 'canceledAt')) {
                    $data['canceled_amount'] = $mundipaggOrder->amount;
                } elseif (property_exists($mundipaggOrder, 'paidAt')) {
                    $data['paid_amount'] = $mundipaggOrder->amount;
                }
                if ($data) {
                    $data+=array(
                        'opencart_id'     => $mundipaggOrder->code,
                        'charge_id'       => $mundipaggOrder->id,
                        'payment_method'  => $mundipaggOrder->paymentMethod,
                        'status'          => $mundipaggOrder->status,
                    );
                    $ModelOrder->saveCharge($data);
                    $orderStatusId = $this->translateStatusFromMP($mundipaggOrder);
                    $ModelOrder->updateOrderStatus($mundipaggOrder->code, $orderStatusId);
                }
            }
        } catch (Exception $e) {
            \Mundipagg\Log::create()
            ->error(\Mundipagg\LogMessages::UNABLE_TO_SAVE_MUNDI_CHARGE, __METHOD__)
            ->withLineNumber(__LINE__);
        }
    }

    /**
     * @param array $items
     * @param CreateCustomerRequest $customer
     * @param array $payments
     * @param string $code
     * @param string $customerId
     * @param CreateShippingRequest $shipping
     * @param array $metadata
     * @param bool $isAntiFraudEnabled
     * @return CreateOrderRequest
     */
    private function createOrderRequest(
        $items,
        $customer,
        $payments,
        $code,
        $customerId,
        $shipping,
        $metadata = null,
        $isAntiFraudEnabled = false,
        $ip = null,
        $sessionId = null,
        $location = null,
        $device = null
    ) {
        return new CreateOrderRequest(
            $items,
            $customer,
            $payments,
            $code,
            $customerId,
            $shipping,
            $metadata,
            $isAntiFraudEnabled,
            $ip,
            $sessionId,
            $location,
            $device
        );
    }

    /**
     * Prepare items to API's format
     * @param array $products
     * @return array
     */
    private function prepareItems($products)
    {
        $items = array();
        foreach ($products as $product) {
            $items[] = array(
                'amount'      => $product['price'],
                'description' => $product['name'],
                'quantity'    => $product['quantity']
            );
        }
        return $items;
    }

    /**
     * @param array $orderData
     * @return \MundiAPILib\Models\CreateAddressRequest
     */
    private function createAddressRequest($orderData)
    {
        return new CreateAddressRequest(
            //Street
            $orderData['payment_address_1'],
            //Number
            $orderData['payment_custom_field'][
                $this->openCart->config->get('payment_mundipagg_mapping_number')
            ],
            //Zipcode
            preg_replace('/\D/', '', $orderData['payment_postcode']),
            //Neighborhood
            $orderData['payment_address_2'],
            //City
            $orderData['payment_city'],
            //State
            $orderData['payment_zone_code'],
            //Country
            $orderData['shipping_iso_code_2'],
            //Complement
            $orderData['payment_custom_field'][
                $this->openCart->config->get('payment_mundipagg_mapping_complement')
            ],
            //Metadata
            null
        );
    }

    /**
     * @param array $orderData
     * @param CreateOrderRequest $createAddressRequest
     * @return array
     */
    private function createCustomerRequest($orderData, $createAddressRequest)
    {
        return array(
            'name'     => $orderData['payment_firstname']." ".$orderData['payment_lastname'],
            'email'    => $orderData['email'],
            'phone'    => $orderData['telephone'],
            'document' => null,
            'type'     => "",
            'address'   => $createAddressRequest,
            'metadata' => null
        );
    }

    /**
     * @param string $paymentType
     * @param string $cardToken
     * @param float $orderAmount
     * @throws \Exception Unsupported payment type
     * @return array
     */
    private function preparePayments($paymentType, $cardToken, $orderAmount)
    {
        switch ($paymentType) {
            case 'boleto':
                return $this->getBoletoPaymentDetails();
            case 'creditCard':
                return $this->getCreditCardPaymentDetails(
                    $cardToken,
                    $this->orderInstallments,
                    $orderAmount
                );
            default:
                /** TODO: log it */
                throw new \Exception('Unsupported payment type');
        }
    }

    private function getBoletoPaymentDetails()
    {
        $boleto = new Boleto($this->openCart);

        return array(
            array(
                'payment_method' => 'boleto',
                'boleto' => array(
                    'bank'         => $boleto->getBank(),
                    'instructions' => $boleto->getInstructions(),
                    'due_at'       => $boleto->getDueDate()
                )
            )
        );
    }

    /**
     * Get global setting of module and return true if is AuthAndCapture and
     * false if is AuthOnly
     *
     * @return boolean
     */
    private function isCapture()
    {
        return $this->openCart->config->get('payment_mundipagg_credit_card_operation') != 'Auth';
    }

    private function getCreditCardPaymentDetails($token, $installments, $amount)
    {
        $amountInCents = $amount * 100;
        return array(
            array(
                'payment_method' => 'credit_card',
                'amount' => $amountInCents,
                'credit_card' => array(
                    'installments' => $installments,
                    'capture' => $this->isCapture(),
                    'card_token' => $token
                )
            )
        );
    }

     /**
     * @param array $orderData
     * @param CreateAddressRequest $createAddressRequest
     * @param array $cart
     * @return CreateShippingRequest
     */
    private function createShippingRequest($orderData, $createAddressRequest, $cart)
    {
        if ($cart->hasShipping()) {
            $shipping = array(
                'amountInCents' => $cart->session->data['shipping_method']['cost'] * 100,
                'description' => $cart->session->data['shipping_method']['title'],
                'recipientName' => $orderData['shipping_firstname'] . " " . $orderData['shipping_lastname'],
                'recipientPhone' => $orderData['telephone'],
                'addressId' => null
            );

            return new CreateShippingRequest(
                $shipping['amountInCents'],
                $shipping['description'],
                $shipping['recipientName'],
                $shipping['recipientPhone'],
                $shipping['addressId'],
                $createAddressRequest
            );
        }
    }

    /**
     * @param int $customerId
     * @return String
     */
    private function getMundipaggCustomerId($customerId)
    {
        $customer = $this->mundipaggCustomerModel->get($customerId);
        if ($customer['mundipagg_customer_id']) {
            return $customer['mundipagg_customer_id'];
        }
        return null;
    }

    public function setCustomerModel($mundipaggCustomerModel)
    {
        $this->mundipaggCustomerModel = $mundipaggCustomerModel;
    }

    /**
     * Update opencart order status with the mundipagg translated status
     *
     * @param mixed $orderStatus
     * @return void
     */
    public function updateOrderStatus($orderStatus)
    {
        $this->openCart->load->model('checkout/order');
        $this->openCart->load->model('extension/payment/mundipagg_order_processing');

        $this->openCart->model_checkout_order->addOrderHistory(
            $this->openCart->session->data['order_id'],
            $orderStatus,
            '',
            true
        );

        $this->openCart->load->model('extension/payment/mundipagg_order_processing');

        $this->openCart->model_extension_payment_mundipagg_order_processing->setOrderStatus(
            $this->openCart->session->data['order_id'],
            $orderStatus
        );
    }

    /**
     * It maps the statuses from mundipagg and those used in opencart
     *
     * @param mixed $response
     * @return string
     */
    public function translateStatusFromMP($response)
    {
        $statusFromMP = strtolower($response->status);

        $this->openCart->load->model('localisation/order_status');
        $statusModel = $this->openCart->model_localisation_order_status;

        switch ($statusFromMP) {
            case 'paid':
                $status = $statusModel->getOrderStatus(2)['order_status_id'];
                break;
            case 'pending':
                $status = $statusModel->getOrderStatus(1)['order_status_id'];
                break;
            case 'canceled':
                $status = $statusModel->getOrderStatus(7)['order_status_id'];
                break;
            case 'failed':
                $status = $statusModel->getOrderStatus(10)['order_status_id'];
                break;
            default:
                $status = false;
        }

        return $status;
    }

    private function setInterestToAmount($amount, $interest)
    {
        return round($amount + ($amount * ($interest * 0.01)), 2);
    }

    /**
     * Check if anti fraud is enabled and order
     * amount is bigger than minimum value.
     * @param string $paymentMethod
     * @param float $orderAmount
     * @return bool
     */
    private function shouldSendAntiFraud($paymentMethod, $orderAmount)
    {
        return
            $paymentMethod == 'creditCard' &&
            $this->getSettings()->isAntiFraudEnabled() &&
            $orderAmount >= $this->getSettings()->getAntiFraudMinVal();
    }
}
