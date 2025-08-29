<?php
declare(strict_types=1);

namespace Logicrays\PaypalUsd\Plugin\Paypal;

use Magento\Paypal\Model\Api\Nvp;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Registry;

/**
 * Plugin that forces USD for PayPal NVP calls and converts all KWD amounts.
 *
 * This preserves storefront/Base currency as KWD, but sends USD (a PayPal-supported currency)
 * at the gateway layer. Amount fields are normalized to two decimals for PayPal.
 *
 * @package Logicrays_PaypalUsd
 */
class NvpCurrencyPlugin
{
    public function __construct(
        private PriceCurrencyInterface $priceCurrency,
        private StoreManagerInterface $storeManager,
        private Registry $registry,
    ) {}

    /**
     * Before plugin for \Magento\Paypal\Model\Api\Nvp::call().
     *
     * - Enforces USD currency.
     * - Converts all amount-like fields from KWD -> USD and formats to 2 decimals.
     * - Skips conversion when request is already USD (prevents double conversion if shopper uses USD display currency).
     *
     * @param Nvp $subject
     * @param string $method
     * @param array $request
     * @return array{0:string,1:array}
     */
    public function beforeCall(
        Nvp $subject,
        string $method,
        array $request,
    ): array {
        // Only act when base currency is KWD (your accounting/display choice).
        if ($this->storeManager->getStore()->getBaseCurrencyCode() !== 'KWD') {
            return [$method, $request];
        }

        // We should cover the commonly used payment-related calls.
        $relevantMethods = [
            'SetExpressCheckout',
            'GetExpressCheckoutDetails',
            'DoExpressCheckoutPayment',
            'DoAuthorization',
            'DoCapture',
            'DoVoid',
            'RefundTransaction',
            'DoDirectPayment',
        ];
        if (!\in_array($method, $relevantMethods, true)) {
            return [$method, $request];
        }

        $targetCurrency = 'USD';

        // Special handling for GetExpressCheckoutDetails - only force currency codes, don't convert amounts
        if ($method === 'GetExpressCheckoutDetails') {
            $request['CURRENCYCODE'] = $targetCurrency;
            $request['PAYMENTREQUEST_0_CURRENCYCODE'] = $targetCurrency;

            foreach (\array_keys($request) as $key) {
                if (\preg_match('/^PAYMENTREQUEST_\d+_CURRENCYCODE$/', (string)$key)) {
                    $request[$key] = $targetCurrency;
                }
            }

            return [$method, $request];
        }

        // Determine source currency from incoming request (if present).
        // If it's already USD, don't reconvert; just ensure codes are USD.
        $sourceCurrency = $request['PAYMENTREQUEST_0_CURRENCYCODE']
            ?? $request['CURRENCYCODE']
            ?? null;

        // 1) Force currency code(s) to USD
        $request['CURRENCYCODE'] = $targetCurrency;
        $request['PAYMENTREQUEST_0_CURRENCYCODE'] = $targetCurrency;

        foreach (\array_keys($request) as $key) {
            if (\preg_match('/^PAYMENTREQUEST_\d+_CURRENCYCODE$/', (string)$key)) {
                $request[$key] = $targetCurrency;
            }
        }

        // Also mirror PAYMENTACTION to PAYMENTREQUEST_0_PAYMENTACTION for clarity
        if (isset($request['PAYMENTACTION']) && is_scalar($request['PAYMENTACTION'])) {
            $request['PAYMENTREQUEST_0_PAYMENTACTION'] = (string)$request['PAYMENTACTION'];
        }

        // 2) Convert amounts only if the source currency is NOT already USD.
        if (\strtoupper((string)$sourceCurrency) !== $targetCurrency) {
            $conversionComment = "KWD→USD conversion applied via Logicrays_PaypalUsd module. ";
            $conversionComment .= "Original amounts in KWD, sent to PayPal in USD. ";

            foreach ($request as $key => $value) {
                if (!\is_scalar($value)) {
                    continue;
                }

                $k = (string)$key;

                // Convert any line-level amounts like L_AMT0, L_AMT1, ...
                $isLineAmt = (bool)\preg_match('/^L_.*AMT\d+$/', $k) || (bool)\preg_match('/^L_AMT\d+$/', $k);

                // Convert top-level amount fields
                $isTopLevelAmt = \in_array(
                    $k,
                    ['AMT','ITEMAMT','TAXAMT','SHIPPINGAMT','HANDLINGAMT','INSURANCEAMT','SHIPDISCAMT'],
                    true,
                );

                // Convert payment request scoped amount fields
                $isPaymentReqAmt = (bool)\preg_match(
                    '/^PAYMENTREQUEST_\d+_(AMT|ITEMAMT|TAXAMT|SHIPPINGAMT|HANDLINGAMT|INSURANCEAMT|SHIPDISCAMT)$/',
                    $k,
                );

                $isMaxAmt = ($k === 'MAXAMT');

                if ($isLineAmt || $isTopLevelAmt || $isPaymentReqAmt || $isMaxAmt) {
                    $numeric = (float)$value;
                    $converted = $this->priceCurrency->convert($numeric, null, $targetCurrency);
                    $request[$k] = \number_format((float)$converted, 2, '.', '');
                    // Only add key conversion details for major amounts to keep comment concise
                    if (\in_array($k, ['AMT', 'ITEMAMT', 'TAXAMT', 'SHIPPINGAMT'], true)) {
                        $conversionComment .= "{$k}: KWD {$value} → USD {$request[$k]}. ";
                    }
                }
            }

            // 3) Recompute ITEMAMT and AMT from converted line items and charges to avoid #10413
            $lineItemTotal = 0.00;
            $lineItemIndexes = [];

            foreach ($request as $key => $value) {
                if (!\is_scalar($value)) {
                    continue;
                }

                if (\preg_match('/^L_AMT(\d+)$/', (string)$key, $m)) {
                    $lineItemIndexes[] = (int)$m[1];
                }
            }

            \sort($lineItemIndexes);

            // Ensure all line item fields are properly populated
            foreach ($lineItemIndexes as $idx) {
                $unitKey = 'L_AMT' . $idx;
                $qtyKey  = 'L_QTY' . $idx;
                $nameKey = 'L_NAME' . $idx;
                $numberKey = 'L_NUMBER' . $idx;

                // Ensure L_NAME is not null/empty/missing
                if (!isset($request[$nameKey]) || empty($request[$nameKey]) || $request[$nameKey] === null) {
                    $request[$nameKey] = 'Product ' . ($idx + 1);
                }

                // Ensure L_NUMBER is not null/empty/missing
                if (!isset($request[$numberKey]) || empty($request[$numberKey]) || $request[$numberKey] === null) {
                    $request[$numberKey] = 'SKU-' . ($idx + 1);
                }

                $unit = isset($request[$unitKey]) && is_scalar($request[$unitKey])
                    ? (float)$request[$unitKey]
                    : 0.0;
                $qty  = isset($request[$qtyKey]) && is_scalar($request[$qtyKey])
                    ? (float)$request[$qtyKey]
                    : 1.0;

                $lineItemTotal += $unit * $qty;
            }

            $lineItemTotal = (float)number_format($lineItemTotal, 2, '.', '');

            // Gather charges with sane defaults
            $getAmount = static function (array $arr, string $a, string $b): float {
                $val = $arr[$a] ?? $arr[$b] ?? 0.0;

                return (float)(is_scalar($val) ? $val : 0.0);
            };

            $shipping = $getAmount($request, 'SHIPPINGAMT', 'PAYMENTREQUEST_0_SHIPPINGAMT');
            $tax      = $getAmount($request, 'TAXAMT', 'PAYMENTREQUEST_0_TAXAMT');
            $handling = $getAmount($request, 'HANDLINGAMT', 'PAYMENTREQUEST_0_HANDLINGAMT');
            $insurance= $getAmount($request, 'INSURANCEAMT', 'PAYMENTREQUEST_0_INSURANCEAMT');
            $shipDisc = $getAmount($request, 'SHIPDISCAMT', 'PAYMENTREQUEST_0_SHIPDISCAMT');

            $computedItemAmt = (float)number_format($lineItemTotal, 2, '.', '');
            $computedOrderAmt = (float)number_format(
                $computedItemAmt + $shipping + $tax + $handling + $insurance - $shipDisc,
                2,
                '.',
                '',
            );

            // Set both top-level and PAYMENTREQUEST_0_*
            $request['ITEMAMT'] = number_format($computedItemAmt, 2, '.', '');
            $request['PAYMENTREQUEST_0_ITEMAMT'] = number_format($computedItemAmt, 2, '.', '');

            $request['AMT'] = number_format($computedOrderAmt, 2, '.', '');
            $request['PAYMENTREQUEST_0_AMT'] = number_format($computedOrderAmt, 2, '.', '');

            // Mirror component charges into PAYMENTREQUEST_0_* to avoid PayPal 10413 warnings
            $request['PAYMENTREQUEST_0_TAXAMT'] = number_format((float)$tax, 2, '.', '');
            $request['PAYMENTREQUEST_0_SHIPPINGAMT'] = number_format((float)$shipping, 2, '.', '');
            if ($handling !== 0.0) {
                $request['PAYMENTREQUEST_0_HANDLINGAMT'] = number_format((float)$handling, 2, '.', '');
            }
            if ($insurance !== 0.0) {
                $request['PAYMENTREQUEST_0_INSURANCEAMT'] = number_format((float)$insurance, 2, '.', '');
            }
            if ($shipDisc !== 0.0) {
                $request['PAYMENTREQUEST_0_SHIPDISCAMT'] = number_format((float)$shipDisc, 2, '.', '');
            }

            // Add conversion note to request for logging purposes
            $request['NOTETEXT'] = ($request['NOTETEXT'] ?? '') . ' ' . $conversionComment;

            // Store conversion data in registry for order history plugin
            $this->registry->register('paypal_conversion_data', [
                'conversion_comment' => $conversionComment,
                'original_currency' => 'KWD',
                'target_currency' => 'USD',
                'conversion_timestamp' => date('Y-m-d H:i:s'),
                'method' => $method,
                'token' => $request['TOKEN'] ?? '',
                'request_data' => $request,
            ], true);
        }

        return [$method, $request];
    }
}
