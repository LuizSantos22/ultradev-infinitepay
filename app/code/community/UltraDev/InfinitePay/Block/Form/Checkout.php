<?php
class UltraDev_InfinitePay_Block_Form_Checkout extends Mage_Payment_Block_Form
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ultradev/infinitepay/form/checkout.phtml');
    }

    public function isPixEnabled(): bool
    {
        return Mage::helper('ultradev_infinitepay')->isPixEnabled();
    }

    /**
     * Percentual formatado (ex.: "5") ou string vazia quando não há desconto.
     */
    public function getPixDiscountLabel(): string
    {
        $helper   = Mage::helper('ultradev_infinitepay');
        $discount = $helper->getPixDiscount();
        return $discount > 0 ? $helper->formatPercent($discount) : '';
    }
}
