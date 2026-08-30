<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Enum\TrainerSpeciality;
use App\Domain\TranslatedString;
use App\Entity\Branch;
use App\Entity\Trainer;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Coaching staff spread across the three branches.
 *
 * Languages matter here: a Riga member filtering for a Russian-speaking or
 * Latvian-speaking coach is a real use case, and M5 will expose that filter.
 */
final class TrainerFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $trainers = [
            [
                'slug' => 'ilze-berzina',
                'fullName' => 'Ilze Bērziņa',
                'branch' => BranchFixtures::REFERENCE_CENTRS,
                'specialities' => [TrainerSpeciality::STRENGTH, TrainerSpeciality::POWERLIFTING],
                'languages' => ['lv', 'en'],
                'hourlyRateCents' => 4000,
                'bio' => TranslatedString::of(
                    'Competitive powerlifter coaching the squat, bench and deadlift since 2016. Works best with lifters who want a number on the bar by spring.',
                    'Spēka trīscīņas sportiste, kas kopš 2016. gada māca pietupienu, spiešanu un vilkšanu. Vislabāk strādā ar tiem, kuri grib konkrētu rezultātu līdz pavasarim.',
                    'Пауэрлифтёрша, тренирует присед, жим и тягу с 2016 года. Лучше всего работает с теми, кому нужен конкретный результат к весне.',
                ),
            ],
            [
                'slug' => 'artjoms-kuznecovs',
                'fullName' => 'Artjoms Kuzņecovs',
                'branch' => BranchFixtures::REFERENCE_CENTRS,
                'specialities' => [TrainerSpeciality::HYPERTROPHY, TrainerSpeciality::CONDITIONING],
                'languages' => ['ru', 'lv', 'en'],
                'hourlyRateCents' => 3500,
                'bio' => TranslatedString::of(
                    'Bodybuilding background, ten years on the floor. Specialises in members returning to training after a long break.',
                    'Kultūrisma pieredze un desmit gadi zālē. Specializējas tajos, kas atgriežas treniņos pēc ilgāka pārtraukuma.',
                    'Бодибилдерский бэкграунд и десять лет в зале. Специализируется на тех, кто возвращается к тренировкам после долгого перерыва.',
                ),
            ],
            [
                'slug' => 'marta-ozola',
                'fullName' => 'Marta Ozola',
                'branch' => BranchFixtures::REFERENCE_AGENSKALNS,
                'specialities' => [TrainerSpeciality::REHAB, TrainerSpeciality::WEIGHT_LOSS],
                'languages' => ['lv', 'ru'],
                'hourlyRateCents' => 3800,
                'bio' => TranslatedString::of(
                    'Physiotherapy degree and a rehab-first approach. The person to see if a shoulder or lower back is limiting what you can train.',
                    'Fizioterapeita izglītība un rehabilitācijas pieeja. Pie viņas jāvēršas, ja plecs vai muguras lejasdaļa ierobežo treniņus.',
                    'Образование физиотерапевта и реабилитационный подход. К ней стоит идти, если плечо или поясница ограничивают тренировки.',
                ),
            ],
            [
                'slug' => 'deniss-petrovs',
                'fullName' => 'Deniss Petrovs',
                'branch' => BranchFixtures::REFERENCE_PURVCIEMS,
                'specialities' => [TrainerSpeciality::CONDITIONING, TrainerSpeciality::WEIGHT_LOSS],
                'languages' => ['ru', 'en'],
                'hourlyRateCents' => 3200,
                'bio' => TranslatedString::of(
                    'Conditioning coach with a background in team sport. Builds programmes for people whose goal is stamina rather than a heavier bar.',
                    'Kondīcijas treneris ar komandu sporta pieredzi. Veido programmas tiem, kuru mērķis ir izturība, nevis smagāka stienis.',
                    'Тренер по кондиции с опытом в командном спорте. Строит программы для тех, кому нужна выносливость, а не более тяжёлая штанга.',
                ),
            ],
        ];

        foreach ($trainers as $data) {
            /** @var Branch $branch */
            $branch = $this->getReference($data['branch'], Branch::class);

            $manager->persist(
                (new Trainer())
                    ->setSlug($data['slug'])
                    ->setFullName($data['fullName'])
                    ->setBio($data['bio'])
                    ->setBranch($branch)
                    ->setLanguages($data['languages'])
                    ->setHourlyRateCents($data['hourlyRateCents'])
                    ->setSpecialities(array_map(
                        static fn (TrainerSpeciality $speciality): string => $speciality->value,
                        $data['specialities'],
                    ))
            );
        }

        $manager->flush();
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        return [BranchFixtures::class];
    }
}
