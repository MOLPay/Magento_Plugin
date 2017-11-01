<?php
/**
 * MOLPay Sdn. Bhd.
 *
 * @package     MOLPay Magento Plugin
 * @author      netbuilder <code@netbuilder.com.my>
 * @copyright   Copyright (c) 2012 - 2016, MOLPay
 * @link        http://molpay.com
 * @since       Version 1.9.x.x
 * @update      MOLPay <technical@molpay.com>
 * @filesource  https://github.com/MOLPay/Magento_Plugin
 */

class Mage_MOLPay_PaymentMethodController extends Mage_Core_Controller_Front_Action {
    //Order instance
    protected $_order;

    /**
     * When a customer chooses MOLPay on Checkout/Payment page
     * 
     */
    public function redirectAction() { 
        $this->getResponse()->setBody($this->getLayout()->createBlock('molpay/paymentmethod_redirect')->toHtml());
    }
  
    /**
     * When MOLPay return the order information at this point is in POST variables
     * 
     * @return boolean
     */
    public function successAction() {
        if( !$this->getRequest()->isPost() ) {
            $this->_redirect('');
            return;
        }
        
        $this->_ack($P); 
        $P = $this->getRequest()->getPost();
        $TypeOfReturn = "ReturnURL";
        $etcAmt ='';
        
        $order = Mage::getModel('sales/order')->loadByIncrementId( $P['orderid'] );
        $orderId = $order->getId();
        $order_status = $order->getStatus();
        $N = Mage::getModel('molpay/paymentmethod');
        $core_session = Mage::getSingleton('core/session');
        
        if(!isset($orderId)){
            //Mage::throwException($this->__('Order identifier is not valid!'));
            $this->_redirect('checkout/cart');
            return;
        }else if( $order->getPayment()->getMethod() !=="molpay" ) {
            //Mage::throwException($this->__('Payment Method is not MOLPay !'));
            $this->_redirect('checkout/cart');
            return;                
        }else if(ucfirst($order_status)=="Processing"){ 
            /* 16 Jun 2016
            * check status. if status PROCESSING, we cancel the order(do Exception). 
            * To avoid replacement status from processing to cancelled due the same Order ID.
            * This happen maybe customer click 'Place Order' 2 times or 
            * unfortunately their internet connection too slow.  
            */
            //Mage::throwException($this->__('Order has been paid!'));
            $this->removeCartItems();
            $this->_redirect('checkout/onepage/success');
            return;
        }else if(ucfirst($order_status)=="Canceled"){
            //Mage::throwException($this->__('Order has been canceled!')); 
            Mage::getSingleton('core/session')->getMessages(true);
            $core_session->addError('Payment Failed. Please proceed with checkout to try again.');
            $this->_redirect('checkout/cart'); 
            return;
        }else{  
            if( $P['status'] !== '00' ) {
                if($P['status'] == '22') { 
                    $this->updateOrderStatus($order, $P, $etcAmt, $TypeOfReturn, "PENDING");
                    $order->save();
                    
                    $this->removeCartItems();
                    $this->_redirect('checkout/onepage/success'); 
                } else {
                    $this->updateOrderStatus($order, $P, $etcAmt, $TypeOfReturn, "FAILED");
                    $order->save();
                    
                    Mage::getSingleton('core/session')->getMessages(true);
                    $core_session->addError('Payment Failed. Please proceed with checkout to try again.');
                    Mage::app()->getFrontController()->getResponse()->setRedirect(Mage::getUrl('checkout/cart'));

                }
                return;
            }else if( $P['status'] === '00' && $this->_matchkey( $N->getConfigData('encrytype') , $N->getConfigData('login') , $N->getConfigData('transkey'), $P )) {
                $currency_code = $order->getOrderCurrencyCode(); 
                if( $currency_code !=="MYR" ) {
                    $amount = $N->MYRtoXXX( $P['amount'] ,  $currency_code );
                    $etcAmt = "  <b>( $currency_code $amount )</b>";
                    if( $order->getBaseGrandTotal() > $amount ) {
                        $order->addStatusToHistory( $order->getStatus(), "Amount order is not valid!" );
                    }
                } 

                $order->getPayment()->setTransactionId( $P['tranID'] );

                if($this->_createInvoice($order,$N,$P,$TypeOfReturn)) {
                    $order->sendNewOrderEmail();
                }
                
                $order->save();
                $this->removeCartItems();
                $this->_redirect('checkout/onepage/success');
                return;

            } else {
                $order->setState(
                    Mage_Sales_Model_Order::STATUS_FRAUD,
                    Mage_Sales_Model_Order::STATUS_FRAUD,
                    'Payment Error: Signature key not match' . "\n<br>Amount: " . $P['currency'] . " " . $P['amount'] . $etcAmt . "\n<br>PaidDate: " . $P['paydate'],
                    $notified = true
                );
                $order->save();
                $this->_redirect('checkout/cart');
                return;
            }
        }  
    }
    
    public function notificationAction() {
        $P = $this->getRequest()->getPost();
        $TypeOfReturn = "NotificationURL";
        $etcAmt='';
        

        if($P['nbcb'] == 2) {
            $order = Mage::getModel('sales/order')->loadByIncrementId( $P['orderid'] );
            $orderId = $order->getId();
            $N = Mage::getModel('molpay/paymentmethod');
            
            if(!isset($orderId)){
                Mage::throwException($this->__('Order identifier is not valid!'));
                return false;
            }elseif( $order->getPayment()->getMethod() !=="molpay" ) {
                Mage::throwException($this->__('Payment Method is not MOLPay !'));
                return false;               
            }else if(ucfirst($order_status)=="Processing"){
                // Order has been placed. To avoid overide PROCESSING to FAILED
                
                return false; 
            }else{ 
                if( $P['status'] !== '00' ) {
                    if($P['status'] == '22') {
                        $this->updateOrderStatus($order, $P, $etcAmt, $TypeOfReturn, "PENDING");
                        $order->save();
                    } else {
                        $this->updateOrderStatus($order, $P, $etcAmt, $TypeOfReturn, "FAILED");
                        $order->save();
                    }
                    return;
                }else if ( $P['status'] === '00' && $this->_matchkey( $N->getConfigData('encrytype') , $N->getConfigData('login') , $N->getConfigData('transkey'), $P )) {
                    $currency_code = $order->getOrderCurrencyCode();
                    if($currency_code !=="MYR") {
                        $amount= $N->MYRtoXXX( $P['amount'] ,  $currency_code );
                        $etcAmt = "  <b>( $currency_code $amount )</b>";
                        if( $order->getBaseGrandTotal() > $amount ) {
                            $order->addStatusToHistory($order->getStatus(), "Amount order is not valid!");
                        }
                    }

                    $order->getPayment()->setTransactionId( $P['tranID'] );   
                    try{
                        if($this->_createInvoice($order,$N,$P,$TypeOfReturn)) {
                            $order->sendNewOrderEmail();
                        }
                    }catch (Mage_Core_Exception $e){
                        Mage::logException($e);
                    }
                    
                    if($order->hasInvoices() && ($order->getStatus() === Mage_Sales_Model_Order::STATUS_FRAUD)){
                        $order->setState(
                                    Mage_Sales_Model_Order::STATE_PROCESSING,
                                    Mage_Sales_Model_Order::STATE_PROCESSING
                                );
                    }

                    $order->save();
                    return;
                }else {  
                    $order->setState(
                            Mage_Sales_Model_Order::STATUS_FRAUD,
                            Mage_Sales_Model_Order::STATUS_FRAUD,
                            'Payment Error: Signature key not match'
                            . "\n<br>TransactionID: " . $P['tranID']
                            . "\n<br>Amount: " . $P['currency'] . " " . $P['amount'] . $etcAmt 
                            . "\n<br>PaidDate: " . $P['paydate'],
                            $notified = true );
                    $order->save();
                    return;
                }
            }
        }
        
        exit;
    }
  
    public function callbackAction() { 
        $P = $this->getRequest()->getPost();
        echo "CBTOKEN:MPSTATOK";
        $TypeOfReturn = "CallbackURL";
        $etcAmt='';
        
        if($P['nbcb'] == 1) {
            $order = Mage::getModel('sales/order')->loadByIncrementId( $P['orderid'] );
            $orderId = $order->getId();
            $N = Mage::getModel('molpay/paymentmethod');
            
            if(!isset($orderId)){
                Mage::throwException($this->__('Order identifier is not valid!'));
                return false;
            }elseif( $order->getPayment()->getMethod() !=="molpay" ) {
                Mage::throwException($this->__('Payment Method is not MOLPay !'));
                return false;               
            }else if(ucfirst($order_status)=="Processing"){
                // Order has been placed. To avoid overide PROCESSING to FAILED
            }else{

                if( $P['status'] !== '00' ) {
                    if($P['status'] == '22') {
                        $this->updateOrderStatus($order, $P, $etcAmt, $TypeOfReturn, "PENDING");
                        $order->save();
                    } else {
                        $this->updateOrderStatus($order, $P, $etcAmt, $TypeOfReturn, "FAILED");
                        $order->save();
                    }
                    return;
                }else if( $P['status'] === '00' && $this->_matchkey( $N->getConfigData('encrytype') , $N->getConfigData('login') , $N->getConfigData('transkey'), $P )) {
                    $currency_code = $order->getOrderCurrencyCode();
                    if( $currency_code !=="MYR" ){
                        $amount= $N->MYRtoXXX( $P['amount'] ,  $currency_code );
                        $etcAmt = "  <b>( $currency_code $amount )</b>";
                        if( $order->getBaseGrandTotal() > $amount ) {
                            $order->addStatusToHistory($order->getStatus(), "Amount order is not valid!");
                        }
                    } 

                    $order->getPayment()->setTransactionId( $P['tranID'] );            
                    try{
                        if($this->_createInvoice($order,$N,$P,$TypeOfReturn)) {
                            $order->sendNewOrderEmail();
                        }
                    }catch (Mage_Core_Exception $e){
                        Mage::logException($e);
                    }
                    
                    if($order->hasInvoices() && ($order->getStatus() === Mage_Sales_Model_Order::STATUS_FRAUD)){
                        $order->setState(
                                    Mage_Sales_Model_Order::STATE_PROCESSING,
                                    Mage_Sales_Model_Order::STATE_PROCESSING
                                );
                    }
                    
                    $order->save();
                    return;

                } else {  
                    $order->setState(
                            Mage_Sales_Model_Order::STATUS_FRAUD,
                            Mage_Sales_Model_Order::STATUS_FRAUD,
                            'Payment Error: Signature key not match'
                            . "\n<br>TransactionID: " . $P['tranID']
                            . "\n<br>Amount: " . $P['currency'] . " " . $P['amount'] . $etcAmt 
                            . "\n<br>PaidDate: " . $P['paydate'],
                            $notified = true );
                    $order->save();
                    return;
                }
            }
        }
        exit;
    }
    
    public function failureAction() {       
        $this->loadLayout();
        $this->renderLayout();
    } 
    
    public function payAction() {
        $this->getResponse()->setBody( $this->getLayout()->createBlock('molpay/paymentmethod_redirect')->toHtml() );
    }
    
    
    
    
    
    
    
    
    /* Function -------------------------------------------------------------------------------------------------------- */
    protected function _matchkey( $entype, $merchantID , $vkey , $P ) {
        $enf = ( $entype == "sha1" )? "sha1" : "md5";           
        $skey = $enf( $P['tranID'].$P['orderid'].$P['status'].$merchantID.$P['amount'].$P['currency'] );
        $skey = $enf( $P['paydate'].$merchantID.$skey.$P['appcode'].$vkey   );
        return ( $skey === $P['skey'] )? 1 : 0;
    }
  
    // Creating Invoice : Convert order into invoice
    protected function _createInvoice(Mage_Sales_Model_Order $order,$N,$P,$TypeOfReturn) {
        /*if( $order->canInvoice() && ($order->hasInvoices() < 1));
            else 
        return false;
        */
        
        if($order->hasInvoices() < 1){
            $invoice =  Mage::getModel('sales/service_order', $order)->prepareInvoice();
            $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
            $invoice->register();
            Mage::getModel('core/resource_transaction')
                ->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();
        }

        $order->setState(
            Mage_Sales_Model_Order::STATE_PROCESSING,
            Mage_Sales_Model_Order::STATE_PROCESSING,
                "Response from MOLPAY - ".$TypeOfReturn." (CAPTURED)"
                . "\n<br>Invoice #".$invoice->getIncrementId().""
                . "\n<br>Amount: ".$P['currency']." ".$P['amount'].$etcAmt
                . "\n<br>AppCode: " .$P['appcode']
                . "\n<br>Skey: " . $P['skey']
                . "\n<br>TransactionID: " . $P['tranID']
                . "\n<br>Status: " . $P['status']
                . "\n<br>PaidDate: " . $P['paydate']
                ,
                true
        );
        return true;               
    }
    
    // Send acknowlodge to MOLPay server
    public function _ack($P) {
        $P['treq'] = 1;
        while ( list($k,$v) = each($P) ) {
          $postData[]= $k."=".$v;
        }
        $postdata   = implode("&",$postData);
        $url        = "https://www.onlinepayment.com.my/MOLPay/API/chkstat/returnipn.php";
        $ch         = curl_init();
        curl_setopt($ch, CURLOPT_POST           , 1     );
        curl_setopt($ch, CURLOPT_POSTFIELDS     , $postdata );
        curl_setopt($ch, CURLOPT_URL            , $url );
        curl_setopt($ch, CURLOPT_HEADER         , 1  );
        curl_setopt($ch, CURLINFO_HEADER_OUT    , TRUE   );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER , 1  );
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER , FALSE);
        $result = curl_exec( $ch );
        curl_close( $ch );
        return;
    }

    // Remove shopping cart items
    public function removeCartItems(){
        $session = Mage::getSingleton('checkout/session');

        foreach( $session->getQuote()->getItemsCollection() as $item ){
            Mage::getSingleton('checkout/cart')->removeItem( $item->getId() )->save();
        }
        
        return;
    }
    
    // Update order status 
    public function updateOrderStatus($order, $P, $etcAmt, $TypeOfReturn, $status){
        
        if($status == "PENDING"){
            $status_update = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        }elseif($status == "FAILED"){
            $status_update = Mage_Sales_Model_Order::STATE_CANCELED;
        }else{
            $status_update = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        }
        
        try{
            $order->setState(
                $status_update,
                $status_update,
                'Response from MOLPAY - ' .$TypeOfReturn. ' (' .$status. ')'
                . "\n<br>TransactionID: " . $P['tranID']
                . "\n<br>Amount: " . $P['currency'] . " " . $P['amount'] . $etcAmt 
                . "\n<br>PaidDate: " . $P['paydate']
                ,
                $notified = true ); 
                
        } catch (Mage_Core_Exception $e) {
            Mage::logException($e);
        }
    }
    
    public function checklogin() {
        $U = Mage::getSingleton('customer/session');
        if( !$U->isLoggedIn() ) {
            $this->_redirect('customer/account/login');
            return false;
        }       
        return true;
    }
}
