<?php

namespace Samody\PostmanGenerator\Tests\Fixtures;

trait CollectionHelpersTrait
{
    private function retrieveRoutes(array $route)
    {
        if (isset($route['item'])) {
            $sum = 0;

            foreach ($route['item'] as $item) {
                $sum += $this->retrieveRoutes($item);
            }

            return $sum;
        }

        return 1;
    }

    private function countCollectionItems(array $collectionItems)
    {
        $sum = 0;

        foreach ($collectionItems as $item) {
            $sum += $this->retrieveRoutes($item);
        }

        return $sum;
    }
}
