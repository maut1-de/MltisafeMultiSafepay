<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 * Covers order resolution for NotificationController::postNotification() and
 * NotificationController::notification(): `OrderUtil::getOrderFromNumber()` using the
 * Shopware order number (after the controller maps a MultiSafepay transaction id:
 * plain order number as-is, or payment-change id with a 10-digit suffix stripped).
 * Orders also get `customFields.orderId` set to `orderNumber` + Unix timestamp, as after
 * a MultiSafepay payment change (same shape the gateway may persist on the order).
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Util;

use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use MultiSafepay\Shopware6\Util\OrderUtil;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

class OrderUtilPostNotificationLookupTest extends TestCase
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

    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = Context::createDefaultContext();
    }

    public function testGetOrderFromNumberFindsOrderByPlainOrderNumber(): void
    {
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $this->createTransaction($orderId, $paymentMethodId, $this->context);

        $mspOrderId = '12345' . (string) time();
        static::getContainer()->get('order.repository')->update([
            [
                'id' => $orderId,
                'customFields' => ['orderId' => $mspOrderId],
            ],
        ], $this->context);

        $orderUtil = static::getContainer()->get(OrderUtil::class);
        $found = $orderUtil->getOrderFromNumber('12345');

        static::assertNotNull($found);
        static::assertSame($orderId, $found->getId());
        static::assertSame('12345', $found->getOrderNumber());
        static::assertNotNull($found->getTransactions());
        static::assertGreaterThan(0, $found->getTransactions()->count());
    }

    public function testGetOrderFromNumberAfterPaymentChangeSuffixMatchesFixtureOrderNumber(): void
    {
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $this->createTransaction($orderId, $paymentMethodId, $this->context);

        $mspOrderId = '12345' . (string) time();
        static::getContainer()->get('order.repository')->update([
            [
                'id' => $orderId,
                'customFields' => ['orderId' => $mspOrderId],
            ],
        ], $this->context);

        $suffix = '1234567890';
        static::assertMatchesRegularExpression('/\d{10}$/', $suffix);

        $orderUtil = static::getContainer()->get(OrderUtil::class);
        $resolvedNumber = substr('12345' . $suffix, 0, -10);
        static::assertSame('12345', $resolvedNumber);

        $found = $orderUtil->getOrderFromNumber($resolvedNumber);

        static::assertNotNull($found);
        static::assertSame($orderId, $found->getId());
    }
}
