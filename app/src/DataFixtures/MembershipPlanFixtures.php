<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Enum\BillingInterval;
use App\Domain\TranslatedString;
use App\Entity\MembershipPlan;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Three membership tiers, priced in EUR cents.
 */
final class MembershipPlanFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $plans = [
            [
                'slug' => 'single-branch',
                'name' => TranslatedString::of('Single branch', 'Viena filiāle', 'Один филиал'),
                'description' => TranslatedString::of(
                    'Full access to the branch of your choice, any time it is open.',
                    'Pilna piekļuve vienai izvēlētai filiālei visā tās darba laikā.',
                    'Полный доступ к одному выбранному филиалу в часы его работы.',
                ),
                'priceCents' => 3490,
                'interval' => BillingInterval::MONTHLY,
                'allBranches' => false,
                'position' => 0,
                'features' => [
                    ['en' => 'One branch, unlimited visits', 'lv' => 'Viena filiāle, neierobežoti apmeklējumi', 'ru' => 'Один филиал, безлимитные посещения'],
                    ['en' => 'Free personal training plan', 'lv' => 'Bezmaksas personīgais treniņu plāns', 'ru' => 'Бесплатный персональный план тренировок'],
                    ['en' => 'Locker and shower access', 'lv' => 'Skapītis un dušas', 'ru' => 'Шкафчик и душевые'],
                ],
            ],
            [
                'slug' => 'all-branches',
                'name' => TranslatedString::of('All branches', 'Visas filiāles', 'Все филиалы'),
                'description' => TranslatedString::of(
                    'Train at every SPĒKS location in Riga, plus one coaching session each month.',
                    'Trenējies visās SPĒKS filiālēs Rīgā, plus viena trenera nodarbība mēnesī.',
                    'Тренируйся во всех залах SPĒKS в Риге, плюс одна тренировка с тренером в месяц.',
                ),
                'priceCents' => 4490,
                'interval' => BillingInterval::MONTHLY,
                'allBranches' => true,
                'position' => 1,
                'features' => [
                    ['en' => 'Every branch in Riga', 'lv' => 'Visas filiāles Rīgā', 'ru' => 'Все филиалы в Риге'],
                    ['en' => 'One coaching session per month', 'lv' => 'Viena trenera nodarbība mēnesī', 'ru' => 'Одна тренировка с тренером в месяц'],
                    ['en' => 'Plan updates every mesocycle', 'lv' => 'Plāna atjaunošana katrā mezociklā', 'ru' => 'Обновление плана каждый мезоцикл'],
                    ['en' => 'Bring a guest twice a month', 'lv' => 'Viesis divas reizes mēnesī', 'ru' => 'Гость дважды в месяц'],
                ],
            ],
            [
                'slug' => 'annual',
                'name' => TranslatedString::of('Annual', 'Gada abonements', 'Годовой'),
                'description' => TranslatedString::of(
                    'Twelve months of all-branch access, paid once - two months cheaper than paying monthly.',
                    'Divpadsmit mēneši piekļuves visām filiālēm ar vienu maksājumu - par diviem mēnešiem lētāk nekā maksājot ik mēnesi.',
                    'Двенадцать месяцев доступа во все залы одним платежом - на два месяца дешевле помесячной оплаты.',
                ),
                'priceCents' => 44900,
                'interval' => BillingInterval::YEARLY,
                'allBranches' => true,
                'position' => 2,
                'features' => [
                    ['en' => 'Everything in All branches', 'lv' => 'Viss no Visām filiālēm', 'ru' => 'Всё из тарифа Все филиалы'],
                    ['en' => 'Two months free versus monthly billing', 'lv' => 'Divi mēneši bez maksas pret ikmēneša maksājumu', 'ru' => 'Два месяца бесплатно по сравнению с помесячной оплатой'],
                    ['en' => 'Priority booking with trainers', 'lv' => 'Prioritāra pieteikšanās pie treneriem', 'ru' => 'Приоритетная запись к тренерам'],
                    ['en' => '10% off everything in the shop', 'lv' => '10% atlaide visam veikalā', 'ru' => 'Скидка 10% на всё в магазине'],
                ],
            ],
        ];

        foreach ($plans as $data) {
            $manager->persist(
                (new MembershipPlan())
                    ->setSlug($data['slug'])
                    ->setName($data['name'])
                    ->setDescription($data['description'])
                    ->setPriceCents($data['priceCents'])
                    ->setBillingInterval($data['interval'])
                    ->setAllBranches($data['allBranches'])
                    ->setPosition($data['position'])
                    ->setFeatures($data['features'])
            );
        }

        $manager->flush();
    }
}
