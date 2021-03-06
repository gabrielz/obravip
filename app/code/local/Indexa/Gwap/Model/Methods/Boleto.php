<?php

require_once 'Zend/Log.php';

/**
 * Indexa - Gwap Payment Module
 *
 * @title      Magento -> Custom Payment Module for Gwap
 * @category   Payment Gateway
 * @package    Indexa_Gwap
 * @author     Indexa Development Team -> desenvolvimento [at] indexainternet [dot] com  [dot] br
 * @copyright  Copyright (c) 2011 Indexa - http://www.indexainternet.com.br
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Indexa_Gwap_Model_Methods_Boleto extends Mage_Payment_Model_Method_Abstract {
    const PAYMENT_TYPE_AUTH = 'AUTHORIZATION';
    const PAYMENT_TYPE_SALE = 'SALE';

    protected $_code = 'gwap_boleto';
    protected $_formBlockType = 'gwap/form_boleto';
    protected $_infoBlockType = 'gwap/info_boleto';
    protected $_allowCurrencyCode = array('BRL', 'USD');
    protected $_canSaveCc = false;

    /**
     * Assign data to info model instance
     *
     * @param   mixed $data
     * @return  Mage_Payment_Model_Info
     */
    public function assignData($data) {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }

        $info = $this->getInfoInstance();
        $info->setGwapBoletoType($data->getGwapBoletoType());
        
        return $this;
    }

    /**
     * Using internal pages for input payment data
     *
     * @return bool
     */
    public function canUseInternal() {
        return false;
    }

    public function getConfig() {
        return new Varien_Object(Mage::getStoreConfig('payment/gwap_boleto'));
    }

    /**
     * Authorize
     *
     * @param   Varien_Object $orderPayment
     * @param float $amount
     * @return  Mage_Payment_Model_Abstract
     */
    public function authorize(Varien_Object $payment, $amount) {
        $config = mage::helper('gwap')->getConfig();
        $auth = mage::helper('gwap')->getAuthConfig();
        $gwap = Mage::getModel('gwap/order')->load($payment->getOrder()->getId(), 'order_id');
        $data = new Varien_Object(unserialize(Mage::helper('core')->decrypt($gwap->getInfo())));
        $order = $payment->getOrder();

        $url = '';
        if ($auth->getAmbiente() == 'LIVE') {
            $url = "https://ctpe.net/frontend/payment.prc";
        } elseif ($auth->getAmbiente() == 'CONNECTOR_TEST') {
            $url = "https://test.ctpe.net/frontend/payment.prc";
        }
                         
        $parameters = mage::helper('gwap')->setOrder($order)->prepareData( $data->getGwapBoletoType() );
        
        //prepare params
        foreach (array_keys($parameters) AS $key) {
            if (!isset($$key)) {
                $$key = '';
            }

            if (!isset($result)) {
                $result = '';
            }

            $$key .= $parameters[$key];
            $$key = urlencode($$key);
            $$key .= "&";

            $var = $key;
            
            $value = $$key;
            $result .= "$var=$value";
        }

        $strPOST = stripslashes($result);

        // open the request url for the Web Payment Frontend
        $cpt = curl_init();
        curl_setopt($cpt, CURLOPT_URL, $url);
        curl_setopt($cpt, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($cpt, CURLOPT_USERAGENT, "php ctpepost");
        curl_setopt($cpt, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($cpt, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($cpt, CURLOPT_POST, 1);
        curl_setopt($cpt, CURLOPT_POSTFIELDS, $strPOST);

        $curlresultURL = curl_exec($cpt);
        $curlerror = curl_error($cpt);
        $curlinfo = curl_getinfo($cpt);

        curl_close($cpt);
        
        $r_arr = explode("&", $curlresultURL);

        foreach ($r_arr AS $buf) {
            $temp = urldecode($buf);
            $temp = explode("=", $temp, 2);
            $postatt = $temp[0];
            $postvar = $temp[1];
            $returnvalue[$postatt] = $postvar;
        }
        
        if( !isset($returnvalue['PROCESSING.CODE']) ){
            Mage::throwException(Mage::helper('gwap')->__('Falha ao gerar boleto'));
            return $this;
        }
        $resultCode = explode('.', $returnvalue['PROCESSING.CODE']);

        // validate Pre authorization - 90 success code
        if ($resultCode[2] != '90') {
            Mage::throwException(Mage::helper('gwap')->__($returnvalue['PROCESSING.REASON'] . ' - ' . $returnvalue['PROCESSING.RETURN']));
            return $this;
        }

        if( $returnvalue['PROCESSING.CONNECTORDETAIL.EXTERNAL_SYSTEM_LINK'] ){
            $redirect_url = $returnvalue['PROCESSING.CONNECTORDETAIL.EXTERNAL_SYSTEM_LINK'];
        }else{
            $redirect_url = $returnvalue['PROCESSING.REDIRECT.URL'] . '?DC=' . $returnvalue['PROCESSING.REDIRECT.PARAMETER.DC'];
        }
        $gwap->setInfo($redirect_url);
        $gwap->save();

        return $this;
    }

    /**
     * Capture
     *
     * @param   Varien_Object $orderPayment
     * @param float $amount
     * @return  Mage_Payment_Model_Abstract
     */
    public function capture(Varien_Object $payment, $amount) {
       
        $gwap = Mage::getModel('gwap/order')->load($payment->getOrder()->getId(), 'order_id');

        $order = $payment->getOrder();
        $incrementId = $order->getIncrementId();
        
        $config = mage::helper('gwap')->getConfig();
        $auth = mage::helper('gwap')->getAuthConfig();
        
        $url = '';
        if ($auth->getAmbiente() == 'LIVE') {
            $url = "https://ctpe.io/payment/query";
        } elseif ($auth->getAmbiente() == 'CONNECTOR_TEST') {
            $url = "https://test.ctpe.io/payment/query";
        }
        
        $transaction_type = 'transaction_channel_'.$order->getPayment()->getGwapBoletoType();
        
        $transaction_type = trim( $config->getData($transaction_type) );        
        
        $captureParams  = '<Request version="1.0">';
        $captureParams .= '<Header>';
        $captureParams .= '<Security sender="' . $auth->getSecuritySender() . '"/>';
        $captureParams .= '</Header>';
        $captureParams .= '<Query mode="'.($auth->getAmbiente()).'" level="CHANNEL" entity="'.$transaction_type.'" type="STANDARD">';
           
        $captureParams .= '<User login="' . ($auth->getUserLogin()) . '" pwd="' . (strval(Mage::helper("core")->decrypt($auth->getUserPwd()))) . '"/>';
        $captureParams .= '<Identification>';
        $captureParams .= '<TransactionID>'.$incrementId.'</TransactionID>';//ff80808135ce84440135ed77c68e0b9f
        $captureParams .= '</Identification>';
        $captureParams .= '<Types>';
        $captureParams .= '<Type code="RC"/>';
        $captureParams .= '</Types>';
        $captureParams .= '</Query>';
        $captureParams .= '</Request>';
       
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_MUTE, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
"Content-Type: application/x-www-form-urlencoded;charset=UTF-8"
));
        curl_setopt($ch, CURLOPT_POSTFIELDS, "load=$captureParams");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $curlresultURL = curl_exec($ch);
        curl_close($ch);
        
        $result = new Varien_Simplexml_Config($curlresultURL);
         if( $result->getNode('Result')->getAttribute('count')  > 0 ){
              $resultCode =  $result->getNode('Result/Transaction');
              foreach ($resultCode as $rc){
                  $code =  $rc->children()->descend('Processing')->getAttribute('code');
              }
              
        }else{
            Mage::throwException(Mage::helper('gwap')->__('Pagamento não efetuado'));
            return $this;        
        }
        
        $resultCode = explode('.', $code);
        
        // validate Pre authorization - 90 success code
        if ($resultCode[2] != '90') {
            Mage::throwException(Mage::helper('gwap')->__('Pagamento não efetuado'));
            return $this;
        }
        
        return $this;        
        
    }

    /**
     * Using for multiple shipping address
     *
     * @return bool
     */
    public function canUseForMultishipping() {
        return false;
    }

    /**
     *  check if capture is available
     * 
     * @return bool
     */
    public function canCapture() {
        return true;
    }

    /**
     * Using for multiple shipping address
     *
     */
    public function createFormBlock($name) {
        $block = $this->getLayout()->createBlock($this->_formBlockType, $name)
                ->setMethod('gwap_boleto')
                ->setPayment($this->getPayment())
                ->setTemplate('gwap/boleto/form.phtml');

        return $block;
    }

    /**
     * Get Frontend name by Code
     *
     * @return frontendNameString or $code if not found
     */
    public function getFrontendName($code) {
        foreach ($this->_isParcelamento["frontend"] as $arCode => $nFrontend)
            if ($code == $arCode)
                return $nFrontend;

        foreach ($this->_isBoleto["frontend"] as $arCode => $nFrontend)
            if ($code == $arCode)
                return $nFrontend;

        foreach ($this->_isDebito["frontend"] as $arCode => $nFrontend)
            if ($code == $arCode)
                return $nFrontend;

        return $code;
    }

    /**
     * Get gwap session namespace
     *
     * @return Indexa_Gwap_Model_Session
     */
    public function getSession() {
        return Mage::getSingleton('gwap/session');
    }

    /**
     * Get checkout session namespace
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckout() {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get current quote
     *
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote() {
        return $this->getCheckout()->getQuote();
    }

    /**
     * Some process when invoice is created
     * 
     * @param Mage_Sales_Model_Invoice_Payment $payment
     * @return Indexa_Gwap_Model_Methods_boleto
     */
    public function onInvoiceCreate(Mage_Sales_Model_Invoice_Payment $payment) {
        return $this;
    }

    /**
     * Some process when order is validated
     * 
     * @param Mage_Sales_Model_Invoice_Payment $payment
     * @return Indexa_Gwap_Model_Methods_boleto
     */
    public function onOrderValidate(Mage_Sales_Model_Order_Payment $payment) {
        return $this;
    }

    /*
     * Validate
     */

    public function validate() {
        parent::validate();
    }

}