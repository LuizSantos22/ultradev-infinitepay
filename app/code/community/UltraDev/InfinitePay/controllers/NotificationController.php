<?php
class UltraDev_InfinitePay_NotificationController extends Mage_Core_Controller_Front_Action
{
    /**
     * Webhook de pagamento aprovado. A InfinitePay pede resposta em até ~1s:
     * respondemos 200 primeiro e só então confirmamos na API (payment_check).
     */
    public function webhookAction()
    {
        $helper = Mage::helper('ultradev_infinitepay');
        $raw    = (string) file_get_contents('php://input');
        $data   = json_decode($raw, true);

        $helper->log('Webhook recebido: ' . $raw);

        if (!$this->getRequest()->isPost() || !is_array($data)) {
            $this->getResponse()->setHttpResponseCode(400)->setBody('invalid');
            return;
        }

        $orderNsu = trim((string) ($data['order_nsu'] ?? ''));
        $txn      = trim((string) ($data['transaction_nsu'] ?? ''));
        $slug     = trim((string) ($data['invoice_slug'] ?? $data['slug'] ?? ''));

        if ($orderNsu === '') {
            $this->getResponse()->setHttpResponseCode(400)->setBody('missing order_nsu');
            return;
        }

        // Responde já; o processamento continua depois de liberar a conexão (PHP-FPM)
        $this->getResponse()->setHttpResponseCode(200)->setBody('ok');
        $this->getResponse()->sendResponse();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $this->getResponse()->clearBody();

        try {
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderNsu);
            if (!$order->getId()
                || $order->getPayment()->getMethod() !== UltraDev_InfinitePay_Helper_Data::METHOD_CODE
            ) {
                $helper->log('Webhook: pedido não encontrado ou de outro método: ' . $orderNsu, Zend_Log::WARN);
                return;
            }

            Mage::getModel('ultradev_infinitepay/processor')->process(
                $order,
                $txn,
                $slug,
                'webhook',
                ['receipt_url' => (string) ($data['receipt_url'] ?? '')]
            );
        } catch (Throwable $e) {
            $helper->log('Webhook: erro no pedido ' . $orderNsu . ': ' . $e->getMessage(), Zend_Log::ERR);
        }
    }
}
