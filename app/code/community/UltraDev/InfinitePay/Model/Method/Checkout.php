<?php
class UltraDev_InfinitePay_Model_Method_Checkout extends Mage_Payment_Model_Method_Abstract
{
    protected $_code                   = 'ultradev_infinitepay_checkout';
    protected $_formBlockType          = 'ultradev_infinitepay/form_checkout';
    protected $_infoBlockType          = 'ultradev_infinitepay/info_checkout';
    protected $_isGateway              = false;
    protected $_isInitializeNeeded     = true;
    protected $_canUseCheckout         = true;
    protected $_canUseInternal         = false;
    protected $_canUseForMultishipping = false;

    public function isAvailable($quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }

        $helper = Mage::helper('ultradev_infinitepay');
        if ($helper->getApiKey() === '' && $helper->getHandle() === '') {
            return false;
        }

        // Valor mínimo de R$ 1,00 no Checkout Integrado
        if ($quote && (float) $quote->getBaseGrandTotal() < 1) {
            return false;
        }

        return true;
    }

    /**
     * Pedido nasce em "pending_payment" e aguarda a confirmação da InfinitePay.
     */
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
        return $this;
    }

    /**
     * Após finalizar o pedido, o cliente é enviado para o controller que gera o link e redireciona.
     */
    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('ultradev-infinitepay/payment/redirect', ['_secure' => true]);
    }
}
