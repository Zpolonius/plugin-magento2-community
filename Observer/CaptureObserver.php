<?php
/**
 * Altapay Module for Magento 2.x.
 *
 * Copyright © 2018 Altapay. All rights reserved.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SDM\Altapay\Observer;

use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Altapay\Api\Payments\CaptureReservation;
use Altapay\Exceptions\ResponseHeaderException;
use Altapay\Response\CaptureReservationResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use SDM\Altapay\Logger\Logger;
use SDM\Altapay\Model\SystemConfig;
use Magento\Sales\Model\Order;
use SDM\Altapay\Helper\Data;
use SDM\Altapay\Helper\Config as storeConfig;
use SDM\Altapay\Model\Handler\OrderLinesHandler;
use SDM\Altapay\Model\Handler\PriceHandler;
use SDM\Altapay\Model\Handler\DiscountHandler;
use Altapay\Api\Subscription\ChargeSubscription;
use Magento\Framework\Math\Random;
use SDM\Altapay\Model\ReconciliationIdentifierFactory;
/**
 * Class CaptureObserver
 * Handle the invoice capture functionality.
 */
class CaptureObserver implements ObserverInterface
{
    /**
     * @var SystemConfig
     */
    private $systemConfig;
    /**
     * @var Logger
     */
    private $altapayLogger;
    /**
     * @var Order
     */
    private $order;
    /**
     * @var Helper Data
     */
    private $helper;

    /**
     * @var Helper Config
     */
    private $storeConfig;
    /**
     * @var OrderLinesHandler
     */
    private $orderLines;
    /**
     * @var PriceHandler
     */
    private $priceHandler;
    /**
     * @var DiscountHandler
     */
    private $discountHandler;
    /**
     * @var ReconciliationIdentifierFactory
     */
    private $reconciliation;
    /**
     * @var Random
     */
    private $random;

    /**
     * CaptureObserver constructor.
     *
     * @param SystemConfig $systemConfig
     * @param Logger $altapayLogger
     * @param Order $order
     * @param Data $helper
     * @param storeConfig $storeConfig
     * @param OrderLinesHandler $orderLines
     * @param PriceHandler $priceHandler
     * @param DiscountHandler $discountHandler
     * @param ReconciliationIdentifierFactory $reconciliation
     * @param Random $random
     */
    public function __construct(
        SystemConfig $systemConfig,
        Logger $altapayLogger,
        Order $order,
        Data $helper,
        storeConfig $storeConfig,
        OrderLinesHandler $orderLines,
        PriceHandler $priceHandler,
        DiscountHandler $discountHandler,
        ReconciliationIdentifierFactory $reconciliation,
        Random $random
    ) {
        $this->systemConfig     = $systemConfig;
        $this->altapayLogger    = $altapayLogger;
        $this->order            = $order;
        $this->helper           = $helper;
        $this->storeConfig      = $storeConfig;
        $this->orderLines       = $orderLines;
        $this->priceHandler     = $priceHandler;
        $this->discountHandler  = $discountHandler;
        $this->reconciliation   = $reconciliation;
        $this->random           = $random;
    }

    /**
     * @param Observer $observer
     *
     * @return void
     * @throws ResponseHeaderException
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $payment          = $observer['payment'];
        $invoice          = $observer['invoice'];
        $orderIncrementId = $invoice->getOrder()->getIncrementId();
        $paymentType      = $payment->getAdditionalInformation('payment_type');
        $orderObject      = $this->order->loadByIncrementId($orderIncrementId);
        $storeCode        = $invoice->getStore()->getCode();
        if (in_array($payment->getMethod(), SystemConfig::getTerminalCodes())) {
            //Create orderlines from order items
            $orderLines = $this->processInvoiceOrderLines($invoice);
            //Send request for payment refund
            $this->sendInvoiceRequest($paymentType, $invoice, $orderLines, $orderObject, $payment, $storeCode);
        }
    }

    /**
     * @param Magento\Sales\Model\Order\Invoice $invoice
     *
     * @return array
     */
    private function processInvoiceOrderLines($invoice)
    {
        $couponCode       = $invoice->getDiscountDescription();
        $couponCodeAmount = $invoice->getDiscountAmount();
        //Return true if discount enabled on all items
        $discountAllItems = $this->discountHandler->allItemsHaveDiscount($invoice->getOrder()->getAllVisibleItems());
        //order lines for items
        $orderLines = $this->itemOrderLines($couponCodeAmount, $invoice, $discountAllItems);
        //send the discount into separate orderline if discount applied to all items
        if ($discountAllItems && abs($couponCodeAmount) > 0) {
            //order lines for discounts
            $orderLines[] = $this->orderLines->discountOrderLine($couponCodeAmount, $couponCode);
        }
        if ($invoice->getShippingInclTax() > 0) {
            //order lines for shipping
            $orderLines[] = $this->orderLines->handleShipping($invoice, $discountAllItems, false);
            //Shipping Discount Tax Compensation Amount
            $compAmount = $this->discountHandler->hiddenTaxDiscountCompensation($invoice, $discountAllItems, false);
            if ($compAmount > 0 && $discountAllItems == false) {
                $orderLines[] = $this->orderLines->compensationOrderLine(
                    "Shipping compensation",
                    "comp-ship",
                    $compAmount
                );
            }
        }
        if(!empty($this->fixedProductTax($invoice))){
            //order lines for FPT
            $orderLines[] = $this->orderLines->fixedProductTaxOrderLine($this->fixedProductTax($invoice));
        }

        return $orderLines;
    }

    /**
     * @param int|float $couponCodeAmount
     * @param Magento\Sales\Model\Order\Invoice $invoice
     * @param bool $discountAllItems
     *
     * @return array
     */
    private function itemOrderLines($couponCodeAmount, $invoice, $discountAllItems)
    {
        $orderLines       = [];
        $discountAmount   = 0;
        $storePriceIncTax = $this->storeConfig->storePriceIncTax($invoice->getOrder());
        foreach ($invoice->getAllItems() as $item) {
            $qty         = $item->getQty();
            $taxPercent  = $item->getOrderItem()->getTaxPercent();
            $productType = $item->getOrderItem()->getProductType();
            if ($qty > 0 && $productType != 'bundle' && $item->getPriceInclTax()) {
                if($item->getDiscountAmount()) {
                    $discountAmount = $item->getDiscountAmount();
                }
                $originalPrice  = $item->getOrderItem()->getOriginalPrice();
                $totalPrice     = $originalPrice * $qty;

                if ($originalPrice == 0) {
                    $originalPrice = $item->getPriceInclTax();
                }

                if ($storePriceIncTax) {
                    $priceWithoutTax = $this->priceHandler->getPriceWithoutTax($originalPrice, $taxPercent);
                    $price           = $item->getPriceInclTax();
                    $unitPrice       = bcdiv($priceWithoutTax, 1, 2);
                    $taxAmount       = $this->priceHandler->calculateTaxAmount($priceWithoutTax, $taxPercent, $qty);
                } else {
                    $price           = $item->getPrice();
                    $unitPrice       = $originalPrice;
                    $taxAmount       = $this->priceHandler->calculateTaxAmount($unitPrice, $taxPercent, $qty);
                }
                $itemDiscountInformation = $this->discountHandler->getItemDiscountInformation(
                    $totalPrice,
                    $price,
                    $discountAmount,
                    $qty,
                    $discountAllItems,
                    $item,
                    $taxAmount
                );
                $discountedAmount        = $itemDiscountInformation['discount'];
                $orderLines[]            = $this->orderLines->itemOrderLine(
                    $item,
                    $unitPrice,
                    $discountedAmount,
                    $taxAmount,
                    $invoice->getOrder(),
                    false
                );
                $roundingCompensation    = $this->priceHandler->compensationAmountCal(
                    $item,
                    $unitPrice,
                    $taxAmount,
                    $discountedAmount,
                    false
                );
                // check if rounding compensation amount, send in the separate orderline
                if ($roundingCompensation > 0 || $roundingCompensation < 0) {
                    $orderLines[] = $this->orderLines->compensationOrderLine(
                        "Compensation Amount",
                        "comp-" . $item->getOrderItem()->getItemId(),
                        $roundingCompensation
                    );
                }
            }
        }

        return $orderLines;
    }

    /**
     * @param Magento\Sales\Model\Order\Invoice $invoice
     *
     * @return array
     */
    private function shippingTrackingInfo($invoice)
    {
        $trackingInfo     = [];
        $tracksCollection = $invoice->getOrder()->getTracksCollection();
        $trackItems       = $tracksCollection->getItems();

        if ($trackItems && is_array($trackItems)) {
            foreach ($trackItems as $track) {
                $trackingInfo[] = [
                    'shippingCompany' => $track->getTitle(),
                    'trackingNumber'  => $track->getTrackNumber()
                ];
            }
        }

        return $trackingInfo;
    }

    /**
     * @param Magento\Sales\Model\Order\Invoice $invoice
     * @param array $orderLines
     * @param Magento\Sales\Model\Order $orderObject
     * @param array $payment
     * @param int|string $storeCode
     * @param string $paymentType
     *
     * @throws ResponseHeaderException
     */
    private function sendInvoiceRequest($paymentType, $invoice, $orderLines, $orderObject, $payment, $storeCode)
    {
        $grandTotal = (float)$invoice->getGrandTotal();
        $payment    = $invoice->getOrder()->getPayment();
        $reconciliationIdentifier  = $this->random->getUniqueHash();
        if ($paymentType === 'subscription' || $paymentType === 'subscriptionAndCharge') {
            $api = new ChargeSubscription($this->systemConfig->getAuth($storeCode));
        } else {
            $api = new CaptureReservation($this->systemConfig->getAuth($storeCode));
            if ($invoice->getTransactionId()) {
                $api->setInvoiceNumber($invoice->getTransactionId());
            }
            $api->setOrderLines($orderLines);
            $shippingTrackingInfo = $this->shippingTrackingInfo($invoice);
            // Send shipping tracking info
            $api->setTrackingInfo($shippingTrackingInfo);
        }

        $api->setTransaction($payment->getLastTransId());
        $api->setAmount(round($grandTotal, 2));
        $api->setReconciliationIdentifier($reconciliationIdentifier);

        /** @var CaptureReservationResponse $response */
        try {
            $response = $api->call();
            $max_date = '';
            $latestTransKey = 0;
            if (isset($response->Transactions)) {
                foreach ($response->Transactions as $key => $value) {
                    if ($value->AuthType === "subscription_payment" && $value->CreatedDate > $max_date) {
                        $max_date = $value->CreatedDate;
                        $latestTransKey = $key;
                    }
                }
            }
    
            if (isset($response->Transactions[$latestTransKey])) {
                $transaction = $response->Transactions[$latestTransKey];
                $payment->setLastTransId($transaction->TransactionId);
                $payment->save();
            }
        } catch (ResponseHeaderException $e) {
            $this->altapayLogger->addInfoLog('Info', $e->getHeader());
            $this->altapayLogger->addCriticalLog('Exception', $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            $this->altapayLogger->addCriticalLog('Exception', $e->getMessage());
        }

        $model = $this->reconciliation->create();
        $model->addData([
            "order_id"      => $invoice->getOrder()->getIncrementId(),
            "identifier"    => $reconciliationIdentifier,
            "type"          => 'captured'
        ]);
        $model->save();

        $rawResponse = $api->getRawResponse();
        if (!empty($rawResponse)) {
            $body = $rawResponse->getBody();
            $this->altapayLogger->addInfoLog('Info' , $body);
            //Update comments if capture fail
            $xml = simplexml_load_string($body);
            if ($xml->Body->Result == 'Error' || $xml->Body->Result == 'Failed' || $xml->Body->Result == 'Incomplete') {
                $orderObject->addStatusHistoryComment('Capture failed: ' . $xml->Body->MerchantErrorMessage)
                    ->setIsCustomerNotified(false);
                $orderObject->getResource()->save($orderObject);
            }

            $headData = [];
            foreach ($rawResponse->getHeaders() as $k => $v) {
                $headData[] = $k . ': ' . json_encode($v);
            }
            $this->altapayLogger->addInfoLog('Info', implode(", ", $headData));
        }
        if (!isset($response->Result) || $response->Result != 'Success') {
            throw new \InvalidArgumentException('Could not capture reservation');
        }
    }

    /**
     * @param Magento\Sales\Model\Order\Invoice $invoice
     *
     * @return float
     */
    public function fixedProductTax($invoice){

        $weeTaxAmount = 0.0;
        foreach ($invoice->getAllItems() as $item) {
            $weeTaxAmount +=  $item->getWeeeTaxAppliedRowAmount();
        }

        return $weeTaxAmount;
    }
}
