<?php

namespace MultiSafepay\Shopware6\Tests\Fixtures;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\Test\TestDefaults;

trait Maut1SalesChannel
{
    use KernelTestBehaviour;

    private function getSalesChannelId(): string
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $id = static::getContainer()->get('sales_channel.repository')->searchIds($criteria, $this->context)->firstId();
        return $id;
    }

    private function getSalesChannelLanguageId(): string
    {
        $salesChannel = static::getContainer()->get('sales_channel.repository')
            ->search(new Criteria([$this->getSalesChannelId()]), $this->context)
            ->first();
        return $salesChannel->getLanguageId();
    }

    /**
     * @param string|null $salesChannelId (null when no saleschannel filtering)
     */
    protected function getMaut1ValidCountryId(): string
    {
        $salesChannelId = $this->getSalesChannelId();
        /** @var EntityRepository<CountryCollection> $repository */
        $repository = static::getContainer()->get('country.repository');

        $criteria = (new Criteria())->setLimit(1)
            ->addFilter(new EqualsFilter('active', true))
            ->addFilter(new EqualsFilter('shippingAvailable', true))
            ->addSorting(new FieldSorting('iso'));

        if ($salesChannelId !== null) {
            $criteria->addFilter(new EqualsFilter('salesChannels.id', $salesChannelId));
        }

        /** @var string $id */
        $id = $repository->searchIds($criteria, Context::createDefaultContext())->firstId();

        return $id;
    }

}