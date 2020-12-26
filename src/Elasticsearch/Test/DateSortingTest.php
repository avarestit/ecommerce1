<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Test;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\QueueTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DateSortingTest extends TestCase
{
    use IntegrationTestBehaviour;
    use ElasticsearchTestTestBehaviour;
    use QueueTestBehaviour;

    public function testSorting(): void
    {
        $productRepository = $this->getContainer()->get('product.repository');

        $a = [
            'id' => 'aec0aaa924a344ab81cd0ec7f96e8291',
            'productNumber' => 'aec0aaa924a344ab81cd0ec7f96e8291',
            'name' => 'a',
            'stock' => 111,
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 11, 'net' => 11, 'linked' => false],
            ],
            'manufacturer' => ['id' => Uuid::randomHex(), 'name' => 'test'],
            'tax' => ['id' => Uuid::randomHex(), 'name' => 'test', 'taxRate' => 15],
            'releaseDate' => new \DateTime('2020-07-23 12:21:59.736+00:00'),
            'customFields' => [
                'testField' => 'test',
            ],
        ];
        $b = [
            'id' => 'bec0aaa924a344ab81cd0ec7f96e8291',
            'productNumber' => 'bec0aaa924a344ab81cd0ec7f96e8291',
            'name' => 'b',
            'stock' => 111,
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 11, 'net' => 11, 'linked' => false],
            ],
            'manufacturer' => ['id' => Uuid::randomHex(), 'name' => 'test'],
            'tax' => ['id' => Uuid::randomHex(), 'name' => 'test', 'taxRate' => 15],
            'releaseDate' => new \DateTime('2020-07-23 12:21:59.814+00:00'),
            'customFields' => [
                'testField' => 'test',
            ],
        ];
        $c = [
            'id' => 'cec0aaa924a344ab81cd0ec7f96e8291',
            'productNumber' => 'cec0aaa924a344ab81cd0ec7f96e8291',
            'name' => 'c',
            'stock' => 111,
            'price' => [
                ['currencyId' => Defaults::CURRENCY, 'gross' => 11, 'net' => 11, 'linked' => false],
            ],
            'manufacturer' => ['id' => Uuid::randomHex(), 'name' => 'test'],
            'tax' => ['id' => Uuid::randomHex(), 'name' => 'test', 'taxRate' => 15],
            'releaseDate' => new \DateTime('2020-07-23 12:21:59.894+00:00'),
            'customFields' => [
                'testField' => 'test',
            ],
        ];

        $this->enableElasticsearch();
        $productRepository->create([$a, $b, $c], Context::createDefaultContext());
        $this->indexElasticSearch();

        $criteria = new Criteria([$c['id'], $a['id'], $b['id']]);
        $criteria->addSorting(new FieldSorting('releaseDate'));

        /** @var IdSearchResult $result */
        $result = $productRepository->searchIds($criteria, Context::createDefaultContext());

        $expected = [$a['id'], $b['id'], $c['id']];
        static::assertEquals($expected, $result->getIds());

        $expected = array_reverse($expected);
        $criteria = new Criteria([$c['id'], $a['id'], $b['id']]);
        $criteria->addSorting(new FieldSorting('releaseDate', FieldSorting::DESCENDING));

        /** @var IdSearchResult $result */
        $result = $productRepository->searchIds($criteria, Context::createDefaultContext());

        static::assertEquals($expected, $result->getIds());

        $criteria = new Criteria([$c['id'], $a['id'], $b['id']]);
        $criteria->addSorting(new FieldSorting('createdAt'));

        /** @var IdSearchResult $result */
        $result = $productRepository->searchIds($criteria, Context::createDefaultContext());

        $expected = [$a['id'], $b['id'], $c['id']];
        static::assertEquals($expected, $result->getIds());

        $expected = array_reverse($expected);
        $criteria = new Criteria([$c['id'], $a['id'], $b['id']]);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var IdSearchResult $result */
        $result = $productRepository->searchIds($criteria, Context::createDefaultContext());

        static::assertEquals($expected, $result->getIds());
    }

    protected function getDiContainer(): ContainerInterface
    {
        return $this->getContainer();
    }
}
