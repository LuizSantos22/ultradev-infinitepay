<?php
class UltraDev_InfinitePay_Helper_Data extends Mage_Core_Helper_Abstract
{
    const METHOD_CODE      = 'ultradev_infinitepay_checkout';
    const API_BASE_URL     = 'https://api.checkout.infinitepay.io';
    const LOG_FILE         = 'ultradev_infinitepay.log';
    const XML_PATH_HANDLE  = 'payment/ultradev_infinitepay/handle';
    const XML_PATH_API_KEY = 'payment/ultradev_infinitepay/api_key';
    const XML_PATH_DEBUG   = 'payment/ultradev_infinitepay/debug';
    const LOCK_PREFIX      = 'ip_order_';

    public function getHandle(): string
    {
        return ltrim(trim((string) Mage::getStoreConfig(self::XML_PATH_HANDLE)), '$@');
    }

    public function getApiKey(): string
    {
        return $this->_getDecryptedConfig(self::XML_PATH_API_KEY);
    }

    public function isDebug(): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_DEBUG);
    }

    public function getMethodConfig(string $field, $storeId = null)
    {
        return Mage::getStoreConfig('payment/' . self::METHOD_CODE . '/' . $field, $storeId);
    }

    public function isPixEnabled($storeId = null): bool
    {
        return (bool) $this->getMethodConfig('pix_enabled', $storeId);
    }

    /**
     * Percentual de desconto no Pix (0 = sem desconto). Aceita apenas 0 < x < 100.
     */
    public function getPixDiscount($storeId = null): float
    {
        if (!$this->isPixEnabled($storeId)) {
            return 0.0;
        }
        $value = (float) str_replace(',', '.', (string) $this->getMethodConfig('pix_discount', $storeId));
        return ($value > 0 && $value < 100) ? round($value, 2) : 0.0;
    }

    public function formatPercent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }

    public function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public function formatCents(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }

    public function log(string $message, int $level = Zend_Log::DEBUG): void
    {
        if ($level === Zend_Log::DEBUG && !$this->isDebug()) {
            return;
        }
        Mage::log('[InfinitePay] ' . $message, $level, self::LOG_FILE, true);
    }

    /**
     * Lock por pedido (MySQL GET_LOCK) para evitar webhook + retorno + cron processando ao mesmo tempo.
     */
    public function acquireLock(string $key, int $timeout = 10): bool
    {
        $db = Mage::getSingleton('core/resource')->getConnection('core_write');
        return (int) $db->fetchOne('SELECT GET_LOCK(?, ?)', [self::LOCK_PREFIX . $key, $timeout]) === 1;
    }

    public function releaseLock(string $key): void
    {
        $db = Mage::getSingleton('core/resource')->getConnection('core_write');
        $db->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_PREFIX . $key]);
    }

    protected function _getDecryptedConfig(string $path): string
    {
        $value = (string) Mage::getStoreConfig($path);
        if ($value === '') {
            return '';
        }
        try {
            $decrypted = (string) Mage::helper('core')->decrypt($value);
            if ($decrypted !== '' && preg_match('/^[\x20-\x7E]+$/', $decrypted)) {
                return $decrypted;
            }
        } catch (\Exception $e) {
            // ignora erro de decrypt
        }
        return $value;
    }
}
