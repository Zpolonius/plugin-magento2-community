<?php
/**
 * Altapay Module for Magento 2.x.
 *
 * Copyright © 2018 Altapay. All rights reserved.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SDM\Altapay\Model;

use Magento\Framework\Exception\AlreadyExistsException;
use Altapay\Api\Ecommerce\Callback;
use Altapay\Response\CallbackResponse;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use SDM\Altapay\Logger\Logger;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use SDM\Altapay\Api\TransactionRepositoryInterface;
use SDM\Altapay\Api\OrderLoaderInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use SDM\Altapay\Model\Handler\OrderLinesHandler;
use SDM\Altapay\Model\Handler\CreatePaymentHandler;
use Magento\Checkout\Model\Cart;
use Magento\CatalogInventory\Api\StockStateInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use SDM\Altapay\Model\TokenFactory;
use SDM\Altapay\Helper\Data;
use SDM\Altapay\Model\ReconciliationIdentifierFactory;

/**
 * Class Generator
 * Handle the create payment related functionality.
 */
class Generator
{
    /**
     * @var Quote
     */
    private $quote;
    
    /**
     * @var Session
     */
    private $checkoutSession;
    
    /**
     * @var Http
     */
    private $request;
    
    /**
     * @var Order
     */
    private $order;
    /**
     * @var InvoiceService
     */
    private $invoiceService;
    /**
     * @var OrderSender
     */
    private $orderSender;
    
    /**
     * @var SystemConfig
     */
    private $systemConfig;
    
    /**
     * @var Logger
     */
    private $altapayLogger;
    
    /**
     * @var TransactionRepositoryInterface
     */
    private $transactionRepository;
    
    /**
     * @var OrderLoaderInterface
     */
    private $orderLoader;
    
    /**
     * @var TransactionFactory
     */
    private $transactionFactory;
    /**
     * @var OrderLinesHandler
     */
    private $orderLines;
    /**
     * @var CreatePaymentHandler
     */
    private $paymentHandler;
    
    /**
     * @var StockStateInterface
     */
    private $stockItem;
    
    /**
     * @var StockRegistryInterface
     */
    private $stockRegistry;
    
    /**
     * @var Cart
     */
    private $modelCart;
    
    /**
     * @var TokenFactory
     */
    private $dataToken;
    
    /**
     * @var Data
     */
    private $helper;

    /**
     * @var ReconciliationIdentifierFactory
     */
    protected $reconciliation;
    
    /**
     * Generator constructor.
     *
     * @param Quote                           $quote
     * @param Session                         $checkoutSession
     * @param Http                            $request
     * @param Order                           $order
     * @param OrderSender                     $orderSender
     * @param SystemConfig                    $systemConfig
     * @param Logger                          $altapayLogger
     * @param TransactionRepositoryInterface  $transactionRepository
     * @param OrderLoaderInterface            $orderLoader
     * @param TransactionFactory              $transactionFactory
     * @param InvoiceService                  $invoiceService
     * @param OrderLinesHandler               $orderLines
     * @param CreatePaymentHandler            $paymentHandler
     * @param StockStateInterface             $stockItem
     * @param StockRegistryInterface          $stockRegistry
     * @param Cart                            $modelCart
     * @param TokenFactory                    $dataToken
     * @param Data                            $helper
     * @param ReconciliationIdentifierFactory $reconciliation
     */
    public function __construct(
        Quote $quote,
        Session $checkoutSession,
        Http $request,
        Order $order,
        OrderSender $orderSender,
        SystemConfig $systemConfig,
        Logger $altapayLogger,
        TransactionRepositoryInterface $transactionRepository,
        OrderLoaderInterface $orderLoader,
        TransactionFactory $transactionFactory,
        InvoiceService $invoiceService,
        OrderLinesHandler $orderLines,
        CreatePaymentHandler $paymentHandler,
        StockStateInterface $stockItem,
        StockRegistryInterface $stockRegistry,
        Cart $modelCart,
        TokenFactory $dataToken,
        Data $helper,
        ReconciliationIdentifierFactory $reconciliation
    ) {
        $this->quote                 = $quote;
        $this->checkoutSession       = $checkoutSession;
        $this->request               = $request;
        $this->order                 = $order;
        $this->orderSender           = $orderSender;
        $this->invoiceService        = $invoiceService;
        $this->systemConfig          = $systemConfig;
        $this->altapayLogger         = $altapayLogger;
        $this->transactionRepository = $transactionRepository;
        $this->transactionFactory    = $transactionFactory;
        $this->orderLoader           = $orderLoader;
        $this->orderLines            = $orderLines;
        $this->paymentHandler        = $paymentHandler;
        $this->stockItem             = $stockItem;
        $this->stockRegistry         = $stockRegistry;
        $this->modelCart             = $modelCart;
        $this->dataToken             = $dataToken;
        $this->helper                = $helper;
        $this->reconciliation        = $reconciliation;
    }

    /**
     * @param RequestInterface $request
     *
     * @return bool
     * @throws \Exception
     */
    public function restoreOrderFromRequest(RequestInterface $request)
    {
        $callback = new Callback($request->getPostValue());
        $response = $callback->call();
        if ($response) {
            $this->altapayLogger->addDebugLog('[restoreOrderFromRequest] Response correct', $response);
            $order = $this->orderLoader->getOrderByOrderIncrementId($response->shopOrderId);
            if ($order->getQuoteId()) {
                $this->altapayLogger->addDebugLog('[restoreOrderFromRequest] Order quote id', $order->getQuoteId());
                if ($quote = $this->quote->loadByIdWithoutStore($order->getQuoteId())) {
                    $this->altapayLogger->addDebugLog('[restoreOrderFromRequest] Quote found', $order->getQuoteId());
                    $quote->setIsActive(1)->setReservedOrderId(null)->save();
                    $this->checkoutSession->replaceQuote($quote);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param      $order
     * @param bool $requireCapture
     */
    public function createInvoice($order, $requireCapture = false)
    {
        if (filter_var($requireCapture, FILTER_VALIDATE_BOOLEAN) === true) {
            $captureType = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE;
        } else {
            $captureType = \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE;
        }

        if (!$order->getInvoiceCollection()->count()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase($captureType);
            $invoice->register();
            $invoice->getOrder()->setCustomerNoteNotify(false);
            $invoice->getOrder()->setIsInProcess(true);
            $transaction = $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder());
            $transaction->save();
        }
    }

    /**
     * @param RequestInterface $request
     */
    public function handleNotificationAction(RequestInterface $request)
    {
        $this->completeCheckout(__(ConstantConfig::NOTIFICATION_CALLBACK), $request);
    }

    /**
     * @param RequestInterface $request
     * @param                  $responseStatus
     */
    public function handleCancelStatusAction(RequestInterface $request, $responseStatus)
    {
        $responseComment = __(ConstantConfig::CONSUMER_CANCEL_PAYMENT);
        if ($responseStatus != 'cancelled') {
            $responseComment = __(ConstantConfig::UNKNOWN_PAYMENT_STATUS_MERCHANT);
        }
        $historyComment = __(ConstantConfig::CANCELLED) . '|' . $responseComment;
        //TODO: fetch the MerchantErrorMessage and use it as historyComment
        $callback = new Callback($request->getPostValue());
        $response = $callback->call();
        if ($response) {
            $order             = $this->loadOrderFromCallback($response);
            $payment           = $order->getPayment();
            $lastTransactionId = $payment->getLastTransId();
            if (!empty($lastTransactionId) && $lastTransactionId != $response->transactionId ) {
                if (strtolower($response->status) === "succeeded") {
                    //check if order status set in configuration
                    $statusKey         = Order::STATE_CANCELED;
                    $storeCode         = $order->getStore()->getCode();
                    $storeScope        = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
                    $orderStatusCancel = $this->systemConfig->getStatusConfig('cancel', $storeScope, $storeCode);
                
                    if ($orderStatusCancel) {
                        $statusKey = $orderStatusCancel;
                    }
                    $this->handleOrderStateAction($request,  Order::STATE_CANCELED, $statusKey, $historyComment);
                    //save failed transaction data
                    $this->saveTransactionData($request, $response, $order);
                }
            }
        }
    }

    /**
     * @param $request
     * @param $response
     * @param $order
     */
    private function saveTransactionData($request, $response, $order)
    {
        $parametersData  = json_encode($request->getPostValue());
        $transactionData = json_encode($response);
        $this->transactionRepository->addTransactionData(
            $order->getIncrementId(),
            $response->transactionId,
            $response->paymentId,
            $transactionData,
            $parametersData
        );
    }

    /**
     * @param RequestInterface $request
     *
     * @return mixed
     */
    public function handleFailStatusRedirectFormAction(RequestInterface $request)
    {
        //TODO:refactor this method
        $formUrl  = null;
        $callback = new Callback($request->getPostValue());
        $response = $callback->call();
        if ($response) {
            $order   = $this->orderLoader->getOrderByOrderIncrementId($response->shopOrderId);
            $formUrl = $order->getAltapayPaymentFormUrl();
            if ($formUrl) {
                $order->addStatusHistoryComment(__(ConstantConfig::DECLINED_PAYMENT_FORM));
            } else {
                $order->addStatusHistoryComment(__(ConstantConfig::DECLINED_PAYMENT_SECTION));
            }
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $order->getResource()->save($order);
        }

        return $formUrl;
    }

    /**
     * @param RequestInterface $request
     * @param                  $msg
     * @param                  $merchantErrorMsg
     * @param                  $responseStatus
     *
     * @throws \Exception
     */
    public function handleFailedStatusAction(
        RequestInterface $request,
        $msg,
        $merchantErrorMsg,
        $responseStatus
    ) {
        $historyComment = $responseStatus . '|' . $msg;
        if (!empty($merchantErrorMsg)) {
            $historyComment = $historyComment . '|' . $merchantErrorMsg;
        }
        $callback = new Callback($request->getPostValue());
        $response = $callback->call();
        if ($response) {
            $order             = $this->loadOrderFromCallback($response);
            $payment           = $order->getPayment();
            $lastTransactionId = $payment->getLastTransId();
            if (!empty($lastTransactionId) && $lastTransactionId != $response->transactionId ) {
                if (strtolower($response->status) === "succeeded") {
                    $transInfo = $this->getTransactionInfoFromResponse($response);
                    //check if order status set in configuration
                    $statusKey         = Order::STATE_CANCELED;
                    $storeCode         = $order->getStore()->getCode();
                    $storeScope        = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
                    $orderStatusCancel = $this->systemConfig->getStatusConfig('cancel', $storeScope, $storeCode);
                    
                    if ($orderStatusCancel) {
                        $statusKey = $orderStatusCancel;
                    }
                    $this->handleOrderStateAction($request, Order::STATE_CANCELED, $statusKey, $historyComment, $transInfo);
                    //save failed transaction data
                    $this->saveTransactionData($request, $response, $order);
                }
            }
        }
    }

    /**
     * @param CallbackResponse $response
     *
     * @return Order
     */
    private function loadOrderFromCallback(CallbackResponse $response)
    {
        return $this->order->loadByIncrementId($response->shopOrderId);
    }

    /**
     * @param RequestInterface $request
     * @param string           $orderState
     * @param string           $orderStatus
     * @param string           $historyComment
     * @param null             $transactionInfo
     *
     * @return bool
     * @throws AlreadyExistsException
     */
    public function handleOrderStateAction(
        RequestInterface $request,
        $orderState = Order::STATE_NEW,
        $orderStatus = Order::STATE_NEW,
        $historyComment = "Order state changed",
        $transactionInfo = null
    ) {
        $callback = new Callback($request->getPostValue());
        $response = $callback->call();
        if ($response) {
            $order = $this->loadOrderFromCallback($response);
            if ($orderStatus === 'canceled') {
                $order->cancel();
            }
            $order->setState($orderState);
            $order->setIsNotified(false);
            if ($transactionInfo !== null) {
                $order->addStatusHistoryComment($transactionInfo);
            }
            $order->addStatusHistoryComment($historyComment, $orderStatus);
            $order->getResource()->save($order);

            return true;
        }

        return false;
    }

    /**
     * @param RequestInterface $request
     */
    public function handleOkAction(RequestInterface $request)
    {
        $this->completeCheckout(__(ConstantConfig::OK_CALLBACK), $request);
    }

    /**
     * @param                  $comment
     * @param RequestInterface $request
     *
     * @throws AlreadyExistsException
     */
    private function completeCheckout($comment, RequestInterface $request)
    {
        $max_date       = '';
        $latestTransKey = '';
        $cardType       = '';
        $expires        = '';
        $setOrderStatus = true;
        $agreementType  = "unscheduled";
        $storeScope     = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $callback       = new Callback($request->getPostValue());
    
        try {
            $response                = $callback->call();
            $paymentType             = $response->type;
            $paymentStatus           = strtolower($response->paymentStatus);
            $ccToken                 = $response->creditCardToken;
            $maskedPan               = $response->maskedCreditCard;
            $paymentId               = $response->paymentId;
            $transactionId           = $response->transactionId;
            $parametersData          = json_encode($request->getPostValue());
            $transactionData         = json_encode($response);
            $orderState              = Order::STATE_PROCESSING;
            $statusKey               = 'process';
            $status                  = $response->status;
            $order                   = $this->orderLoader->getOrderByOrderIncrementId($response->shopOrderId);
            $quote                   = $this->quote->loadByIdWithoutStore($order->getQuoteId());
            $storeCode               = $order->getStore()->getCode();
            $orderStatusAfterPayment = $this->systemConfig->getStatusConfig('process', $storeScope, $storeCode);
            $orderStatusCapture      = $this->systemConfig->getStatusConfig('autocapture', $storeScope, $storeCode);
            $responseComment         = __(ConstantConfig::CONSUMER_CANCEL_PAYMENT);
            $historyComment          = __(ConstantConfig::CANCELLED) . '|' . $responseComment;
            
            foreach ($response->Transactions as $key => $transaction) {
                if ($transaction->CreatedDate > $max_date) {
                    $max_date       = $transaction->CreatedDate;
                    $latestTransKey = $key;
                }
            }
            
            if ($paymentStatus === 'released') {
                $this->handleCancelStatusAction($request, $response->status);
                return false;
            }
    
            if ($paymentType === 'subscriptionAndCharge' && $status === 'succeeded') {
                $transaction = $response->Transactions[$latestTransKey];
                $authType    = $transaction->AuthType;
                $transStatus = $transaction->TransactionStatus;
                
                if (isset($transaction) && $authType === 'subscription_payment' && $transStatus !== 'captured') {
                    //check if order status set in configuration
                    $statusKey         = Order::STATE_CANCELED;
                    $orderStatusCancel = $this->systemConfig->getStatusConfig('cancel', $storeScope, $storeCode);
    
                    if ($orderStatusCancel) {
                        $statusKey = $orderStatusCancel;
                    }
                    $this->handleOrderStateAction($request,  Order::STATE_CANCELED, $statusKey, $historyComment);
                    //save failed transaction data
                    $this->saveTransactionData($request, $response, $order);
    
                    return false;
                }
            }

            if ($order->getId()) {
                //Update stock quantity
                if ($order->getState() == 'canceled') {
                    $this->updateStockQty($order);
                }
                $this->resetCanceledQty($order);
                if (isset($response->Transactions[$latestTransKey])) {
                    $transaction = $response->Transactions[$latestTransKey];
                    if (isset($transaction->CreditCardExpiry->Month) && isset($transaction->CreditCardExpiry->Year)) {
                        $expires = $transaction->CreditCardExpiry->Month . '/' . $transaction->CreditCardExpiry->Year;
                    }
                    if (isset($transaction->PaymentSchemeName)) {
                        $cardType = $transaction->PaymentSchemeName;
                    }
                    if ($this->helper->validateQuote($quote)) {
                        $agreementType = "recurring";
                    }
                    if ($response->type === "verifyCard") {
                        $model = $this->dataToken->create();
                        $model->addData([
                            "customer_id" => $order->getCustomerId(),
                            "payment_id" => $paymentId,
                            "token" => $ccToken,
                            "agreement_id" => $transactionId,
                            "agreement_type" => $agreementType,
                            "masked_pan" => $maskedPan,
                            "currency_code" => $order->getOrderCurrencyCode(),
                            "expires" => $expires,
                            "card_type" => $cardType
                        ]);
                        try {
                            $model->save();
                        } catch (Exception $e) {
                            $this->altapayLogger->addCriticalLog('Exception', $e->getMessage());
                        }
                    }

                    $reconciliationData = $transaction->ReconciliationIdentifiers ?? '';

                    if($reconciliationData){
                        $model = $this->reconciliation->create();

                        foreach($reconciliationData as $key=>$value){
                            $collection = $this->helper->getReconciliationData($order->getIncrementId(), $value->Id);
                            if(!$collection->getSize()){
                                $model->addData([
                                    "order_id"      => $order->getIncrementId(),
                                    "identifier"    => $value->Id,
                                    "type"          => $value->Type
                                ]);
                            }
                        }

                        $model->save();
                    }
                }
                $payment = $order->getPayment();
                $payment->setPaymentId($paymentId);
                $payment->setLastTransId($transactionId);
                $payment->setCcTransId($response->creditCardToken);
                $payment->setAdditionalInformation('cc_token', $ccToken);
                $payment->setAdditionalInformation('masked_credit_card', $maskedPan);
                $payment->setAdditionalInformation('expires', $expires);
                $payment->setAdditionalInformation('card_type', $cardType);
                $payment->setAdditionalInformation('payment_type', $paymentType);
                $payment->save();
                //send order confirmation email
                $this->sendOrderConfirmationEmail($comment, $order);
                //unset redirect if success
                $this->checkoutSession->unsAltapayCustomerRedirect(); 
                //save transaction data
                $this->transactionRepository->addTransactionData(
                    $order->getIncrementId(),
                    $response->transactionId,
                    $response->paymentId,
                    $transactionData,
                    $parametersData
                );

                if ($this->isCaptured($response, $storeCode, $storeScope, $latestTransKey) && $orderStatusCapture == "complete")
                {
                    if ($this->orderLines->sendShipment($order)) {
                        $orderState = Order::STATE_COMPLETE;
                        $statusKey  = 'autocapture';
                        $order->addStatusHistoryComment(__(ConstantConfig::PAYMENT_COMPLETE));
                    } else {
                        $setOrderStatus = false;
                        $order->addStatusToHistory($orderStatusCapture, ConstantConfig::PAYMENT_COMPLETE, false);
                    }
                } elseif ($orderStatusAfterPayment) {
                    $orderState = $orderStatusAfterPayment;
                }
                if ($setOrderStatus) {
                    $this->paymentHandler->setCustomOrderStatus($order, $orderState, $statusKey);
                }
                $order->addStatusHistoryComment($comment);
                $order->addStatusHistoryComment($this->getTransactionInfoFromResponse($response));
                $order->setIsNotified(false);
                $order->getResource()->save($order);

                if (strtolower($paymentType) === 'paymentandcapture' || strtolower($paymentType) === 'subscriptionandcharge') {
                    $this->createInvoice($order, $response->requireCapture);
                }
            }
        } catch (\Exception $e) {
            $this->altapayLogger->addCriticalLog('Exception', $e->getMessage());
        }
    }

    /**
     * @param $response
     *
     * @return string
     */
    private function getTransactionInfoFromResponse($response)
    {
        return sprintf(
            "Transaction ID: %s - Payment ID: %s - Credit card token: %s",
            $response->transactionId,
            $response->paymentId,
            $response->creditCardToken
        );
    }

    /**
     * @param $response
     * @param $storeCode
     * @param $storeScope
     *
     * @return bool|\Magento\Payment\Model\MethodInterface
     */
    private function isCaptured($response, $storeCode, $storeScope, $latestTransKey)
    {
        $isCaptured = false;
        foreach (SystemConfig::getTerminalCodes() as $terminalName) {
            $terminalConfig = $this->systemConfig->getTerminalConfigFromTerminalName(
                $terminalName,
                'terminalname',
                $storeScope,
                $storeCode
            );
            if ($terminalConfig === $response->Transactions[$latestTransKey]->Terminal) {
                $isCaptured = $this->systemConfig->getTerminalConfigFromTerminalName(
                    $terminalName,
                    'capture',
                    $storeScope,
                    $storeCode
                );
                break;
            }
        }

        return $isCaptured;
    }

    /**
     * @param $comment
     * @param $order
     */
    private function sendOrderConfirmationEmail($comment, $order)
    {
        $currentStatus        = $order->getStatus();
        $orderHistories       = $order->getStatusHistories();
        $latestHistoryComment = array_pop($orderHistories);
        $prevStatus           = $latestHistoryComment->getStatus();

        $sendMail = true;
        if (strpos($comment, ConstantConfig::NOTIFICATION_CALLBACK) !== false && $currentStatus == $prevStatus) {
            $sendMail = false;
        }
        if (!$order->getEmailSent() && $sendMail == true) {
            $this->orderSender->send($order);
        }
    }

    /**
     * @param RequestInterface $request
     * @param                  $avsCode
     * @param                  $historyComment
     *
     * @return bool
     */
    public function avsCheck(RequestInterface $request, $avsCode, $historyComment)
    {
        $checkRejectionCase = false;
        $callback           = new Callback($request->getPostValue());
        $response           = $callback->call();
        if ($response) {
            $order                 = $this->loadOrderFromCallback($response);
            $storeScope            = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $storeCode             = $order->getStore()->getCode();
            $transInfo             = $this->getTransactionInfoFromResponse($response);
            $isAvsEnabled          = $this->checkAvsConfig($response, $storeCode, $storeScope, 'avscontrol');
            $isAvsEnforced         = $this->checkAvsConfig($response, $storeCode, $storeScope, 'enforceavs');
            $getAcceptedAvsResults = $this->getAcceptedAvsResults($response, $storeCode, $storeScope);

            if ($isAvsEnabled) {
                if ($isAvsEnforced && empty($avsCode)) {
                    $checkRejectionCase = true;
                } elseif (stripos($getAcceptedAvsResults, $avsCode) === false) {
                    $checkRejectionCase = true;
                }
            }
            if ($checkRejectionCase) {
                //check if order status set in configuration
                $statusKey         = Order::STATE_CANCELED;
                $orderStatusCancel = $this->systemConfig->getStatusConfig('cancel', $storeScope, $storeCode);
                //Save payment info in order to retrieve it for release operation
                if ($order->getId()) {
                    $this->savePaymentData($response, $order);
                }
                if ($orderStatusCancel) {
                    $statusKey = $orderStatusCancel;
                }
                $this->handleOrderStateAction($request, Order::STATE_CANCELED, $statusKey, $historyComment, $transInfo);
                //save failed transaction data
                $this->saveTransactionData($request, $response, $order);
            }
        }

        return $checkRejectionCase;
    }

    /**
     * @param $response
     * @param $storeCode
     * @param $storeScope
     * @param $configField
     *
     * @return bool
     */
    private function checkAvsConfig($response, $storeCode, $storeScope, $configField)
    {
        $isEnabled = false;
        foreach (SystemConfig::getTerminalCodes() as $terminalName) {
            $terminalConfig = $this->systemConfig->getTerminalConfigFromTerminalName(
                $terminalName,
                'terminalname',
                $storeScope,
                $storeCode
            );
            if ($terminalConfig === $response->Transactions[$this->getLatestTransaction($response)]->Terminal) {
                $isEnabled = $this->systemConfig->getTerminalConfigFromTerminalName(
                    $terminalName,
                    $configField,
                    $storeScope,
                    $storeCode
                );
                break;
            }
        }

        return $isEnabled;
    }

    /**
     * @param $response
     * @param $storeCode
     * @param $storeScope
     *
     * @return |null
     */
    private function getAcceptedAvsResults($response, $storeCode, $storeScope)
    {
        $acceptedAvsResults = null;
        foreach (SystemConfig::getTerminalCodes() as $terminalName) {
            $terminalConfig = $this->systemConfig->getTerminalConfigFromTerminalName(
                $terminalName,
                'terminalname',
                $storeScope,
                $storeCode
            );
            if ($terminalConfig === $response->Transactions[$this->getLatestTransaction($response)]->Terminal) {
                $acceptedAvsResults = $this->systemConfig->getTerminalConfigFromTerminalName(
                    $terminalName,
                    'avs_acceptance',
                    $storeScope,
                    $storeCode
                );
                break;
            }
        }

        return $acceptedAvsResults;
    }

    /**
     * @param $response
     * @param $order
     */
    private function savePaymentData($response, $order)
    {
        $payment = $order->getPayment();
        $payment->setPaymentId($response->paymentId);
        $payment->setLastTransId($response->transactionId);
        $payment->save();
    }

    /**
     * @param $order
     * return void
     */
    protected function updateStockQty($order)
    {
        $cart = $this->modelCart;
        $quoteItems = $this->checkoutSession->getQuote()->getItemsCollection();
        foreach ($order->getAllItems() as $item) {
            $stockQty  = $this->stockItem->getStockQty($item->getProductId(), $item->getStore()->getWebsiteId());
            $qty       = $stockQty - $item->getQtyOrdered();
            $stockItem = $this->stockRegistry->getStockItemBySku($item['sku']);
            $stockItem->setQty($qty);
            $stockItem->setIsInStock((bool)$qty);
            $this->stockRegistry->updateStockItemBySku($item['sku'], $stockItem);
        }
        foreach($quoteItems as $item)
        {
            $cart->removeItem($item->getId())->save();
        }
    }

    /**
     * @param $order
     *
     * @return void
     */
    private function resetCanceledQty($order) {
        foreach ($order->getAllItems() as $item) {
            if ($item->getQtyCanceled() > 0) {
                $item->setQtyCanceled($item->getQtyToCancel());
                $item->save();
            }
        }
    }
    
    /**
     * @param $response
     *
     * @return int|string
     */
    private function getLatestTransaction($response) {
        $max_date = '';
        $latestTransKey = 0;
        foreach ($response->Transactions as $key=>$value) {
            if ($value->AuthType === "subscription_payment" && $value->CreatedDate > $max_date) {
                $max_date = $value->CreatedDate;
                $latestTransKey = $key;
            }
        }
        return $latestTransKey;
    }
}