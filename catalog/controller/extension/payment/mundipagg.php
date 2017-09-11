<?php
/**
 * ControllerExtensionPaymentMundipagg is the payment module controller
 *
 * @package Mundipagg
 */
require_once DIR_SYSTEM.'library/mundipagg/vendor/autoload.php';

use Mundipagg\Order;
use Mundipagg\Log;
use Mundipagg\LogMessages;
use Mundipagg\Controller\Boleto;
use Mundipagg\Controller\CreditCard;
use Mundipagg\Controller\Settings;

class ControllerExtensionPaymentMundipagg extends Controller
{
    /**
     * @var array $data
     */
    private $data;

    /**
     * @var array $setting
     */
    private $setting;

    /**
     * @var object $creditCardModel
     */
    private $creditCardModel;

    /**
     * @var object $mundipaggModel
     */
    private $mundipaggModel;

    /**
     * It loads opencart/mundipagg models
     *
     * From time to time it is necessary to load a ton of models. This method just
     * group this statements together in order to make a cleaner code
     *
     * @return void
     */
    private function load()
    {
        $this->load->model('checkout/order');
        $this->load->model('setting/setting');
        $this->load->model('extension/payment/mundipagg_customer');
        $this->load->model('extension/payment/mundipagg_credit_card');
        $this->load->language('extension/payment/mundipagg');

        $this->data['misc'] = $this->language->get('misc');
        $this->setting = $this->model_setting_setting;
        $this->mundipaggModel = $this->model_mundipagg;
        $this->creditCardModel = $this->model_extension_payment_mundipagg_credit_card;
    }

    /**
     * This method sets the customized css file path
     *
     * The user can provide a custom css file to be used instead of modules default.
     * It must be inside theme stylesheet directory and be named mundipagg_theme.css.
     * (not implemented yet)
     *
     * @return Void
     */
    private function getDirectories()
    {
        $this->load();

        $theme = $this->config->get('config_theme');
        $themeDirectory = $this->config->get('theme_' . $theme . '_directory');
        $this->data['themeDirectory'] = 'catalog/view/theme/' . $themeDirectory;

        $customizedFile = $this->data['themeDirectory'] . '/stylesheet/mundipagg/mundipagg_customized.css';

        if (file_exists($customizedFile)) {
            $this->data['customizedFile'] = $customizedFile;
        }
    }

    /**
     * This method is called when user has to choose between the installed payment methods.
     *
     * @return mixed
     */
    public function index()
    {
        $boleto = new Boleto($this);
        $creditCard = new CreditCard($this);
        $settings = new Settings($this);

        $this->data['publicKey'] = $settings->getPublicKey();

        $this->getDirectories();

        if ($creditCard->isEnabled()) {
            $this->data = array_merge($this->data, $creditCard->getCreditCardPageInfo());
        }

        if ($boleto->isEnabled()) {
            $this->data = array_merge($this->data, $boleto->getBoletoPageInfo());
        }

        return $this->load->view('extension/payment/mundipagg', $this->data);
    }

    /**
     * Generate boletos page
     *
     * @return void
     */
    public function generateBoleto()
    {
        if (!$this->customer->isLogged()) {
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }

        $this->load();

        if (isset($this->session->data['order_id'])) {
            $orderData = $this->model_checkout_order->getOrder($this->session->data['order_id']);
            if ($this->validate($orderData)) {
                if ($orderData['payment_code'] === 'mundipagg') {
                    $order = new Order(
                        $this->model_extension_payment_mundipagg_customer,
                        $this
                    );

                    $response = $order->create($orderData, $this->cart, 'boleto');

                    if (isset($response->charges[0]->last_transaction->success)) {
                        $this->success($response);
                    }

                    $this->response->redirect($this->url->link('checkout/failure', '', true));
                }
            }
        }
        $this->response->redirect($this->url->link('checkout/cart'));
    }

    /**
     * Save payment and redirect user to boleto url
     *
     * @param string $response Api's response
     * @return void
     */
    private function success($response)
    {
        $orderComment =
                $this->language->get('boleto')['pending_order_status'] . " <br>" .
                "<a href='" .
                $response->charges[0]->last_transaction->url.
                "' target='_blank'>" .
                $this->language->get('boleto')['click_to_generate'] .
                "</a>";
        $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], 1, $orderComment, true);
        $this->response->redirect($response->charges[0]->last_transaction->url);
    }

    /**
     * Validate order data
     *
     * @return boolean
     */
    private function validate($orderData)
    {
        if (isset($orderData['order_id']) &&
            $orderData['order_id'] !== null) {
            $pattern = array(
               "customer_id",
               "email",
               "payment_firstname",
               "payment_lastname",
               "payment_address_1",
               "payment_address_2",
               "payment_postcode",
               "payment_city",
               "payment_zone_id",
               "payment_zone",
               "payment_zone_code",
               "payment_country_id",
               "payment_country",
               "payment_custom_field",
               "payment_method",
               "payment_code",
               "currency_id",
               "currency_code",
               "ip"
            );

            // kind of trick, but it counts how much indexes are in the difference between the intersection
            // between pattern and indexes in orderData
            if (count(array_diff($pattern, array_intersect(array_keys($orderData), $pattern))) > 0) {
                Log::create()->error(LogMessages::MALFORMED_REQUEST, __METHOD__);

                return false;
            }

            return true;
        }

        Log::create()
            ->error(LogMessages::ORDER_ID_NOT_FOUND, __METHOD__)
            ->withOrderId($orderData['order_id']);

        return false;
    }

    /**
     * Group credit card request validations in one method
     *
     * @return bool
     */
    private function isValidateCreditCardRequest()
    {
        if (!isset($this->session->data['order_id'])) {
            return false;
        }
        
        if ($this->session->data['payment_method']['code'] !== 'mundipagg') {
            return false;
        }
        
        if (!$this->customer->isLogged()) {
            return false;
        }
        
        if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
            return false;
        }
        
        return true;
    }

    /**
     * Create credit card order using mundipagg SDK
     *
     * @param $interest
     * @param $installments
     * @param $orderData
     * @param $cardToken
     * @return mixed
     */
    private function createCreditCardOrder($interest, $installments, $orderData, $cardToken)
    {
        $this->load();
        
        $order = new Order($this->model_extension_payment_mundipagg_customer, $this);
        
        $order->setInterest($interest);
        $order->setInstallments($installments);
        
        return $order->create($orderData, $this->cart, 'creditCard', $cardToken);
    }

    /**
     * It maps the statuses from mundipagg and those used in opencart
     *
     * @param mixed $response
     * @return string
     */
    private function translateStatusFromMP($response)
    {
        $statusFromMP = strtolower($response->status);
        
        $this->load->model('localisation/order_status');
        $statusModel = $this->model_localisation_order_status;
        
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

    /**
     * Update opencart order status with the mundipagg translated status
     *
     * @param mixed $response
     * @return void
     */
    private function updateOrderStatus($response)
    {
        $orderStatus = $this->translateStatusFromMP($response);
        
        if (!$orderStatus) {
            Log::create()
                ->error(LogMessages::UNKNOWN_ORDER_STATUS, __METHOD__)
                ->withResponseStatus($response->status)
                ->withOrderId($this->session->data['order_id']);

            $this->response->redirect($this->url->link('checkout/failure'));
        }
        
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/mundipagg_order_processing');
        
        $this->model_checkout_order->addOrderHistory(
            $this->session->data['order_id'],
            $orderStatus,
            '',
            true
        );

        $this->model_extension_payment_mundipagg_order_processing->setOrderStatus(
            $this->session->data['order_id'],
            $orderStatus
        );
    }

    /**
     * This method stores the received order id from mundipagg with the opencart order id
     *
     * @param string $mundiOrderId
     * @param string $openCartOrderId
     * @return void
     */
    public function saveMPOrderId($mundiOrderId, $openCartOrderId)
    {
        $this->load->model('extension/payment/mundipagg_credit_card');
        $this->model_extension_payment_mundipagg_credit_card->saveMundiOrder(
            $mundiOrderId,
            $openCartOrderId
        );
    }

    /**
     * This method process the credit card transaction
     *
     * @return void
     */
    public function processCreditCard()
    {
        $this->load();
        
        if (!$this->isValidateCreditCardRequest()) {
            Log::create()
                ->error(LogMessages::INVALID_CREDIT_CARD_REQUEST, __METHOD__)
                ->withOrderId($this->session->data['order_id']);

            $this->response->redirect($this->url->link('checkout/failure'));
        }

        $orderData = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $cardToken = $this->request->post['munditoken'];
        $paymentDetails = explode('|', $this->request->post['payment-details']);

        try {
            $response = $this->createCreditCardOrder(
                (double)$paymentDetails[1],
                $paymentDetails[0],
                $orderData,
                $cardToken
            );
        } catch (Exception $e) {
            Log::create()
                ->error(LogMessages::UNABLE_TO_CREATE_ORDER, __METHOD__)
                ->withOrderId($this->session->data['order_id'])
                ->withException($e)
                ->withBackTraceInfo();

            $this->response->redirect($this->url->link('checkout/failure'));
        }

        $this->updateOrderStatus($response);
        $this->saveMPOrderId($response->id, $this->session->data['order_id']);
        $this->response->redirect($this->url->link('checkout/success', '', true));

        http_response_code(302);
        $this->response->addHeader('Location: '.
            str_replace(
                ['&amp;', "\n", "\r"],
                ['&', '', ''],
                $this->url->link('checkout/success', '', true)
            )
        );
        $this->response->setOutput(true);
    }
}
