<?php
declare(strict_types=1);

namespace Logicrays\PaypalUsd\Plugin\Sales;

use Magento\Sales\Model\Order\Status\History;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order\Payment;

/**
 * Plugin to add KWD→USD conversion details to order status history.
 *
 * This plugin intercepts order status history creation and adds conversion
 * information when PayPal transactions involve currency conversion.
 *
 * @package Logicrays_PaypalUsd
 */
class OrderStatusHistoryPlugin
{
    public function __construct(
        private Registry $registry,
    ) {}

    /**
     * After plugin for Order Status History creation.
     *
     * Adds KWD→USD conversion details as a separate comment when available.
     *
     * @param \Magento\Sales\Model\Order $subject
     * @param \Magento\Sales\Model\Order $result
     * @param \Magento\Sales\Model\Order\Status\History $history
     * @return \Magento\Sales\Model\Order
     */
    public function afterAddStatusHistory(
        \Magento\Sales\Model\Order $subject,
        \Magento\Sales\Model\Order $result,
        \Magento\Sales\Model\Order\Status\History $history
    ): \Magento\Sales\Model\Order       {
        try {
            $comment = $history->getComment();
            // Convert Phrase objects to string for processing
            $commentString = is_string($comment) ? $comment : (string)$comment;
            if (empty($commentString)) {
                return $result;
            }
        } catch (\Exception $e) {
            // If we can't process the comment, just return without error
            return $result;
        }

        // Check if this is a PayPal-related transaction
        $order = $history->getOrder();
        if (!$order || !$order->getPayment()) {
            return $result;
        }

        $payment = $order->getPayment();

        if ($payment->getMethod() !== 'paypal_express') {
            return $result;
        }

                // Look for conversion details in payment additional information
        $conversionInfo = $this->extractConversionInfo($payment);

        if (!empty($conversionInfo)) {
            // Check if we already added a conversion comment for this order
            $existingHistory = $order->getStatusHistories();
            $hasConversionComment = false;

            if ($existingHistory && is_iterable($existingHistory)) {
                foreach ($existingHistory as $historyItem) {
                    if ($historyItem && $historyItem->getComment()) {
                        try {
                            $comment = $historyItem->getComment();
                            // Convert Phrase objects to string safely
                            $commentString = is_string($comment) ? $comment : (string)$comment;
                            if (str_contains($commentString, 'KWD→USD conversion applied via Logicrays_PaypalUsd module')) {
                                $hasConversionComment = true;
                                break;
                            }
                        } catch (\Exception $e) {
                            // Skip this history item if we can't convert it to string
                            continue;
                        }
                    }
                }
            }

            // Only add conversion comment if not already present
            if (!$hasConversionComment) {
                try {
                    $conversionHistory = $subject->addCommentToStatusHistory(
                        $conversionInfo,
                        false,
                        false
                    );
                    $conversionHistory->setIsCustomerNotified(false);
                } catch (\Exception $e) {
                    // Log error but don't break the order flow
                    error_log("PaypalUsd: Could not add conversion comment: " . $e->getMessage());
                }
            }
        }

        return $result;
    }

    /**
     * Extract conversion information from payment.
     *
     * @param Payment $payment
     * @return string
     */
    private function extractConversionInfo(Payment $payment): string
    {
        $conversionInfo = [];

        // Check for conversion details in additional information
        $additionalInfo = $payment->getAdditionalInformation();
        if (!is_array($additionalInfo)) {
            $additionalInfo = [];
        }

        // Look for PayPal transaction details that might contain conversion info
        if (isset($additionalInfo['paypal_transaction_data'])) {
            $transactionData = $additionalInfo['paypal_transaction_data'];
            if (is_array($transactionData) && isset($transactionData['NOTETEXT'])) {
                $noteText = $transactionData['NOTETEXT'];
                if (str_contains($noteText, 'KWD→USD conversion')) {
                    $conversionInfo[] = "PayPal Transaction: " . $noteText;
                }
            }
        }

                        // Check for recent PayPal API calls in session/registry
        $paypalData = $this->registry->registry('paypal_conversion_data');

        if ($paypalData && is_array($paypalData)) {
            if (isset($paypalData['conversion_comment'])) {
                $conversionComment = $paypalData['conversion_comment'];

                // Add transaction ID for refund reference
                $transactionId = $this->getPayPalTransactionId($payment);
                $token = $paypalData['token'] ?? '';

                if (!empty($transactionId)) {
                    $conversionComment .= " PayPal Transaction ID: {$transactionId}";
                } elseif (!empty($token)) {
                    $conversionComment .= " PayPal Token: {$token}";
                }

                return $conversionComment;
            }
        }

        return '';
    }

    /**
     * Get PayPal transaction ID from payment.
     *
     * @param Payment $payment
     * @return string
     */
    private function getPayPalTransactionId(Payment $payment): string
    {
        // Try to get transaction ID from different sources
        $transactionId = '';

        // First try to get from last transaction ID
        if ($payment->getLastTransId()) {
            $transactionId = $payment->getLastTransId();
        }

        // If not found, try from additional information
        if (empty($transactionId)) {
            $additionalInfo = $payment->getAdditionalInformation();
            if (is_array($additionalInfo)) {
                if (isset($additionalInfo['paypal_transaction_id'])) {
                    $transactionId = $additionalInfo['paypal_transaction_id'];
                } elseif (isset($additionalInfo['txn_id'])) {
                    $transactionId = $additionalInfo['txn_id'];
                }
            }
        }

        // If still not found, try to get from payment transactions
        if (empty($transactionId)) {
            $transactions = $payment->getTransactions();
            if ($transactions && is_iterable($transactions)) {
                foreach ($transactions as $transaction) {
                    if ($transaction->getTxnId() && !empty($transaction->getTxnId())) {
                        $transactionId = $transaction->getTxnId();
                        break;
                    }
                }
            }
        }

        return $transactionId;
    }
}
