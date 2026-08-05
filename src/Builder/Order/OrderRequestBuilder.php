<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Builder\Order;

use MultiSafepay\Api\Transactions\CaptureRequest;
use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\Meta;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Helper\ManualCaptureHelper;
use MultiSafepay\Shopware6\Sources\Transaction\TransactionTypeSource;
use MultiSafepay\ValueObject\Money;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class OrderRequestBuilder
 *
 * This class is responsible for building the order request.
 *
 * Note (Shopware 6.7 / plugin 4.2.0): When upserting order customFields, always merge with
 * $order->getCustomFields(). In 6.7 the DAL replaces the whole customFields blob when you
 * pass a subset; passing only our keys would wipe other plugins' or payment-changer data.
 *
 * @package MultiSafepay\Shopware6\Builder\Order
 */
class OrderRequestBuilder
{
    use Maut1OrderRequestBuilderTrait;

    /**
     * @var \MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilderPool
     */
    private \MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilderPool $orderRequestBuilderPool;
    private EntityRepository $orderRepository;

    /**
     * @var ManualCaptureHelper
     */
    private ManualCaptureHelper $manualCaptureHelper;

    /**
     * OrderRequestBuilder constructor
     *
     * @param \MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilderPool $orderRequestBuilderPool
     * @param EntityRepository $orderRepository
     * @param ManualCaptureHelper|null $manualCaptureHelper
     */
    public function __construct(
        \MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilderPool $orderRequestBuilderPool,
        EntityRepository                                              $orderRepository,
        ?ManualCaptureHelper                                          $manualCaptureHelper = null
    )
    {
        $this->orderRequestBuilderPool = $orderRequestBuilderPool;
        $this->orderRepository = $orderRepository;
        $this->manualCaptureHelper = $manualCaptureHelper ?? new ManualCaptureHelper();
    }

    /**
     *  Build the order request
     *
     * @param PaymentTransactionStruct $transaction
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $gateway
     * @param string|null $type
     * @param array $gatewayInfo
     *
     * @return OrderRequest
     * @throws InvalidArgumentException
     */
    public function build(
        PaymentTransactionStruct $transaction,
        OrderEntity              $order,
        RequestDataBag           $dataBag,
        SalesChannelContext      $salesChannelContext,
        string                   $gateway,
        ?string                  $type = 'redirect',
        array                    $gatewayInfo = []
    ): OrderRequest
    {
        $orderRequest = new OrderRequest();

        // maut1: Custom logic starts here
        $context = $salesChannelContext->getContext();
        $amount = $this->handleAmount($gateway, $order);
        $orderNumberAndTime = $this->handleOrder($order, $context, $transaction);

        $meta = new Meta();
        $orderRequest->addOrderId($orderNumberAndTime)
            ->addMoney(
                new Money(
                    $amount,
                    $salesChannelContext->getCurrency()->getIsoCode()
                )
            )->addGatewayCode($gateway)->addGatewayInfo(
                $meta->addData($gatewayInfo)
            )->addType(
                !$dataBag->get('active_token') ? $type : TransactionTypeSource::TRANSACTION_TYPE_DIRECT_VALUE
            );

        if ($this->getPayload($dataBag)) {
            $orderRequest->addType(TransactionTypeSource::TRANSACTION_TYPE_DIRECT_VALUE);
            $orderRequest->addData(['payment_data' => ['payload' => $this->getPayload($dataBag)]]);
            $orderRequest->addRecurringModel('cardOnFile');
        }

        if ($dataBag->getBoolean('tokenize')) {
            $orderRequest->addData(['recurring_model' => 'cardOnFile']);
        }

        $paymentMethodCustomFields = $salesChannelContext->getPaymentMethod()->getCustomFields() ?? [];
        if ($this->manualCaptureHelper->isManualCaptureEnabledForGateway(
            $gateway,
            $paymentMethodCustomFields
        )) {
            $orderRequest->addData(['capture' => CaptureRequest::CAPTURE_MANUAL_TYPE]);
        }

        foreach ($this->orderRequestBuilderPool->getOrderRequestBuilders() as $orderRequestBuilder) {

            // maut1: Skip ShoppingCartBuilder at payment change to avoid cart items and amount in the request
            $isShoppingCartBuilder = is_a($orderRequestBuilder, 'MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder');
            if ($isShoppingCartBuilder && $this->isPaymentChange($order)) {
                continue;
            }
            $orderRequestBuilder->build($order, $orderRequest, $transaction, $dataBag, $salesChannelContext);
        }

        return $orderRequest;
    }

    /**
     *  Get the payload
     *
     * @param RequestDataBag $dataBag
     * @return string|null
     */
    private function getPayload(RequestDataBag $dataBag): ?string
    {
        if ($dataBag->get('payload')) {
            return $dataBag->get('payload');
        }

        $request = (new Request($_GET, $_POST, array(), $_COOKIE, $_FILES, $_SERVER))->request;
        return $request->get('payload') ?: null;
    }

    /**
     * Check if the order is a payment change
     * @param OrderEntity $order
     * @return bool
     */
    private function isPaymentChange(OrderEntity $order): bool
    {
        $customFields = $order->getCustomFields();
        return $customFields != null && array_key_exists('changePayment', $customFields);
    }

    /**
     * Handle amount for payment change scenarios
     * @param string $gateway
     * @param OrderEntity $order
     * @return float
     */
    private function handleAmount(string $gateway, OrderEntity $order): float
    {
        $lower = strtolower($gateway);
        $amount = $order->getAmountTotal() * 100;

        if ($this->isPaymentChange($order)) {
            if ($lower === 'dirdeb' || $lower === 'ideal' || $lower === 'directbank') {
                return 1.0;
            } else {
                return 0.0;
            }
        }

        if ($amount === 0.0 && ($lower === 'dirdeb' || $lower === 'ideal' || $lower === 'directbank')) {
            return 1.0;
        }

        return $amount;
    }

    /**
     * MultiSafepay requires a unique order ID for each transaction (error 1006 if reused).
     * - Payment change: orderNumber + 10-digit timestamp; previous orderId values are appended
     *   to customFields.orderIdHistory; merge customFields (6.7-safe).
     * - Normal checkout: return the existing orderNumber unchanged.
     *
     * @param OrderEntity $order
     * @param Context $context
     * @param PaymentTransactionStruct $transaction
     * @return string
     */
    private function handleOrder(OrderEntity $order, Context $context, PaymentTransactionStruct $transaction): string
    {
        $orderNumber = $order->getOrderNumber();
        if ($this->isPaymentChange($order)) {
            $orderNumberAndTime = $orderNumber . time();
            $existingCustomFields = $order->getCustomFields() ?? [];

            $customFields = array_merge($existingCustomFields, [
                'changePayment' => true,
                'orderId' => $orderNumberAndTime,
            ]);

            $orderIdHistory = $this->appendPreviousOrderIdToHistory($existingCustomFields);
            if ($orderIdHistory !== null) {
                $customFields['orderIdHistory'] = $orderIdHistory;
            }

            $this->orderRepository->upsert([[
                'id' => $order->getId(),
                'customFields' => $customFields,
            ]], $context);
            return $orderNumberAndTime;
        }
        return $orderNumber;
    }
}
