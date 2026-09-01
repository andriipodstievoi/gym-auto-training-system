<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Entity\ProductCategory;
use App\Repository\ProductCategoryRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public catalogue.
 *
 * Everything here is anonymous-readable; only checkout asks who you are.
 */
final class ShopController extends AbstractController
{
    #[Route('/{_locale}/shop', name: 'shop_index', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET'])]
    public function index(ProductCategoryRepository $categories, ProductRepository $products): Response
    {
        $grouped = self::groupByCategory($products->findActiveInCategory());

        $sections = [];

        foreach ($categories->findOrdered() as $category) {
            $id = $category->getId();

            if (null === $id || [] === ($grouped[$id] ?? [])) {
                continue;
            }

            $sections[] = ['category' => $category, 'products' => $grouped[$id]];
        }

        return $this->render('shop/index.html.twig', [
            'sections' => $sections,
        ]);
    }

    #[Route('/{_locale}/shop/c/{slug}', name: 'shop_category', requirements: ['_locale' => 'en|lv|ru', 'slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function category(string $slug, ProductCategoryRepository $categories, ProductRepository $products): Response
    {
        $category = $categories->findOneBy(['slug' => $slug]);

        if (!$category instanceof ProductCategory) {
            throw $this->createNotFoundException(\sprintf('No shop category is called "%s".', $slug));
        }

        return $this->render('shop/category.html.twig', [
            'category' => $category,
            'products' => $products->findActiveInCategory($category),
        ]);
    }

    #[Route('/{_locale}/shop/p/{slug}', name: 'shop_product', requirements: ['_locale' => 'en|lv|ru', 'slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function product(string $slug, ProductRepository $products): Response
    {
        $product = $products->findOneActiveBySlug($slug);

        if (!$product instanceof Product) {
            // Inactive and unknown look identical from outside on purpose.
            throw $this->createNotFoundException(\sprintf('No product on sale is called "%s".', $slug));
        }

        return $this->render('shop/show.html.twig', [
            'product' => $product,
        ]);
    }

    /**
     * @param list<Product> $products
     *
     * @return array<int, list<Product>>
     */
    private static function groupByCategory(array $products): array
    {
        $grouped = [];

        foreach ($products as $product) {
            $categoryId = $product->getCategory()?->getId();

            if (null === $categoryId) {
                continue;
            }

            $grouped[$categoryId][] = $product;
        }

        return $grouped;
    }
}
