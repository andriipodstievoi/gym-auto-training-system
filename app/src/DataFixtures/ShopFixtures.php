<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Enum\ProductKind;
use App\Domain\TranslatedString;
use App\Entity\Product;
use App\Entity\ProductCategory;
use App\Entity\ProductVariant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Shop catalogue: supplements, apparel and accessories.
 *
 * Roughly half the catalogue carries variants and half does not, deliberately:
 * both paths through the cart, the pricing and the checkout have to work, and
 * a shaker genuinely has no sizes.
 */
final class ShopFixtures extends Fixture
{
    /**
     * Reference prefix, so later fixtures can pick a product by slug.
     */
    public const string REFERENCE_PREFIX = 'product-';

    /**
     * Sizes shared by everything that comes in sizes.
     *
     * @var list<array{string, string, string, string}>
     */
    private const array SIZES = [
        ['S', 'S', 'S', 'S'],
        ['M', 'M', 'M', 'M'],
        ['L', 'L', 'L', 'L'],
        ['XL', 'XL', 'XL', 'XL'],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::catalogue() as $slug => $data) {
            $category = (new ProductCategory())
                ->setSlug($slug)
                ->setName($data['name'])
                ->setKind($data['kind'])
                ->setPosition($data['position']);

            $manager->persist($category);

            foreach ($data['products'] as $row) {
                [$productSlug, $sku, $priceCents, $stock, $name, $description, $variants] = $row;

                $product = (new Product())
                    ->setSlug($productSlug)
                    ->setSku($sku)
                    ->setName(TranslatedString::of(...$name))
                    ->setDescription(TranslatedString::of(...$description))
                    ->setCategory($category)
                    ->setPriceCents($priceCents)
                    ->setStock($stock);

                foreach ($variants as $position => [$suffix, $label, $variantPriceCents, $variantStock]) {
                    // The constructor attaches it to the product; the cascade
                    // on the collection is what persists it.
                    (new ProductVariant($product))
                        ->setSku($sku.'-'.$suffix)
                        ->setLabel(TranslatedString::of(...$label))
                        ->setPriceCents($variantPriceCents)
                        ->setStock($variantStock)
                        ->setPosition($position);
                }

                $manager->persist($product);
                $this->addReference(self::REFERENCE_PREFIX.$productSlug, $product);
            }
        }

        $manager->flush();
    }

    /**
     * @return array<string, array{
     *     name: TranslatedString,
     *     kind: ProductKind,
     *     position: int,
     *     products: list<array{string, string, int, int, list<string>, list<string>, list<array{string, list<string>, int, int}>}>
     * }>
     */
    private static function catalogue(): array
    {
        return [
            'protein' => [
                'name' => TranslatedString::of('Protein', 'Proteīns', 'Протеин'),
                'kind' => ProductKind::SUPPLEMENT,
                'position' => 0,
                'products' => [
                    ['whey-vanilla', 'SPK-WHEY-VAN', 2990, 120,
                        ['Whey protein, vanilla, 1 kg', 'Sūkalu proteīns, vaniļa, 1 kg', 'Сывороточный протеин, ваниль, 1 кг'],
                        ['24 g of protein per serving, mixes without lumps in a shaker.', '24 g olbaltumvielu porcijā, šeikerī izšķīst bez kunkuļiem.', '24 г белка на порцию, размешивается в шейкере без комков.'],
                        [
                            ['VAN', ['Vanilla', 'Vaniļa', 'Ваниль'], 2990, 60],
                            ['VCN', ['Vanilla and cinnamon', 'Vaniļa un kanēlis', 'Ваниль с корицей'], 3190, 30],
                        ],
                    ],
                    ['whey-chocolate', 'SPK-WHEY-CHO', 2990, 96,
                        ['Whey protein, chocolate, 1 kg', 'Sūkalu proteīns, šokolāde, 1 kg', 'Сывороточный протеин, шоколад, 1 кг'],
                        ['The same blend in chocolate, our best seller since opening.', 'Tas pats maisījums šokolādes garšā, mūsu pirktākais produkts kopš atvēršanas.', 'Та же смесь со вкусом шоколада, наш бестселлер с открытия.'],
                        [
                            ['CHO', ['Chocolate', 'Šokolāde', 'Шоколад'], 2990, 50],
                            ['CHZ', ['Chocolate and hazelnut', 'Šokolāde un lazdu rieksti', 'Шоколад с фундуком'], 3190, 28],
                        ],
                    ],
                ],
            ],
            'creatine' => [
                'name' => TranslatedString::of('Creatine', 'Kreatīns', 'Креатин'),
                'kind' => ProductKind::SUPPLEMENT,
                'position' => 1,
                'products' => [
                    ['creatine-monohydrate', 'SPK-CREA-300', 1690, 200,
                        ['Creatine monohydrate, 300 g', 'Kreatīna monohidrāts, 300 g', 'Креатин моногидрат, 300 г'],
                        ['Unflavoured micronised monohydrate, 5 g per day, no loading phase needed.', 'Mikronizēts monohidrāts bez garšas, 5 g dienā, bez piesātinājuma fāzes.', 'Микронизированный моногидрат без вкуса, 5 г в день, без фазы загрузки.'],
                        [],
                    ],
                ],
            ],
            'apparel' => [
                'name' => TranslatedString::of('Apparel', 'Apģērbs', 'Одежда'),
                'kind' => ProductKind::APPAREL,
                'position' => 2,
                'products' => [
                    ['training-tee', 'SPK-TEE-BLK', 2200, 60,
                        ['SPĒKS training tee', 'SPĒKS treniņu krekls', 'Футболка SPĒKS для тренировок'],
                        ['Breathable cotton blend with a printed logo on the chest.', 'Elpojošs kokvilnas maisījums ar apdrukātu logo uz krūtīm.', 'Дышащая хлопковая смесь с печатным логотипом на груди.'],
                        self::sizeVariants(2200, [12, 20, 18, 10]),
                    ],
                    ['training-hoodie', 'SPK-HOD-GRY', 4500, 35,
                        ['SPĒKS hoodie', 'SPĒKS džemperis', 'Худи SPĒKS'],
                        ['Heavyweight hoodie for the walk between the changing room and the platform.', 'Blīvs džemperis ceļam no ģērbtuves līdz platformai.', 'Плотное худи для дороги от раздевалки до помоста.'],
                        self::sizeVariants(4500, [8, 12, 10, 5]),
                    ],
                    ['lifting-shorts', 'SPK-SHT-BLK', 2800, 48,
                        ['Lifting shorts', 'Treniņu šorti', 'Шорты для тренировок'],
                        ['Cut to allow a full-depth squat without riding up.', 'Piegriezums ļauj pilnu pietupienu bez saraušanās.', 'Крой позволяет присесть в полную глубину без задирания.'],
                        self::sizeVariants(2800, [10, 16, 14, 8]),
                    ],
                ],
            ],
            'accessories' => [
                'name' => TranslatedString::of('Accessories', 'Aksesuāri', 'Аксессуары'),
                'kind' => ProductKind::ACCESSORY,
                'position' => 3,
                'products' => [
                    ['lifting-belt', 'SPK-BLT-10', 5900, 25,
                        ['Leather lifting belt, 10 mm', 'Ādas jostas, 10 mm', 'Кожаный пояс, 10 мм'],
                        ['Single-prong leather belt, IPF-legal width, sizes S to XL.', 'Ādas josta ar vienu tapu, IPF atļauts platums, izmēri no S līdz XL.', 'Кожаный пояс с одним язычком, ширина по правилам IPF, размеры от S до XL.'],
                        self::sizeVariants(5900, [6, 8, 7, 4]),
                    ],
                    ['wrist-wraps', 'SPK-WRP-60', 1900, 80,
                        ['Wrist wraps, 60 cm', 'Plaukstu locītavu saites, 60 cm', 'Бинты на запястья, 60 см'],
                        ['Stiff wraps for heavy pressing, thumb loop included.', 'Stingras saites smagai spiešanai, ar īkšķa cilpu.', 'Жёсткие бинты для тяжёлых жимов, с петлёй для большого пальца.'],
                        [],
                    ],
                    ['shaker', 'SPK-SHK-700', 900, 150,
                        ['Shaker, 700 ml', 'Šeikeris, 700 ml', 'Шейкер, 700 мл'],
                        ['Leak-proof lid and a mixing ball, dishwasher safe.', 'Necaurlaidīgs vāciņš un maisīšanas bumbiņa, drīkst mazgāt trauku mazgājamā mašīnā.', 'Герметичная крышка и шарик-миксер, можно мыть в посудомоечной машине.'],
                        [],
                    ],
                ],
            ],
        ];
    }

    /**
     * S to XL at one price. Sizes cost the same here; the column is absolute
     * rather than a delta so that a future XXL can cost more without anybody
     * touching the others.
     *
     * @param array{int, int, int, int} $stock
     *
     * @return list<array{string, list<string>, int, int}>
     */
    private static function sizeVariants(int $priceCents, array $stock): array
    {
        $variants = [];

        foreach (self::SIZES as $index => [$suffix, $en, $lv, $ru]) {
            $variants[] = [$suffix, [$en, $lv, $ru], $priceCents, $stock[$index]];
        }

        return $variants;
    }
}
