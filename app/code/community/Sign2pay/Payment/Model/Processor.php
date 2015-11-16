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
     * Exchange hashed credentials for token (second step of Authrature)
     *
     * 
     * @return string (encoded json)
     */
    public function processTokenExchangeRequest(array $data){
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
    public function processPaymentRequest(array $data){
        //start variables preparation
        $client_id = Mage::helper('sign2pay')->getSign2payClientId();
        $client_secret = Mage::helper('sign2pay')->getSign2payClientSecret();

        $quote = Mage::getSingleton('checkout/session')->getQuote();
        $amount = $quote['grand_total']*100;
        
        $ref_id = Mage::getSingleton('checkout/session')->getSign2PayCheckoutHash();

        $request_body = array(
            'client_id' => $client_id,
            'amount' => $amount,
            'ref_id' => $ref_id,
            'token' => $data['access_token']['token']
        );
        //end variables preparation


        /*==========================================start request preparation==========================*/
        $client = new Varien_Http_Client('https://app.sign2pay.com/api/v2/payment/authorize/capture');
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


    /*
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

        $purchase_id = $this->getRequestData('purchase_id');
        Mage::getSingleton('checkout/session')->setPurchaseId($purchase_id);
        
        // Load appropriate order
        $this->_order = Mage::getModel('sales/order')->loadByIncrementId($orderId);

        if (!$this->_order->getId()) {
            throw new Exception('Requested order with id ' . $orderId . ' does not exists.');
        }

        $result = array();

        $status = $this->_verifyResponse($purchase_id);
        
        if ($status) {
            if ($status == 'processing') {
                // Payment was successful, so update the order's state
                // and send order email and move to the success page
                $result['status'] = 'success';
                $result['redirect_to'] = Mage::getBaseUrl() . 'sign2pay/payment/success';
                $result['params'] = array(
                    'purchase_id'   => $purchaseId,
                    'status'        => $status
                );
                Mage::getSingleton('checkout/session')->setPurchaseId($purchase_id);
                // Register the payment capture
                $this->_registerPaymentCapture();
            } else {
                // Register the payment failure
                $this->_registerPaymentDenial();
            }
        } else {
            // Register the payment failure
            $this->_registerPaymentFailure();
        }

        if (!$result) {
            // There is a problem in the response we got
            $result['status'] = 'failure';
            $result['redirect_to'] = Mage::getBaseUrl() . 'sign2pay/payment/failure';
            $result['params'] = array(
                'ref_id'    => $orderId,
                'message'   => Mage::helper('sign2pay')->__('Sorry, but we could not process your payment at this time.'),
            );
        }

        return $result;
    }

    /**
     * Cancel the order
     *
     * @param Mage_Sales_Model_Order $order
     */
    public function cancel(Mage_Sales_Model_Order $order)
    {
        $order->cancel()->save();

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
            if(array_key_exists('error', $body) || !array_key_exists('status') || $body['status'] == 'completed'){
                return false;
            }
            else{
                return $body['status'];
            }            
        } catch (Zend_Http_Client_Exception $e) {
            Mage::log('exception');
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

    /**
     * Treat failed payment as order cancellation
     */
    protected function _registerPaymentFailure()
    {
        $this->_order
            ->registerCancellation(
                Mage::helper('sign2pay')->__('There was a problem with Sign2pay payment.'),
                false
            )
            ->save();

        return $this;
    }

    /**
     * Process denied payment notification
     */
    protected function _registerPaymentDenial()
    {
        $this->_order->getPayment()
            ->setTransactionId($this->getRequestData('purchase_id'))
            ->setNotificationResult(true)
            ->setIsTransactionClosed(true)
            ->registerPaymentReviewAction(Mage_Sales_Model_Order_Payment::REVIEW_ACTION_DENY, false);
        $this->_order->save();

        return $this;
    }
}
