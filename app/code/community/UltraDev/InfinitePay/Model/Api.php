<?php
/**
 * Comunicação com o Checkout Integrado da InfinitePay
 *   POST https://api.checkout.infinitepay.io/links
 *   POST https://api.checkout.infinitepay.io/payment_check
 */
class UltraDev_InfinitePay_Model_Api
{
    protected function _helper(): UltraDev_InfinitePay_Helper_Data
    {
        return Mage::helper('ultradev_infinitepay');
    }

    /**
     * Cria o link de checkout para o pedido.
     *
     * @return array{url:string, slug:string}
     */
    public function createLink(Mage_Sales_Model_Order $order): array
    {
        $h       = $this->_helper();
        $payload = $this->_buildPayload($order);

        $h->log('POST /links payload=' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $response = $this->_post('/links', $payload, true);

        $url = (string) ($response['url'] ?? $response['checkout_url'] ?? $response['link'] ?? '');
        if ($response['_http_status'] < 200 || $response['_http_status'] >= 300 || $url === '') {
            $h->log('createLink falhou. HTTP=' . $response['_http_status'] . ' resposta=' . $response['_raw'], Zend_Log::ERR);
            Mage::throwException($h->__('Não foi possível iniciar o pagamento com a InfinitePay. Tente novamente ou escolha outra forma de pagamento.'));
        }

        $slug = (string) ($response['slug'] ?? '');
        if ($slug === '') {
            $slug = (string) basename((string) parse_url($url, PHP_URL_PATH));
        }

        return ['url' => $url, 'slug' => $slug];
    }

    /**
     * Consulta o status do pagamento. Respostas 4xx retornam paid=false; falha de rede/5xx lança exceção.
     */
    public function paymentCheck(string $orderNsu, string $transactionNsu = '', string $slug = ''): array
    {
        $h    = $this->_helper();
        $body = ['order_nsu' => $orderNsu];

        if ($h->getHandle() !== '') {
            $body['handle'] = $h->getHandle();
        }
        if ($transactionNsu !== '') {
            $body['transaction_nsu'] = $transactionNsu;
        }
        if ($slug !== '') {
            $body['slug'] = $slug;
        }

        $response = $this->_post('/payment_check', $body, false);
        $h->log('POST /payment_check body=' . json_encode($body) . ' HTTP=' . $response['_http_status'] . ' resposta=' . $response['_raw']);

        if ($response['_http_status'] >= 500 || $response['_http_status'] === 0) {
            Mage::throwException('payment_check indisponível (HTTP ' . $response['_http_status'] . ')');
        }
        if ($response['_http_status'] >= 400) {
            $response['paid'] = false;
        }

        return $response;
    }

    // ------------------------------------------------------------------
    // Payload
    // ------------------------------------------------------------------

    protected function _buildPayload(Mage_Sales_Model_Order $order): array
    {
        $h     = $this->_helper();
        $store = Mage::app()->getStore($order->getStoreId());

        $payload = [
            'order_nsu'    => (string) $order->getIncrementId(),
            'items'        => $this->_buildItems($order),
            'redirect_url' => $store->getUrl('ultradev-infinitepay/payment/return', ['_secure' => true]),
            'webhook_url'  => $store->getUrl('ultradev-infinitepay/notification/webhook', ['_secure' => true]),
        ];

        // Com API Key a InfiniteTag não é necessária; sem ela, o handle identifica a conta.
        if ($h->getApiKey() === '') {
            $payload['handle'] = $h->getHandle();
        }

        $customer = $this->_buildCustomer($order);
        if ($customer) {
            $payload['customer'] = $customer;
        }

        if ((bool) $h->getMethodConfig('send_address', $order->getStoreId())) {
            $address = $this->_buildAddress($order);
            if ($address) {
                $payload['address'] = $address;
            }
        }

        $options = $this->_buildOptions($order);
        if ($options) {
            $payload['options'] = $options;
        }

        return $payload;
    }

    /**
     * Itens em centavos. Cada linha do pedido vira 1 item (qtd 1) já com imposto e desconto,
     * mais frete e um item de ajuste caso o total não feche com o grand total do pedido.
     */
    protected function _buildItems(Mage_Sales_Model_Order $order): array
    {
        $h     = $this->_helper();
        $items = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $qty = max(1, (int) $item->getQtyOrdered());

            $rowTotal = (float) $item->getRowTotalInclTax();
            if ($rowTotal <= 0) {
                $rowTotal = (float) $item->getRowTotal() + (float) $item->getTaxAmount();
            }
            $rowTotal -= (float) $item->getDiscountAmount();

            $cents = $h->toCents($rowTotal);
            if ($cents <= 0) {
                continue;
            }

            $description = (string) $item->getName() . ($qty > 1 ? ' (x' . $qty . ')' : '');
            $items[] = [
                'quantity'    => 1,
                'price'       => $cents,
                'description' => mb_substr($description, 0, 120),
            ];
        }

        $shipping = (float) $order->getShippingInclTax() - (float) $order->getShippingDiscountAmount();
        if ($shipping > 0) {
            $items[] = [
                'quantity'    => 1,
                'price'       => $h->toCents($shipping),
                'description' => 'Frete',
            ];
        }

        if (!$items) {
            Mage::throwException($h->__('Pedido sem itens válidos para pagamento.'));
        }

        // Reconciliação com o grand total
        $total = $h->toCents((float) $order->getGrandTotal());
        $sum   = array_sum(array_column($items, 'price'));
        $diff  = $total - $sum;

        if ($diff > 0) {
            $items[] = ['quantity' => 1, 'price' => $diff, 'description' => 'Taxas e ajustes'];
        } elseif ($diff < 0) {
            $largest = 0;
            foreach ($items as $i => $row) {
                if ($row['price'] > $items[$largest]['price']) {
                    $largest = $i;
                }
            }
            $items[$largest]['price'] = max(1, $items[$largest]['price'] + $diff);
        }

        if (array_sum(array_column($items, 'price')) !== $total) {
            $h->log('Total dos itens difere do grand total do pedido ' . $order->getIncrementId(), Zend_Log::WARN);
        }

        return $items;
    }

    protected function _buildCustomer(Mage_Sales_Model_Order $order): array
    {
        $billing  = $order->getBillingAddress();
        $customer = [];

        $name = trim((string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname());
        if ($name === '' && $billing) {
            $name = trim($billing->getFirstname() . ' ' . $billing->getLastname());
        }
        if ($name !== '') {
            $customer['name'] = $name;
        }
        if ($order->getCustomerEmail()) {
            $customer['email'] = (string) $order->getCustomerEmail();
        }

        if ($billing) {
            $digits = preg_replace('/\D+/', '', (string) $billing->getTelephone());
            if (in_array(strlen($digits), [10, 11], true)) {
                $customer['phone_number'] = '+55' . $digits;
            } elseif (in_array(strlen($digits), [12, 13], true) && strpos($digits, '55') === 0) {
                $customer['phone_number'] = '+' . $digits;
            }
        }

        return $customer;
    }

    protected function _buildAddress(Mage_Sales_Model_Order $order): array
    {
        $addr = $order->getShippingAddress() ?: $order->getBillingAddress();
        if (!$addr) {
            return [];
        }

        $street = $addr->getStreet();
        $cep    = preg_replace('/\D+/', '', (string) $addr->getPostcode());
        if (strlen($cep) !== 8 || empty($street[0])) {
            return [];
        }

        $address = [
            'cep'    => $cep,
            'street' => (string) $street[0],
        ];
        if (!empty($street[1])) {
            $address['number'] = (string) $street[1];
        }
        if (!empty($street[2])) {
            $address['complement'] = (string) $street[2];
        }
        if (!empty($street[3])) {
            $address['neighborhood'] = (string) $street[3];
        }

        return $address;
    }

    /**
     * Opções avançadas (exigem API Key). Só envia o que estiver configurado.
     */
    protected function _buildOptions(Mage_Sales_Model_Order $order): array
    {
        $h       = $this->_helper();
        $storeId = $order->getStoreId();
        $options = [];

        $pix = $h->getPixDiscount($storeId);
        if ($pix > 0) {
            $options['discounts'] = ['pix' => ($pix == (int) $pix) ? (int) $pix : $pix];
        }

        if (!$h->isPixEnabled($storeId)) {
            $options['payment_methods_enabled'] = ['credit_card'];
        }

        $assured = trim((string) $h->getMethodConfig('assured_installments', $storeId));
        if ($assured !== '' && is_numeric($assured)) {
            $options['assured_installments'] = max(0, min(12, (int) $assured));
        }

        if ((bool) $h->getMethodConfig('single_payment', $storeId)) {
            $options['single_payment'] = true;
        }

        $hours = (int) $h->getMethodConfig('expire_hours', $storeId);
        if ($hours > 0 && (bool) $h->getMethodConfig('send_valid_until', $storeId)) {
            $options['valid_until'] = gmdate('Y-m-d\TH:i:s\Z', time() + $hours * 3600);
        }

        return $options;
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    protected function _post(string $path, array $body, bool $withApiKey): array
    {
        $h       = $this->_helper();
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        $apiKey = $h->getApiKey();
        if ($withApiKey && $apiKey !== '') {
            $headers[] = 'X-Api-Key: ' . $apiKey;
        }

        $ch = curl_init(UltraDev_InfinitePay_Helper_Data::API_BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);

        if ($raw === false) {
            $h->log('cURL erro em ' . $path . ': ' . $err, Zend_Log::ERR);
            return ['_http_status' => 0, '_raw' => (string) $err];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        $data['_http_status'] = $status;
        $data['_raw']         = (string) $raw;

        return $data;
    }
}
