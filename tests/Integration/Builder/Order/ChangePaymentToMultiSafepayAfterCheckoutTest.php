<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 * Ensures that an already-paid order can be sent to MultiSafepay when the payment
 * method is changed to MultiSafepay after checkout (e.g. for recurring/next payment).
 * Plugin extensions may implement this with custom behaviour (unique order ID, 1 cent
 * amount for DIRDEB); this test verifies the base plugin still builds a valid
 * OrderRequest for such orders so that extensions can rely on it.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Builder\Order;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\Checkout\Payment\Cart\Token\JWTFactoryV2;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ChangePaymentToMultiSafepayAfterCheckoutTest extends TestCase
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
    private string $customerId;
    private string $orderId;
    private string $orderTransactionId;
    private string $paymentMethodId;
    private EntityRepository $orderRepository;
    private EntityRepository $orderTransactionRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = Context::createDefaultContext();
        $this->orderRepository = static::getContainer()->get('order.repository');
        $this->orderTransactionRepository = static::getContainer()->get('order_transaction.repository');
        $this->createAlreadyPaidOrderWithChangePaymentFlag();
    }


    /**
     * When an order is already paid and the payment method is changed to MultiSafepay
     * (e.g. via custom field changePayment), the OrderRequestBuilder must still produce
     * a valid OrderRequest so that redirect and finalize flows work (possibly with
     * extension-specific behaviour like unique order ID and 1 cent amount).
     */
    public function testOrderRequestCanBeBuiltForAlreadyPaidOrderWithChangePaymentToMultiSafepay(): void
    {
        $order = $this->loadOrder();
        static::assertNotNull($order, 'Order must exist');
        static::assertTrue(
            isset($order->getCustomFields()['changePayment']) && $order->getCustomFields()['changePayment'] === true,
            'Order must have changePayment custom field for payment change scenario'
        );

        $orderRequestBuilder = static::getContainer()->get(OrderRequestBuilder::class);
        $returnUrl = $this->buildReturnUrlWithPaymentToken();
        $transactionStruct = new PaymentTransactionStruct($this->orderTransactionId, $returnUrl);
        $dataBag = new RequestDataBag([]);
        $salesChannelContext = $this->createSalesChannelContext();

        $orderRequest = $orderRequestBuilder->build(
            $transactionStruct,
            $order,
            $dataBag,
            $salesChannelContext,
            'DIRDEB',
            'redirect',
            []
        );

        static::assertInstanceOf(OrderRequest::class, $orderRequest);
        static::assertStringContainsString($order->getOrderNumber(), $orderRequest->getOrderId(), 'Order ID must be contained on orderId + timestamp');

        $orderIdWithTimestamp = $orderRequest->getOrderId();
        // Try to load the order from the database using a customFields filter (orderId in custom fields)
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.orderId', $orderIdWithTimestamp));
        $orderWithTimestamp = $this->orderRepository->search($criteria, $this->context)->first();

        // Assert that the orderIdWithTimestamp is equal to the custom fields orderId
        static::assertEquals(
            $orderIdWithTimestamp,
            $orderWithTimestamp?->getCustomFields()['orderId'] ?? null,
            'Custom field orderId should match the generated orderIdWithTimestamp'
        );

        static::assertNotNull(
            $orderWithTimestamp,
            sprintf('Order with new orderNumber "%s" should be found in the database.', $orderIdWithTimestamp)
        );

        static::assertIsInt($orderRequest->getAmount());
        static::assertGreaterThanOrEqual(0, $orderRequest->getAmount(), 'Amount must be non-negative');
    }

    private function createAlreadyPaidOrderWithChangePaymentFlag(): void
    {
        $this->customerId = $this->createCustomer($this->context);
        $this->orderId = $this->createOrder($this->customerId, $this->context);
        $this->paymentMethodId = $this->createPaymentMethod($this->context);
        $this->orderTransactionId = $this->createTransaction($this->orderId, $this->paymentMethodId, $this->context);

        $paidStateId = $this->getStateMachineState(
            OrderTransactionStates::STATE_MACHINE,
            OrderTransactionStates::STATE_PAID
        );
        $this->orderTransactionRepository->update([
            ['id' => $this->orderTransactionId, 'stateId' => $paidStateId],
        ], $this->context);

        $this->orderRepository->update([
            ['id' => $this->orderId, 'customFields' => ['changePayment' => true]],
        ], $this->context);
    }

    private function loadOrder(): ?OrderEntity
    {
        $criteria = new Criteria([$this->orderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('currency');

        return $this->orderRepository->search($criteria, $this->context)->first();
    }

    /**
     * Build a return URL containing a valid payment token so PaymentOptionsBuilder can parse it.
     */
    private function buildReturnUrlWithPaymentToken(): string
    {
        $tokenFactory = static::getContainer()->get(JWTFactoryV2::class);
        $router = static::getContainer()->get(UrlGeneratorInterface::class);
        $tokenStruct = new TokenStruct(
            null,
            null,
            $this->paymentMethodId,
            $this->orderTransactionId,
            null,
            1800,
            null
        );
        $paymentToken = $tokenFactory->generateToken($tokenStruct);

        return $router->generate(
            'payment.finalize.transaction',
            ['_sw_payment_token' => $paymentToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    private function createSalesChannelContext(): \Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        $factory = static::getContainer()->get(CachedSalesChannelContextFactory::class);
        $token = Uuid::randomHex();

        return $factory->create($token, $this->getSalesChannelId(), [
            SalesChannelContextService::CUSTOMER_ID => $this->customerId,
        ]);
    }
}
