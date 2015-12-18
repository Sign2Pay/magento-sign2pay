<?php

class Sign2pay_Payment_Model_Processor extends Mage_Payment_Model_Method_Abstract
{
    /**
     * Request order
     * @var Mage_Sales_Model_Order
     */
    protected $_order = null;

    /**
     * Request data
     * @var array
     */
    protected $_request = array();

    /**
     * Request data getter
     *
     * @param string $key
     * @return array|string
     */
    public function getRequestData($key = null)
    {
        if (null === $key) {
            return $this->_request;
        }
        return isset($this->_request[$key]) ? $this->_request[$key] : null;
    }

    /**
     * General payment method responsible for the flow of the operations
     *
     * @param array returned by sign2pay api after the initial request
     *
     */
    public function performPayment(array $initial_response)
    {
        try{
            // Load appropriate order
            $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
            $this->_order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
            if (!$this->_order->getId()) {
                throw new Exception('Requested order with id ' . $orderId . ' does not exists.');
            }

            $this->validateInitialResponse($initial_response);
            $token_response = json_decode($this->sendTokenExchangeRequest($initial_response), true);
                if (empty($token_response['access_token']['token'])) {
                    if (!empty($token_response['error_description'])) {
                        throw new Exception($token_response['error_description']);
                    }
                    throw new Exception('Token is missing');
                }

            $payment = json_decode($this->sendPaymentRequest($token_response), true);
                if (empty($payment['purchase_id'])) {
                    if (!empty($payment['error_description'])) {
                        throw new Exception($payment['error_description']);
                    }
                    throw new Exception('Purchase ID is missing');
                }

            return $this->processPaymentCaptureResponse($payment);
        }
        catch (Exception $e) {
            Mage::logException($e);
            Mage::getSingleton('checkout/session')->addError($e->getMessage());

            $this->cancel();
            return Mage::app()->getResponse()->setRedirect('failure', array('_secure'=>true));
        }
    }


    /**
     * Validate the initial response
     * add error to session and throw exception if something's not right
     *
     * @param array returned by sign2pay api after the initial request
     *
     */
    public function validateInitialResponse(array $initial_response)
    {
        if ($initial_response['state'] !== Mage::getSingleton('checkout/session')->getSign2PayUserHash()
            || array_key_exists('error', $initial_response)) {

            if (!empty($initial_response['error_description'])) {
                throw new Exception($initial_response['error_description']);
            }
            throw new Exception('Could not validate the response');
        }
    }

    /**
     * Exchange hashed credentials for token (second step of Authrature)
     *
     *
     * @return string (encoded json)
     */
    public function sendTokenExchangeRequest(array $data)
    {
        //start variables preparation
        $client_id = Mage::helper('sign2pay')->getSign2payClientId();
        $client_secret = Mage::helper('sign2pay')->getSign2payClientSecret();
        $state = Mage::getSingleton('checkout/session')->getSign2PayUserHash();
        $code = $data['code'];
        $redirect_uri = Mage::helper('sign2pay')->getRedirectUri();

        $request_body = array(
            'client_id' => $client_id,
            'state' => $state,
            'code' => $code,
            'redirect_uri' => $redirect_uri
        );
        //end variables preparation


        /*==========================================start request preparation==========================*/
        $client = new Varien_Http_Client('https://app.sign2pay.com/oauth/token');
        $client->setMethod(Varien_Http_Client::POST);

        $client->setAuth($client_id,$client_secret);
        $client->setParameterPost($request_body);

        try{
            $response = $client->request();
            return $response->getBody();
        } catch (Zend_Http_Client_Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Exchange token for payment id (third step of Authrature)
     *
     *
     * @return string (encoded json)
     */
    public function sendPaymentRequest(array $data){
        //start variables preparation
        $client_id = Mage::helper('sign2pay')->getSign2payClientId();
        $client_secret = Mage::helper('sign2pay')->getSign2payClientSecret();

        $quote = Mage::helper('sign2pay')->getQuote();

        $ref_id = Mage::getSingleton('checkout/session')->getSign2PayCheckoutHash();

        $request_body = array(
            'client_id' => $client_id,
            'amount' => Mage::helper('sign2pay')->getPaymentAmount(),
            'ref_id' => $ref_id,
            'token' => $data['access_token']['token']
        );
        //end variables preparation

        /*==========================================start request preparation==========================*/
        $client = new Varien_Http_Client('https://app.sign2pay.com/api/v2/payment/authorize/capture');
        $client->setMethod(Varien_Http_Client::POST);

        $client->setAuth($client_id,$client_secret);
        $client->setParameterPost($request_body);
        try {
            $response = $client->request();
            return $response->getBody();
        } catch (Zend_Http_Client_Exception $e) {
            Mage::logException($e);
        }
    }


    /**
     * Get gateway data, validate and run corresponding handler
     *
     * @param array $request
     * @throws Exception
     * @todo Add gateway authentication
     * @todo Add order state validation
     */
    public function processPaymentCaptureResponse(array $request)
    {
        $this->_request = $request;

        $purchaseId = $this->getRequestData('purchase_id');
        Mage::getSingleton('checkout/session')->setPurchaseId($purchaseId);

        if ($this->_verifyResponse($purchaseId)) {
            // Payment was successful, so update the order's state
            // and send order email and move to the success page
            Mage::getSingleton('checkout/session')->setPurchaseId($purchaseId);
            // Register the payment capture
            $this->_registerPaymentCapture();
        } else {
            throw new Exception('Sorry, but we could not process your payment at this time.');
        }
    }

    /**
     * Cancel the order
     */
    public function cancel()
    {
        Mage::helper('sign2pay')->setStatusOnOrder($this->_order, 'Canceled');
        $this->_order->cancel()->save();

        return $this;
    }

    /**
     * Veritfy the gateway response
     *
     * @param string $apiKey
     * @param string $token
     * @param string $timestamp
     * @param string $signature
     * @return boolean
     */
    public function _verifyResponse($purchase_id)
    {
        $client_id = Mage::helper('sign2pay')->getSign2payClientId();
        $client_secret = Mage::helper('sign2pay')->getSign2payClientSecret();

        $client = new Varien_Http_Client('https://app.sign2pay.com/api/v2/payment/status/'.$purchase_id);
        $client->setMethod(Varien_Http_Client::GET);
        $client->setAuth($client_id,$client_secret);

        try{
            $response = $client->request();
            $body = json_decode($response->getBody());
            if(array_key_exists('status', $body) || $body['status'] == 'processing'){
                return true;
            }
            else{
                return false;
            }
        } catch (Zend_Http_Client_Exception $e) {
            Mage::logException($e);
            return false;
        }
    }

    /**
     * Process completed payment (either full or partial)
     */
    public function _registerPaymentCapture()
    {

        $session = $session = Mage::getSingleton('checkout/session');
        $purchaseId = $session->getPurchaseId();
        $this->_order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
        $payment = $this->_order->getPayment();

        $payment->setTransactionId($purchaseId)
            ->setCurrencyCode('EUR')
            ->setIsTransactionClosed(0)
            ->registerCaptureNotification(
                $this->getRequestData('amount') / 100
            );

        Mage::helper('sign2pay')->setStatusOnOrder($this->_order, Mage::getStoreConfig('payment/sign2pay/complete_order_status', Mage::app()->getStore()));
        $this->_order->save();

        // notify customer
        $invoice = $payment->getCreatedInvoice();
        if ($invoice && !$this->_order->getEmailSent()) {
            $this->_order->sendNewOrderEmail()->addStatusHistoryComment(
                Mage::helper('sign2pay')->__('Notified customer about invoice #%s.', $invoice->getIncrementId())
            )
            ->setIsCustomerNotified(true)
            ->save();
        }

        return $this;
    }
}
