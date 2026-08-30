<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Enum\ProductKind;
use App\Domain\TranslatedString;
use App\Entity\Product;
use App\Entity\ProductCategory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Shop catalogue: supplements, apparel and accessories.
 */
final class ShopFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $categories = [
            'protein' => [
                'name' => TranslatedString::of('Protein', 'Proteīns', 'Протеин'),
                'kind' => ProductKind::SUPPLEMENT,
                'position' => 0,
                'products' => [
                    ['whey-vanilla', 'SPK-WHEY-VAN', 2990, 120,
                        ['Whey protein, vanilla, 1 kg', 'Sūkalu proteīns, vaniļa, 1 kg', 'Сывороточный протеин, ваниль, 1 кг'],
                        ['24 g of protein per serving, mixes without lumps in a shaker.', '24 g olbaltumvielu porcijā, šeikerī izšķīst bez kunkuļiem.', '24 г белка на порцию, размешивается в шейкере без комков.']],
                    ['whey-chocolate', 'SPK-WHEY-CHO', 2990, 96,
                        ['Whey protein, chocolate, 1 kg', 'Sūkalu proteīns, šokolāde, 1 kg', 'Сывороточный протеин, шоколад, 1 кг'],
                        ['The same blend in chocolate, our best seller since opening.', 'Tas pats maisījums šokolādes garšā, mūsu pirktākais produkts kopš atvēršanas.', 'Та же смесь со вкусом шоколада, наш бестселлер с открытия.']],
                ],
            ],
            'creatine' => [
                'name' => TranslatedString::of('Creatine', 'Kreatīns', 'Креатин'),
                'kind' => ProductKind::SUPPLEMENT,
                'position' => 1,
                'products' => [
                    ['creatine-monohydrate', 'SPK-CREA-300', 1690, 200,
                        ['Creatine monohydrate, 300 g', 'Kreatīna monohidrāts, 300 g', 'Креатин моногидрат, 300 г'],
                        ['Unflavoured micronised monohydrate, 5 g per day, no loading phase needed.', 'Mikronizēts monohidrāts bez garšas, 5 g dienā, bez piesātinājuma fāzes.', 'Микронизированный моногидрат без вкуса, 5 г в день, без фазы загрузки.']],
                ],
            ],
            'apparel' => [
                'name' => TranslatedString::of('Apparel', 'Apģērbs', 'Одежда'),
                'kind' => ProductKind::APPAREL,
                'position' => 2,
                'products' => [
                    ['training-tee', 'SPK-TEE-BLK', 2200, 60,
                        ['SPĒKS training tee', 'SPĒKS treniņu krekls', 'Футболка SPĒKS для тренировок'],
                        ['Breathable cotton blend with a printed logo on the chest.', 'Elpojošs kokvilnas maisījums ar apdrukātu logo uz krūtīm.', 'Дышащая хлопковая смесь с печатным логотипом на груди.']],
                    ['training-hoodie', 'SPK-HOD-GRY', 4500, 35,
                        ['SPĒKS hoodie', 'SPĒKS džemperis', 'Худи SPĒKS'],
                        ['Heavyweight hoodie for the walk between the changing room and the platform.', 'Blīvs džemperis ceļam no ģērbtuves līdz platformai.', 'Плотное худи для дороги от раздевалки до помоста.']],
                    ['lifting-shorts', 'SPK-SHT-BLK', 2800, 48,
                        ['Lifting shorts', 'Treniņu šorti', 'Шорты для тренировок'],
                        ['Cut to allow a full-depth squat without riding up.', 'Piegriezums ļauj pilnu pietupienu bez saraušanās.', 'Крой позволяет присесть в полную глубину без задирания.']],
                ],
            ],
            'accessories' => [
                'name' => TranslatedString::of('Accessories', 'Aksesuāri', 'Аксессуары'),
                'kind' => ProductKind::ACCESSORY,
                'position' => 3,
                'products' => [
                    ['lifting-belt', 'SPK-BLT-10', 5900, 25,
                        ['Leather lifting belt, 10 mm', 'Ādas jostas, 10 mm', 'Кожаный пояс, 10 мм'],
                        ['Single-prong leather belt, IPF-legal width, sizes S to XL.', 'Ādas josta ar vienu tapu, IPF atļauts platums, izmēri no S līdz XL.', 'Кожаный пояс с одним язычком, ширина по правилам IPF, размеры от S до XL.']],
                    ['wrist-wraps', 'SPK-WRP-60', 1900, 80,
                        ['Wrist wraps, 60 cm', 'Plaukstu locītavu saites, 60 cm', 'Бинты на запястья, 60 см'],
                        ['Stiff wraps for heavy pressing, thumb loop included.', 'Stingras saites smagai spiešanai, ar īkšķa cilpu.', 'Жёсткие бинты для тяжёлых жимов, с петлёй для большого пальца.']],
                    ['shaker', 'SPK-SHK-700', 900, 150,
                        ['Shaker, 700 ml', 'Šeikeris, 700 ml', 'Шейкер, 700 мл'],
                        ['Leak-proof lid and a mixing ball, dishwasher safe.', 'Necaurlaidīgs vāciņš un maisīšanas bumbiņa, drīkst mazgāt trauku mazgājamā mašīnā.', 'Герметичная крышка и шарик-миксер, можно мыть в посудомоечной машине.']],
                ],
            ],
        ];

        foreach ($categories as $slug => $data) {
            $category = (new ProductCategory())
                ->setSlug($slug)
                ->setName($data['name'])
                ->setKind($data['kind'])
                ->setPosition($data['position']);

            $manager->persist($category);

            foreach ($data['products'] as [$productSlug, $sku, $priceCents, $stock, $name, $description]) {
                $manager->persist(
                    (new Product())
                        ->setSlug($productSlug)
                        ->setSku($sku)
                        ->setName(TranslatedString::of(...$name))
                        ->setDescription(TranslatedString::of(...$description))
                        ->setCategory($category)
                        ->setPriceCents($priceCents)
                        ->setStock($stock)
                );
            }
        }

        $manager->flush();
    }
}
