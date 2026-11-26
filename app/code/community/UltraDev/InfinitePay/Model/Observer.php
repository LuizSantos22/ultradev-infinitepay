<?php
class UltraDev_InfinitePay_Model_Observer
{
    /**
     * Cron a cada 15 min: reconfirma pedidos pendentes e cancela os expirados.
     */
    public function checkPendingOrders(): void
    {
        $helper    = Mage::helper('ultradev_infinitepay');
        $processor = Mage::getModel('ultradev_infinitepay/processor');

        $collection = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('state', Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('created_at', ['gt' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);

        $collection->getSelect()
            ->join(
                ['pay' => $collection->getTable('sales/order_payment')],
                'pay.parent_id = main_table.entity_id',
                []
            )
            ->where('pay.method = ?', UltraDev_InfinitePay_Helper_Data::METHOD_CODE);

        $collection->setPageSize(100);

        foreach ($collection as $order) {
            try {
                $payment = $order->getPayment();
                $hours   = (int) $helper->getMethodConfig('expire_hours', $order->getStoreId());

                $processor->process(
                    $order,
                    (string) $payment->getAdditionalInformation('ip_transaction_nsu'),
                    (string) $payment->getAdditionalInformation('ip_slug'),
                    'cron',
                    [],
                    $hours
                );
            } catch (Throwable $e) {
                $helper->log('Cron: erro no pedido ' . $order->getIncrementId() . ': ' . $e->getMessage(), Zend_Log::ERR);
            }
        }
    }
}
