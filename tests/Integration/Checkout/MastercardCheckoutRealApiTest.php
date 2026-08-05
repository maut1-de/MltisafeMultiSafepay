<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 * Integration test: checkout a cart with Mastercard and perform a real API call
 * to the MultiSafepay API. Validates that the API accepts the order and returns
 * a valid payment URL.
 *
 * Uses the current plugin config from the database (API key and environment).
 * Skipped if the plugin API key is not configured for the sales channel.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Checkout;

use MultiSafepay\Shopware6\Handlers\MastercardPaymentHandler;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\Cart\Token\JWTFactoryV2;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MastercardCheckoutRealApiTest extends TestCase
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
    private string $salesChannelId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = Context::createDefaultContext();
        $this->salesChannelId = $this->getSalesChannelId();

        $this->customerId = $this->createCustomer($this->context);
        $this->paymentMethodId = $this->createPaymentMethod($this->context, MastercardPaymentHandler::class);
        $this->orderId = $this->createOrder($this->customerId, $this->context);
        $this->orderTransactionId = $this->createTransaction($this->orderId, $this->paymentMethodId, $this->context);

        $orderRepository = static::getContainer()->get('order.repository');
        $orderRepository->update([
            ['id' => $this->orderId, 'paymentMethodId' => $this->paymentMethodId],
        ], $this->context);
    }

    /**
     * Checks out with Mastercard: builds order request, calls MultiSafepay API, and asserts
     * the response is valid (payment URL present and pointing to MultiSafepay).
     */
    public function testCheckoutWithMastercardCallsRealMultiSafepayApiAndReturnsValidResponse(): void
    {
        $handler = static::getContainer()->get(MastercardPaymentHandler::class);
        $returnUrl = $this->buildReturnUrlWithPaymentToken();
        $transactionStruct = new PaymentTransactionStruct($this->orderTransactionId, $returnUrl);
        $request = new Request();

        $response = $handler->pay($request, $transactionStruct, $this->context, null);

        static::assertNotNull($response, 'Payment handler must return a redirect response');
        static::assertInstanceOf(
            \Symfony\Component\HttpFoundation\RedirectResponse::class,
            $response,
            'Response must be a RedirectResponse with payment URL'
        );

        $targetUrl = $response->getTargetUrl();
        static::assertNotEmpty($targetUrl, 'Payment URL must not be empty');
        static::assertStringContainsString(
            'multisafepay',
            strtolower($targetUrl),
            'Payment URL must point to MultiSafepay (test or live)'
        );
        static::assertSame(302, $response->getStatusCode(), 'Redirect must be 302');
    }

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
}
