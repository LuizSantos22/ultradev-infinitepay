<?php
/**
 * Lógica única de confirmação de pagamento, usada por retorno do cliente, webhook e cron.
 * Nunca confia nos parâmetros recebidos: sempre confirma na API (payment_check).
 */
class UltraDev_InfinitePay_Model_Processor
{
    /**
     * @return bool true se o pedido está pago ao final do processamento
     */
    public function process(
        Mage_Sales_Model_Order $order,
        string $transactionNsu = '',
        string $slug = '',
        string $source = 'return',
        array $extra = [],
        int $expireHours = 0
    ): bool {
        $h           = Mage::helper('ultradev_infinitepay');
        $incrementId = (string) $order->getIncrementId();

        if (!$h->acquireLock($incrementId)) {
            $h->log('Lock não obtido para o pedido ' . $incrementId, Zend_Log::WARN);
            return false;
        }

        try {
            $order = Mage::getModel('sales/order')->load((int) $order->getId());

            if ($order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                return in_array($order->getState(), [
                    Mage_Sales_Model_Order::STATE_PROCESSING,
                    Mage_Sales_Model_Order::STATE_COMPLETE,
                ], true);
            }

            $payment = $order->getPayment();
            if ($payment->getMethod() !== UltraDev_InfinitePay_Helper_Data::METHOD_CODE) {
                return false;
            }

            if ($transactionNsu === '') {
                $transactionNsu = (string) $payment->getAdditionalInformation('ip_transaction_nsu');
            } elseif ($payment->getAdditionalInformation('ip_transaction_nsu') !== $transactionNsu) {
                // guarda para o cron tentar de novo caso a verificação falhe agora
                $payment->setAdditionalInformation('ip_transaction_nsu', $transactionNsu)->save();
            }
            if ($slug === '') {
                $slug = (string) $payment->getAdditionalInformation('ip_slug');
            }

            $check = Mage::getModel('ultradev_infinitepay/api')->paymentCheck($incrementId, $transactionNsu, $slug);

            if (empty($check['paid'])) {
                if ($expireHours > 0 && strtotime((string) $order->getCreatedAt()) < time() - $expireHours * 3600) {
                    $order->cancel()
                        ->addStatusHistoryComment($h->__('Pedido cancelado: pagamento não confirmado pela InfinitePay em %d horas.', $expireHours))
                        ->save();
                    $h->log('Pedido ' . $incrementId . ' cancelado por expiração.');
                }
                return false;
            }

            // Sanidade de valor: paid_amount pode diferir de amount (taxas/desconto), então só barra valores abaixo do esperado
            $expected = $h->toCents((float) $order->getGrandTotal());
            $paid     = (int) ($check['paid_amount'] ?? $check['amount'] ?? 0);
            $discount = (($check['capture_method'] ?? '') === 'pix') ? $h->getPixDiscount($order->getStoreId()) : 0.0;
            $minimum  = (int) floor($expected * (1 - $discount / 100)) - 1;

            if ($paid > 0 && $paid < $minimum) {
                $h->log(sprintf('Valor divergente no pedido %s: pago=%d mínimo=%d', $incrementId, $paid, $minimum), Zend_Log::WARN);
                if ($order->canHold()) {
                    $order->hold();
                }
                $order->addStatusHistoryComment(
                    $h->__('InfinitePay informou pagamento de %s, abaixo do esperado (%s). Pedido em espera para conferência manual.', $h->formatCents($paid), $h->formatCents($minimum))
                )->save();
                return false;
            }

            $this->_register($order, $check, $transactionNsu, $slug, $source, $extra);
            return true;
        } finally {
            $h->releaseLock($incrementId);
        }
    }

    protected function _register(
        Mage_Sales_Model_Order $order,
        array $check,
        string $transactionNsu,
        string $slug,
        string $source,
        array $extra
    ): void {
        $h       = Mage::helper('ultradev_infinitepay');
        $payment = $order->getPayment();

        $txnId = $transactionNsu !== '' ? $transactionNsu : (string) ($check['transaction_nsu'] ?? '');
        if ($txnId === '') {
            $txnId = 'ip-' . $order->getIncrementId();
        }

        $method = (string) ($check['capture_method'] ?? '');

        $payment->setLastTransId($txnId);
        $payment->setAdditionalInformation('ip_transaction_nsu', $txnId);
        $payment->setAdditionalInformation('ip_capture_method', $method);
        $payment->setAdditionalInformation('ip_installments', (int) ($check['installments'] ?? 1));
        $payment->setAdditionalInformation('ip_amount', (int) ($check['amount'] ?? 0));
        $payment->setAdditionalInformation('ip_paid_amount', (int) ($check['paid_amount'] ?? 0));
        if ($slug !== '') {
            $payment->setAdditionalInformation('ip_slug', $slug);
        }
        $receipt = (string) ($extra['receipt_url'] ?? '');
        if ($receipt !== '' && strpos($receipt, 'https://') === 0) {
            $payment->setAdditionalInformation('ip_receipt_url', $receipt);
        }
        $payment->save();

        $status = (string) $h->getMethodConfig('order_status', $order->getStoreId());
        if ($status === '') {
            $status = 'processing';
        }

        $label = $method === 'pix' ? 'Pix' : ($method === 'credit_card' ? 'Cartão de crédito' : 'n/d');
        $order->setState(
            Mage_Sales_Model_Order::STATE_PROCESSING,
            $status,
            $h->__('Pagamento confirmado pela InfinitePay (%s, via %s). NSU: %s', $label, $source, $txnId),
            false
        );
        $order->save();

        if (!$order->hasInvoices() && $order->canInvoice()) {
            $invoice = $order->prepareInvoice();
            $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
            $invoice->setTransactionId($txnId);
            $invoice->register();
            $invoice->getOrder()->setIsInProcess(true);

            Mage::getModel('core/resource_transaction')
                ->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();
        }

        $h->log('Pedido ' . $order->getIncrementId() . ' pago via ' . $source . ' (' . $label . ').');
    }
}
