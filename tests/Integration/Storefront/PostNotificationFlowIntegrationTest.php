<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 * Exercises the same steps as NotificationController::postNotification() after a MultiSafepay
 * payment: resolve Shopware order from the MultiSafepay transaction id (plain order number or
 * payment-change id with a 10-digit suffix), verify the HMAC notification, parse JSON, then
 * transition payment state. Kept separate from HTTP route tests because the controller reads
 * php://input and PHP superglobals, which PHPUnit’s kernel client does not populate.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Storefront;

use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Handlers\IdealPaymentHandler;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Util\Notification;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use const JSON_THROW_ON_ERROR;

class PostNotificationFlowIntegrationTest extends TestCase
{
    use IntegrationTestBehaviour;
    use Orders;
    use Transactions;
    use Customers;
    use PaymentMethods {
        IntegrationTestBehaviour::getContainer insteadof Transactions;
        IntegrationTestBehaviour::getContainer insteadof Customers;
        IntegrationTestBehaviour::getContainer insteadof PaymentMethods;
        IntegrationTestBehaviour::getContainer insteadof Orders;
        IntegrationTestBehaviour::getKernel insteadof Transactions;
        IntegrationTestBehaviour::getKernel insteadof Customers;
        IntegrationTestBehaviour::getKernel insteadof PaymentMethods;
        IntegrationTestBehaviour::getKernel insteadof Orders;
    }

    private const TEST_API_KEY = 'test-msp-post-notification-hmac-key';

    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = Context::createDefaultContext();
    }

    public function testPostNotificationFlowWithPlainOrderNumberAsTransactionId(): void
    {
        $orderNumber = $this->uniqueOrderNumber();
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $this->assignOrderNumber($orderId, $orderNumber);

        $paymentMethodId = $this->createPaymentMethod($this->context, IdealPaymentHandler::class);
        $orderTransactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        $this->setTestPluginApiKey();

        $mspTransactionId = $orderNumber;
        $body = $this->notificationBodyJson();

        static::assertTrue(Notification::verifyNotification($body, $this->authHeaderForBody($body), self::TEST_API_KEY));

        $this->applyPostNotificationTransitions($mspTransactionId, $body, $orderTransactionId);
    }

    public function testPostNotificationFlowWithPaymentChangeStyleTransactionId(): void
    {
        $orderNumber = $this->uniqueOrderNumber();
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $this->assignOrderNumber($orderId, $orderNumber);

        $paymentMethodId = $this->createPaymentMethod($this->context, IdealPaymentHandler::class);
        $orderTransactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        $suffix = '1234567890';
        static::assertMatchesRegularExpression('/\d{10}$/', $suffix);
        $mspTransactionId = $orderNumber . $suffix;

        static::getContainer()->get('order.repository')->update([
            [
                'id' => $orderId,
                'customFields' => [
                    'orderId' => $orderNumber . (string) time(),
                    'changePayment' => true,
                ],
            ],
        ], $this->context);

        $this->setTestPluginApiKey();

        $body = $this->notificationBodyJson();
        static::assertTrue(Notification::verifyNotification($body, $this->authHeaderForBody($body), self::TEST_API_KEY));

        $this->applyPostNotificationTransitions($mspTransactionId, $body, $orderTransactionId);
    }

    private function uniqueOrderNumber(): string
    {
        return 'NPF' . strtoupper(bin2hex(random_bytes(5)));
    }

    private function assignOrderNumber(string $orderId, string $orderNumber): void
    {
        static::getContainer()->get('order.repository')->update([
            ['id' => $orderId, 'orderNumber' => $orderNumber],
        ], $this->context);
    }

    private function setTestPluginApiKey(): void
    {
        /** @var SystemConfigService $systemConfig */
        $systemConfig = static::getContainer()->get(SystemConfigService::class);
        $systemConfig->set('MltisafeMultiSafepay.config.apiKey', self::TEST_API_KEY, $this->getSalesChannelId());
    }

    private function notificationBodyJson(): string
    {
        return json_encode(
            [
                'status' => 'completed',
                'payment_details' => ['type' => 'IDEAL'],
            ],
            JSON_THROW_ON_ERROR
        );
    }

    private function authHeaderForBody(string $body): string
    {
        $timestamp = (string) time();
        $payload = $timestamp . ':' . $body;
        $hash = hash_hmac('sha512', $payload, trim(self::TEST_API_KEY));

        return base64_encode($timestamp . ':' . $hash);
    }

    /**
     * Mirrors NotificationController::postNotification() resolution and transitions (without HTTP).
     */
    private function applyPostNotificationTransitions(string $mspTransactionId, string $body, string $orderTransactionId): void
    {
        $isPaymentChange = preg_match('/\d{10}$/', $mspTransactionId) === 1;
        $resolvedOrderNumber = $isPaymentChange ? substr($mspTransactionId, 0, -10) : $mspTransactionId;

        $orderUtil = static::getContainer()->get(OrderUtil::class);
        $order = $orderUtil->getOrderFromNumber($resolvedOrderNumber);
        static::assertNotNull($order);

        $transactionResponse = new TransactionResponse(json_decode($body, true, 512, JSON_THROW_ON_ERROR), $body);
        $status = $this->statusForNotification($transactionResponse, $isPaymentChange);

        $checkoutHelper = static::getContainer()->get(CheckoutHelper::class);
        $shopwareTransaction = $order->getTransactions()?->first();
        static::assertNotNull($shopwareTransaction);

        $checkoutHelper->transitionPaymentState($status, $orderTransactionId, $this->context);
        $checkoutHelper->transitionPaymentMethodIfNeeded(
            $shopwareTransaction,
            $this->context,
            $transactionResponse->getPaymentDetails()->getType()
        );

        $this->assertOrderTransactionIsPaid($orderTransactionId);
    }

    /**
     * Same rules as NotificationController::getStatus().
     */
    private function statusForNotification(TransactionResponse $transaction, bool $isPaymentChange): string
    {
        $status = $transaction->getStatus();
        if ($isPaymentChange && $transaction->getPaymentDetails()->getType() === 'DIRDEB') {
            return 'completed';
        }

        return $status;
    }

    private function assertOrderTransactionIsPaid(string $orderTransactionId): void
    {
        $repo = static::getContainer()->get('order_transaction.repository');
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('stateMachineState');
        $transaction = $repo->search($criteria, $this->context)->get($orderTransactionId);
        static::assertSame(
            OrderTransactionStates::STATE_PAID,
            $transaction->getStateMachineState()?->getTechnicalName()
        );
    }
}
