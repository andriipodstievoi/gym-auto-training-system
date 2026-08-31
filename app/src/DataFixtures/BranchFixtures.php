<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Enum\EquipmentType;
use App\Domain\Enum\ZoneKind;
use App\Domain\TranslatedString;
use App\Entity\Branch;
use App\Entity\Equipment;
use App\Entity\FloorZone;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Three Riga branches, each with its floor zones and equipment.
 *
 * Addresses and coordinates are plausible Riga locations chosen so the Leaflet
 * map has a realistic spread across the city; swap them for the real ones when
 * they are known.
 *
 * Every branch also gets the rooms in {@see SHARED_AMENITIES}: changing rooms,
 * a reception, and a lounge and spa on the upper storey. They are real zones
 * rather than shapes drawn into the plan, so they are clickable, translated
 * and editable like any training floor.
 */
final class BranchFixtures extends Fixture
{
    public const string REFERENCE_CENTRS = 'branch-centrs';
    public const string REFERENCE_PURVCIEMS = 'branch-purvciems';
    public const string REFERENCE_AGENSKALNS = 'branch-agenskalns';

    /**
     * Monday to Sunday, keyed by ISO weekday number.
     *
     * @var array<int, array{open: string, close: string}>
     */
    private const array WEEKDAY_HOURS = [
        1 => ['open' => '06:00', 'close' => '23:00'],
        2 => ['open' => '06:00', 'close' => '23:00'],
        3 => ['open' => '06:00', 'close' => '23:00'],
        4 => ['open' => '06:00', 'close' => '23:00'],
        5 => ['open' => '06:00', 'close' => '22:00'],
        6 => ['open' => '08:00', 'close' => '21:00'],
        7 => ['open' => '09:00', 'close' => '20:00'],
    ];

    /**
     * The rooms every branch has, whatever its training floor looks like.
     * Contents are {@see EquipmentType::FIXTURE} throughout - a locker is not
     * something anybody trains on.
     *
     * Shaped as [svgId, floor, en, lv, ru, [[itemEn, itemLv, itemRu, qty], ...]].
     *
     * @var list<array{string, int, string, string, string, list<array{string, string, string, int}>}>
     */
    private const array SHARED_AMENITIES = [
        ['changing-men', 0, "Men's changing room", 'Vīriešu ģērbtuve', 'Мужская раздевалка', [
            ['Lockers', 'Skapīši', 'Шкафчики', 24],
            ['Showers', 'Dušas', 'Душевые', 8],
            ['Benches', 'Soliņi', 'Скамьи', 6],
        ]],
        ['changing-women', 0, "Women's changing room", 'Sieviešu ģērbtuve', 'Женская раздевалка', [
            ['Lockers', 'Skapīši', 'Шкафчики', 24],
            ['Showers', 'Dušas', 'Душевые', 8],
            ['Benches', 'Soliņi', 'Скамьи', 6],
        ]],
        ['reception', 0, 'Reception', 'Reģistratūra', 'Ресепшн', [
            ['Reception desk', 'Reģistratūras lete', 'Стойка ресепшн', 1],
            ['Shop counter', 'Veikala lete', 'Витрина магазина', 1],
            ['Water station', 'Ūdens punkts', 'Питьевая станция', 2],
        ]],
        ['lounge', 1, 'Lounge', 'Atpūtas zona', 'Лаундж', [
            ['Lounge seating', 'Atpūtas sēdvietas', 'Мягкие кресла', 12],
            ['Coffee bar', 'Kafijas bārs', 'Кофе-бар', 1],
            ['Work tables', 'Darba galdi', 'Рабочие столы', 4],
        ]],
        ['spa', 1, 'Spa', 'SPA zona', 'СПА-зона', [
            ['Finnish sauna', 'Somu pirts', 'Финская сауна', 1],
            ['Steam room', 'Tvaika pirts', 'Хамам', 1],
            ['Plunge pool', 'Atvēsināšanās baseins', 'Купель', 1],
            ['Relaxation loungers', 'Atpūtas krēsli', 'Шезлонги', 6],
        ]],
    ];

    public function load(ObjectManager $manager): void
    {
        $branches = [
            [
                'reference' => self::REFERENCE_CENTRS,
                'slug' => 'centrs',
                'name' => 'SPĒKS Centrs',
                'address' => 'Brīvības iela 55',
                'postalCode' => 'LV-1010',
                'lat' => 56.9573,
                'lng' => 24.1183,
                'phone' => '+371 6700 1010',
                'email' => 'centrs@speks.lv',
                'description' => TranslatedString::of(
                    'Our flagship floor in the centre: a full powerlifting area, two lifting platforms and the widest machine selection in the city.',
                    'Mūsu galvenā zāle centrā: pilna spēka trīscīņas zona, divas pacelšanas platformas un plašākā trenažieru izvēle pilsētā.',
                    'Наш главный зал в центре: полноценная пауэрлифтерская зона, два помоста и самый широкий выбор тренажёров в городе.',
                ),
                'zones' => [
                    ['free-weights', 'Free weights', 'Brīvie svari', 'Свободные веса', [
                        ['Power rack', 'Jaudas rāmis', 'Силовая рама', EquipmentType::BARBELL, 6],
                        ['Competition bench', 'Sacensību sols', 'Соревновательная скамья', EquipmentType::BARBELL, 4],
                        ['Dumbbell rack 2-50 kg', 'Hanteļu statīvs 2-50 kg', 'Стойка гантелей 2-50 кг', EquipmentType::DUMBBELL, 2],
                    ]],
                    ['machines', 'Machines', 'Trenažieri', 'Тренажёры', [
                        ['Leg press', 'Kāju prese', 'Жим ногами', EquipmentType::MACHINE, 2],
                        ['Lat pulldown', 'Augšējais bloks', 'Верхняя тяга', EquipmentType::CABLE, 3],
                        ['Cable crossover', 'Bloku krosovers', 'Кроссовер', EquipmentType::CABLE, 2],
                    ]],
                    ['cardio', 'Cardio', 'Kardio', 'Кардио', [
                        ['Treadmill', 'Skrejceliņš', 'Беговая дорожка', EquipmentType::CARDIO, 10],
                        ['Rowing machine', 'Airēšanas trenažieris', 'Гребной тренажёр', EquipmentType::CARDIO, 4],
                    ]],
                    ['functional', 'Functional', 'Funkcionālā zona', 'Функциональная зона', [
                        ['Kettlebells 8-40 kg', 'Svaru bumbas 8-40 kg', 'Гири 8-40 кг', EquipmentType::KETTLEBELL, 2],
                        ['Pull-up rig', 'Pievilkšanās rāmis', 'Турниковая рама', EquipmentType::BODYWEIGHT, 1],
                    ]],
                ],
            ],
            [
                'reference' => self::REFERENCE_PURVCIEMS,
                'slug' => 'purvciems',
                'name' => 'SPĒKS Purvciems',
                'address' => 'Dzelzavas iela 74',
                'postalCode' => 'LV-1082',
                'lat' => 56.9625,
                'lng' => 24.1900,
                'phone' => '+371 6700 1082',
                'email' => 'purvciems@speks.lv',
                'description' => TranslatedString::of(
                    'A neighbourhood gym built around machines and conditioning, with the quietest mornings of any branch.',
                    'Rajona zāle, veidota ap trenažieriem un kondīciju treniņiem, ar klusākajiem rītiem no visām filiālēm.',
                    'Районный зал вокруг тренажёров и кондиционных тренировок, с самыми спокойными утрами среди филиалов.',
                ),
                'zones' => [
                    ['machines', 'Machines', 'Trenažieri', 'Тренажёры', [
                        ['Chest press', 'Krūšu prese', 'Жим от груди', EquipmentType::MACHINE, 2],
                        ['Seated row', 'Sēdus airēšana', 'Тяга сидя', EquipmentType::CABLE, 2],
                        ['Leg curl', 'Kāju locīšana', 'Сгибание ног', EquipmentType::MACHINE, 2],
                    ]],
                    ['free-weights', 'Free weights', 'Brīvie svari', 'Свободные веса', [
                        ['Squat rack', 'Pietupienu rāmis', 'Стойка для приседа', EquipmentType::BARBELL, 3],
                        ['Dumbbell rack 2-40 kg', 'Hanteļu statīvs 2-40 kg', 'Стойка гантелей 2-40 кг', EquipmentType::DUMBBELL, 1],
                    ]],
                    ['cardio', 'Cardio', 'Kardio', 'Кардио', [
                        ['Air bike', 'Gaisa velosipēds', 'Аэробайк', EquipmentType::CARDIO, 4],
                        ['Cross trainer', 'Elipsveida trenažieris', 'Эллиптический тренажёр', EquipmentType::CARDIO, 6],
                    ]],
                ],
            ],
            [
                'reference' => self::REFERENCE_AGENSKALNS,
                'slug' => 'agenskalns',
                'name' => 'SPĒKS Āgenskalns',
                'address' => 'Kalnciema iela 28',
                'postalCode' => 'LV-1046',
                'lat' => 56.9385,
                'lng' => 24.0742,
                'phone' => '+371 6700 1046',
                'email' => 'agenskalns@speks.lv',
                'description' => TranslatedString::of(
                    'Our smallest floor, on the left bank, with a dedicated studio for classes and one-to-one coaching.',
                    'Mūsu mazākā zāle Pārdaugavā ar atsevišķu studiju nodarbībām un individuālajam darbam.',
                    'Наш самый компактный зал на левом берегу с отдельной студией для занятий и персональных тренировок.',
                ),
                'zones' => [
                    ['free-weights', 'Free weights', 'Brīvie svari', 'Свободные веса', [
                        ['Half rack', 'Pusrāmis', 'Полурама', EquipmentType::BARBELL, 2],
                        ['Adjustable bench', 'Regulējams sols', 'Регулируемая скамья', EquipmentType::DUMBBELL, 4],
                    ]],
                    ['studio', 'Studio', 'Studija', 'Студия', [
                        ['Resistance bands', 'Pretestības gumijas', 'Резиновые петли', EquipmentType::BAND, 20],
                        ['Yoga mats', 'Jogas paklāji', 'Коврики для йоги', EquipmentType::BODYWEIGHT, 15],
                    ]],
                    ['cardio', 'Cardio', 'Kardio', 'Кардио', [
                        ['Treadmill', 'Skrejceliņš', 'Беговая дорожка', EquipmentType::CARDIO, 4],
                    ]],
                ],
            ],
        ];

        foreach ($branches as $data) {
            $branch = (new Branch())
                ->setSlug($data['slug'])
                ->setName($data['name'])
                ->setDescription($data['description'])
                ->setAddressLine($data['address'])
                ->setPostalCode($data['postalCode'])
                ->setLatitude($data['lat'])
                ->setLongitude($data['lng'])
                ->setPhone($data['phone'])
                ->setEmail($data['email'])
                ->setOpeningHours(self::WEEKDAY_HOURS);

            foreach ($data['zones'] as $position => [$svgId, $en, $lv, $ru, $items]) {
                $zone = (new FloorZone())
                    ->setSvgId($svgId)
                    ->setName(TranslatedString::of($en, $lv, $ru))
                    ->setKind(ZoneKind::TRAINING)
                    ->setFloor(0)
                    ->setPosition($position);

                foreach ($items as [$itemEn, $itemLv, $itemRu, $type, $quantity]) {
                    $zone->addEquipment(
                        (new Equipment())
                            ->setName(TranslatedString::of($itemEn, $itemLv, $itemRu))
                            ->setType($type)
                            ->setQuantity($quantity)
                    );
                }

                $branch->addFloorZone($zone);
            }

            $position = count($data['zones']);

            foreach (self::SHARED_AMENITIES as [$svgId, $floor, $en, $lv, $ru, $items]) {
                $zone = (new FloorZone())
                    ->setSvgId($svgId)
                    ->setName(TranslatedString::of($en, $lv, $ru))
                    ->setKind(ZoneKind::AMENITY)
                    ->setFloor($floor)
                    ->setPosition($position++);

                foreach ($items as [$itemEn, $itemLv, $itemRu, $quantity]) {
                    $zone->addEquipment(
                        (new Equipment())
                            ->setName(TranslatedString::of($itemEn, $itemLv, $itemRu))
                            ->setType(EquipmentType::FIXTURE)
                            ->setQuantity($quantity)
                    );
                }

                $branch->addFloorZone($zone);
            }

            $manager->persist($branch);
            $this->addReference($data['reference'], $branch);
        }

        $manager->flush();
    }
}
