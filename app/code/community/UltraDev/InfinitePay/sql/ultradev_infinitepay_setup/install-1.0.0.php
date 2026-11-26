<?php
/**
 * UltraDev_InfinitePay – Setup v1.0.0
 *
 * Nenhuma tabela necessária: os dados do pagamento ficam em
 * sales_flat_order_payment.additional_information.
 * O script existe para registrar a versão no core_resource.
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

// Nenhuma DDL necessária nesta versão.

$installer->endSetup();
