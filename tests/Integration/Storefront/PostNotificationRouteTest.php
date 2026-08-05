<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 * Verifies the storefront POST route for MultiSafepay post-notifications is registered,
 * matches POST requests to `/multisafepay/notification` (including `transactionid` as plain
 * order number or payment-change id), and targets NotificationController::postNotification.
 *
 * End-to-end handling of the signed body is covered in PostNotificationFlowIntegrationTest
 * (NotificationController uses php://input / superglobals, which PHPUnit’s HttpKernel client
 * does not populate).
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Storefront;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

class PostNotificationRouteTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testPostNotificationRouteIsRegisteredWithPostMethodAndController(): void
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');
        $postRoute = $router->getRouteCollection()->get('frontend.multisafepay.postnotification');

        static::assertNotNull($postRoute);
        static::assertSame('/multisafepay/notification', $postRoute->getPath());
        static::assertSame(['POST'], $postRoute->getMethods());
        static::assertSame(
            'MultiSafepay\\Shopware6\\Storefront\\Controller\\NotificationController::postNotification',
            $postRoute->getDefault('_controller')
        );
        static::assertContains('storefront', (array) $postRoute->getDefault('_routeScope'));
        static::assertContains(false, (array) $postRoute->getDefault('csrf_protected'));
    }

    public function testGetNotificationRouteMatchesGetRequestToMultisafepayNotificationPath(): void
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        $request = Request::create(
            'https://localhost/multisafepay/notification',
            'GET'
        );

        $matched = $router->matchRequest($request);

        static::assertSame('frontend.multisafepay.notification', $matched['_route']);
        static::assertSame(
            'MultiSafepay\\Shopware6\\Storefront\\Controller\\NotificationController::notification',
            $matched['_controller']
        );
    }

    public function testPostNotificationRouteSharesPathWithGetAndExpectsTransactionIdInQueryString(): void
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        $getRoute = $router->getRouteCollection()->get('frontend.multisafepay.notification');
        $postRoute = $router->getRouteCollection()->get('frontend.multisafepay.postnotification');

        static::assertNotNull($getRoute);
        static::assertNotNull($postRoute);
        static::assertSame($getRoute->getPath(), $postRoute->getPath());
        static::assertSame(['GET'], $getRoute->getMethods());
        static::assertSame(['POST'], $postRoute->getMethods());

        // MultiSafepay uses the same path with ?transactionid= (plain order number or order number + 10 digits).
        static::assertSame(
            '/multisafepay/notification?transactionid=12345',
            $postRoute->getPath() . '?' . http_build_query(['transactionid' => '12345'])
        );
        static::assertSame(
            '/multisafepay/notification?transactionid=123451234567890',
            $postRoute->getPath() . '?' . http_build_query(['transactionid' => '123451234567890'])
        );
    }

    public function testPostWithoutPluginRoutePrefixDoesNotMatch(): void
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        $request = Request::create('https://localhost/unknown-post', 'POST');

        $this->expectException(ResourceNotFoundException::class);
        $router->matchRequest($request);
    }
}
