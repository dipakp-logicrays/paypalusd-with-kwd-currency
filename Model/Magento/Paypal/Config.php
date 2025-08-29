<?php
declare(strict_types=1);

namespace Logicrays\PaypalUsd\Model\Magento\Paypal;

/**
 * Extends core PayPal config to keep methods visible for KWD quotes.
 *
 * Magento checks supported currency codes when deciding method availability.
 * We append KWD so PayPal buttons show up; the plugin handles USD conversion at runtime.
 *
 * @package Logicrays_PaypalUsd
 */
class Config extends \Magento\Paypal\Model\Config
{
    /**
     * Currency codes PayPal methods are considered available for.
     * We add KWD to ensure visibility in KWD stores.
     *
     * @var string[]
     */
    protected $_supportedCurrencyCodes = [
        'AUD','CAD','CZK','DKK','EUR','HKD','HUF','ILS','JPY','MXN','NOK','NZD','PLN','GBP','RUB','SGD','SEK','CHF','TWD','THB','USD',
        'KWD', // Added to keep PayPal visible in KWD quotes
    ];
}
