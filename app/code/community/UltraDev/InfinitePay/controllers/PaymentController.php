<?php
class UltraDev_InfinitePay_PaymentController extends Mage_Core_Controller_Front_Action
{
    /**
     * Gera o link (uma única vez por pedido) e redireciona para a página de pagamento da InfinitePay.
     * Sem parâmetros: usa o último pedido da sessão. Com ?order=<increment_id>&key=<protect_code>: reabre o pagamento.
     */
    public function redirectAction()
    {
        $helper       = Mage::helper('ultradev_infinitepay');
        $session      = Mage::getSingleton('checkout/session');
        $fromCheckout = false;

        $incrementId = trim((string) $this->getRequest()->getParam('order'));
        if ($incrementId === '') {
            $incrementId  = (string) $session->getLastRealOrderId();
            $fromCheckout = true;
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
        if (!$order->getId() || $order->getPayment()->getMethod() !== UltraDev_InfinitePay_Helper_Data::METHOD_CODE) {
            $this->_redirect('checkout/cart');
            return;
        }

        if (!$fromCheckout) {
            $key = (string) $this->getRequest()->getParam('key');
            if ($key === '' || !hash_equals((string) $order->getProtectCode(), $key)) {
                $this->_redirect('');
                return;
            }
        }

        if ($order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            $session->addNotice($helper->__('Este pedido não está aguardando pagamento.'));
            $this->_redirect('');
            return;
        }

        $payment = $order->getPayment();
        $url     = (string) $payment->getAdditionalInformation('ip_checkout_url');

        if ($url === '') {
            try {
                $link = Mage::getModel('ultradev_infinitepay/api')->createLink($order);
                $url  = $link['url'];

                $payment->setAdditionalInformation('ip_checkout_url', $url);
                $payment->setAdditionalInformation('ip_slug', $link['slug']);
                $payment->save();

                $order->addStatusHistoryComment($helper->__('Link de pagamento InfinitePay gerado.'))->save();
            } catch (Throwable $e) {
                $helper->log('Falha ao gerar link do pedido ' . $order->getIncrementId() . ': ' . $e->getMessage(), Zend_Log::ERR);
                $this->_fail($order, $fromCheckout, $e->getMessage());
                return;
            }
        }

        if (strpos($url, 'https://') !== 0) {
            $this->_fail($order, $fromCheckout, $helper->__('Link de pagamento inválido.'));
            return;
        }

        $this->_redirectUrl($url);
    }

    /**
     * Retorno do cliente (redirect_url). Os parâmetros da URL NÃO são prova de pagamento:
     * a confirmação sempre passa pelo payment_check.
     */
    public function returnAction()
    {
        $helper   = Mage::helper('ultradev_infinitepay');
        $request  = $this->getRequest();
        $session  = Mage::getSingleton('checkout/session');
        $orderNsu = trim((string) $request->getParam('order_nsu'));

        $order = Mage::getModel('sales/order')->loadByIncrementId($orderNsu);
        if ($orderNsu === '' || !$order->getId()
            || $order->getPayment()->getMethod() !== UltraDev_InfinitePay_Helper_Data::METHOD_CODE
        ) {
            $this->_redirect('');
            return;
        }

        $pendingMessage = $helper->__('Recebemos seu pedido. Assim que a InfinitePay confirmar o pagamento, o status será atualizado.');

        try {
            $paid = Mage::getModel('ultradev_infinitepay/processor')->process(
                $order,
                (string) $request->getParam('transaction_nsu'),
                (string) $request->getParam('slug'),
                'retorno',
                ['receipt_url' => (string) $request->getParam('receipt_url')]
            );
            if (!$paid) {
                $session->addNotice($pendingMessage);
            }
        } catch (Throwable $e) {
            $helper->log('Retorno: erro no pedido ' . $order->getIncrementId() . ': ' . $e->getMessage(), Zend_Log::ERR);
            $session->addNotice($pendingMessage);
        }

        $session->setLastQuoteId($order->getQuoteId())
            ->setLastSuccessQuoteId($order->getQuoteId())
            ->setLastOrderId($order->getId())
            ->setLastRealOrderId($order->getIncrementId());

        $this->_redirect('checkout/onepage/success');
    }

    protected function _fail(Mage_Sales_Model_Order $order, bool $fromCheckout, string $message): void
    {
        $session = Mage::getSingleton('checkout/session');

        if ($fromCheckout) {
            try {
                $order->cancel()
                    ->addStatusHistoryComment('Pedido cancelado: falha ao gerar o link de pagamento InfinitePay.')
                    ->save();

                $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
                if ($quote->getId()) {
                    $quote->setIsActive(1)->setReservedOrderId(null)->save();
                    $session->replaceQuote($quote);
                }
            } catch (Throwable $e) {
                Mage::helper('ultradev_infinitepay')->log('Falha ao restaurar carrinho: ' . $e->getMessage(), Zend_Log::ERR);
            }
        }

        $session->addError($message);
        $this->_redirect('checkout/cart');
    }
}
