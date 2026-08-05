<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Storefront\Controller;

use Exception;
use JsonException;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\RequestUtil;
use MultiSafepay\Util\Notification;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TypeError;

/**
 * Class NotificationController
 *
 * @package MultiSafepay\Shopware6\Storefront\Controller
 */
class NotificationController extends StorefrontController
{
    /**
     * @var CheckoutHelper
     */
    private CheckoutHelper $checkoutHelper;

    /**
     * @var Request
     */
    private Request $request;

    /**
     * @var SdkFactory
     */
    private SdkFactory $sdkFactory;

    /**
     * @var OrderUtil
     */
    private OrderUtil $orderUtil;

    /**
     * @var SettingsService
     */
    private SettingsService $config;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * NotificationController constructor
     *
     * @param CheckoutHelper $checkoutHelper
     * @param SdkFactory $sdkFactory
     * @param RequestUtil $requestUtil
     * @param OrderUtil $orderUtil
     * @param SettingsService $settingsService
     * @param LoggerInterface $logger
     */
    public function __construct(
        CheckoutHelper  $checkoutHelper,
        SdkFactory      $sdkFactory,
        RequestUtil     $requestUtil,
        OrderUtil       $orderUtil,
        SettingsService $settingsService,
        LoggerInterface $logger
    )
    {
        $this->checkoutHelper = $checkoutHelper;
        $this->request = $requestUtil->getGlobals();
        $this->sdkFactory = $sdkFactory;
        $this->orderUtil = $orderUtil;
        $this->config = $settingsService;
        $this->logger = $logger;
    }

    /**
     * Check if the id has an unix timestamp at the end (indicating a payment change)
     *
     * Maut1: order numbers themselves can end in a 10-digit block (e.g. the local/test
     * number-range format <customerNumber>_<YYMMDDHHmm>) — an id that matches an existing
     * order number EXACTLY is a normal checkout, never a payment change. Only ids without an
     * exact order match are treated as payment-change ids (orderNumber + appended timestamp).
     *
     * @param string $id
     * @return bool
     */
    private function isPaymentChange(string $id): bool
    {
        if (preg_match('/\d{10}$/', $id) !== 1) {
            return false;
        }

        return is_null($this->findOrderFromNumber($id));
    }

    /**
     * Null-safe order lookup by order number. OrderUtil::getOrderFromNumber() declares a
     * non-nullable return type but forwards `->first()`, which yields null for unknown
     * numbers — that TypeError previously escaped as a 500 on the notification route.
     *
     * @param string $orderNumber
     * @return OrderEntity|null
     */
    private function findOrderFromNumber(string $orderNumber): ?OrderEntity
    {
        try {
            return $this->orderUtil->getOrderFromNumber($orderNumber);
        } catch (TypeError|InconsistentCriteriaIdsException) {
            return null;
        }
    }

    /**
     * Resolve MultiSafepay transaction id to Shopware order number.
     * - Payment change: id is orderNumber + 10-digit timestamp; strip the timestamp.
     * - Unique id format (orderNumber-xxx-timestamp): strip to orderNumber (before first '-').
     * - Plain order number: return as-is.
     *
     * @param bool $isPaymentChange
     * @param string $id
     * @return string
     */
    private function getOrderNumber(bool $isPaymentChange, string $id): string
    {
        if ($isPaymentChange) {
            return substr($id, 0, -10);
        }

        return $id;
    }

    /**
     * Get the correct status considering payment changes
     * @param TransactionResponse $transaction
     * @param bool $isPaymentChange
     * @return string
     */
    private function getStatus(TransactionResponse $transaction, bool $isPaymentChange): string
    {
        $status = $transaction->getStatus();

        // Maut1: For payment changes with direct debit, we set the status to completed
        if ($isPaymentChange && $transaction->getPaymentDetails()->getType() == 'DIRDEB') {
            return 'completed';
        }

        return $status;
    }

    /**
     *  Handle the notification
     *
     * @param Context $context
     * @return Response
     * @throws ClientExceptionInterface
     */
    public function notification(Context $context): Response
    {
        $response = new Response();
        $id = $this->request->query->getString('transactionid');

        // Maut1: Check if this is a payment change
        $isPaymentChange = $this->isPaymentChange($id);
        $orderNumber = $this->getOrderNumber($isPaymentChange, $id);
        $order = $this->findOrderFromNumber($orderNumber);
        if (is_null($order)) {
            $this->logger->warning('Order not found for MultiSafepay notification', [
                'message' => 'Could not find order for notification',
                'orderNumber' => $orderNumber,
                'orderId' => 'unknown'
            ]);

            return $response->setContent('NG');
        }

        $getTransactions = $order->getTransactions();
        if (is_null($getTransactions)) {
            return $response->setContent('NG');
        }

        $transaction = $getTransactions->first();
        $transactionId = $transaction->getId();

        try {
            $result = $this->sdkFactory->create($order->getSalesChannelId())
                ->getTransactionManager()->get($id);
        } catch (Exception $exception) {
            $this->logger->error('Order not found in notification', [
                'message' => 'Could not find order by order number',
                'orderNumber' => $orderNumber,
                'orderId' => $order->getId(),
                'salesChannelId' => $order->getSalesChannelId(),
                'exceptionMessage' => $exception->getMessage(),
                'exceptionCode' => $exception->getCode()
            ]);
            return $response->setContent('NG');
        }

        // Maut1: Get the correct status considering payment changes. Deliberately kept on
        // transitionPaymentState() rather than 4.3's transitionPaymentStateFromTransaction() —
        // the latter doesn't delegate through transitionPaymentState() internally, so
        // MauteinsPaymentChanger's MultiSafepayCheckoutHelperDecorator (uncleared/direct-debit ->
        // in_progress override) would silently stop firing on this webhook path.
        $status = $this->getStatus($result, $isPaymentChange);
        $this->checkoutHelper->transitionPaymentState($status, $transactionId, $context);

        $paymentDetails = $result->getPaymentDetails();
        $wallet = $paymentDetails->get('wallet');
        $wallet = is_string($wallet) ? trim($wallet) : null;
        $wallet = $wallet !== '' ? $wallet : null;
        $this->checkoutHelper->transitionPaymentMethodIfNeeded(
            $transaction,
            $context,
            $paymentDetails->getType(),
            $wallet
        );

        return $response->setContent('OK');
    }

    /**
     *  Handle the post-notification
     *
     * @return Response
     * @throws InvalidArgumentException
     */
    public function postNotification(): Response
    {
        $response = new Response();
        $id = $this->request->query->getString('transactionid');

        // Maut1: Check if this is a payment change
        $isPaymentChange = $this->isPaymentChange($id);
        $orderNumber = $this->getOrderNumber($isPaymentChange, $id);
        $order = $this->findOrderFromNumber($orderNumber);
        if (is_null($order)) {
            $this->logger->warning('Order not found in post-notification', [
                'message' => 'Could not find order by order number',
                'orderNumber' => $orderNumber,
                'orderId' => 'unknown'
            ]);
            return $response->setContent('NG');
        }

        $getTransactions = $order->getTransactions();
        if (is_null($getTransactions)) {
            return $response->setContent('NG');
        }

        $shopwareTransaction = $getTransactions->first();
        if (is_null($shopwareTransaction)) {
            return $response->setContent('NG');
        }

        $transactionId = $shopwareTransaction->getId();
        $body = file_get_contents('php://input');

        if (!$body) {
            return $response->setContent('NG');
        }

        $hasAuthHeader = isset($_SERVER['HTTP_AUTH']);
        if (!Notification::verifyNotification(
            $body,
            $_SERVER['HTTP_AUTH'] ?? '',
            $this->config->getApiKey($order->getSalesChannelId())
        )) {
            $this->logger->warning('Post-notification verification failed', [
                'message' => 'Notification signature verification failed',
                'orderNumber' => $orderNumber,
                'orderId' => $order->getId(),
                'hasAuthHeader' => $hasAuthHeader,
                'bodyLength' => strlen($body)
            ]);

            return $response->setContent('NG');
        }

        try {
            $transaction = new TransactionResponse(json_decode($body, true, 512, JSON_THROW_ON_ERROR), $body);
        } catch (JsonException $jsonException) {
            $this->logger->error('Failed to parse post-notification JSON', [
                'message' => 'Could not decode JSON from notification body',
                'orderNumber' => $orderNumber,
                'orderId' => $order->getId(),
                'bodyLength' => strlen($body),
                'exceptionMessage' => $jsonException->getMessage(),
                'exceptionCode' => $jsonException->getCode()
            ]);

            return $response->setContent('JSON Error: ' . $jsonException->getMessage());
        }

        $context = Context::createDefaultContext();

        // Maut1: Get the correct status considering payment changes — same rationale as
        // notification() above (keep transitionPaymentState(), not transitionPaymentStateFromTransaction()).
        $status = $this->getStatus($transaction, $isPaymentChange);
        $this->checkoutHelper->transitionPaymentState($status, $transactionId, $context);

        $paymentDetails = $transaction->getPaymentDetails();
        $wallet = $paymentDetails->get('wallet');
        $wallet = is_string($wallet) ? trim($wallet) : null;
        $wallet = $wallet !== '' ? $wallet : null;
        $this->checkoutHelper->transitionPaymentMethodIfNeeded(
            $shopwareTransaction,
            $context,
            $paymentDetails->getType(),
            $wallet
        );

        return $response->setContent('OK');
    }
}
