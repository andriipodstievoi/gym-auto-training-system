<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Booking\SlotFinder;
use App\Entity\Booking;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Trainer;
use App\Entity\TrainerAvailability;
use App\Entity\User;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * The coaching side of M5: who works when, one diary with something in it, and
 * a conversation already in progress.
 *
 * One coach is linked to a login (coach@speks.lv) so the coach area is
 * reachable in development at all - without it the only way in would be to
 * edit a row by hand. The other three have hours but no account, which is the
 * state a coach is actually in on their first day and the state the mailer has
 * to survive.
 *
 * Times are stored in UTC, like everything else, and written here as Riga wall
 * clock so the seeded week reads the way a coach would say it.
 */
final class BookingFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * Weekly hours per coach, in the gym's own timezone.
     *
     * @var array<string, list<array{int, string, string}>>
     */
    private const array WEEK = [
        TrainerFixtures::REFERENCE_ILZE => [
            [1, '09:00', '13:00'],
            [3, '09:00', '13:00'],
            [5, '15:00', '19:00'],
        ],
        TrainerFixtures::REFERENCE_ARTJOMS => [
            [1, '16:00', '20:00'],
            [2, '16:00', '20:00'],
            [4, '16:00', '20:00'],
            [6, '10:00', '14:00'],
        ],
        TrainerFixtures::REFERENCE_MARTA => [
            [2, '08:00', '12:00'],
            [4, '08:00', '12:00'],
        ],
        // Deniss deliberately has none: the profile of a coach with nothing
        // open has to say so, and something has to exercise that page.
    ];

    public function load(ObjectManager $manager): void
    {
        $zone = new DateTimeZone(SlotFinder::TIMEZONE);

        /** @var Trainer $coach */
        $coach = $this->getReference(TrainerFixtures::REFERENCE_ARTJOMS, Trainer::class);
        $coach->setUser($this->getReference(UserFixtures::REFERENCE_COACH, User::class));

        foreach (self::WEEK as $reference => $windows) {
            /** @var Trainer $trainer */
            $trainer = $this->getReference($reference, Trainer::class);

            foreach ($windows as [$weekday, $from, $to]) {
                $manager->persist(
                    (new TrainerAvailability($trainer))
                        ->setWeekday($weekday)
                        ->setStartTime(new DateTimeImmutable($from))
                        ->setEndTime(new DateTimeImmutable($to))
                );
            }
        }

        /** @var User $member */
        $member = $this->getReference(UserFixtures::REFERENCE_MEMBER, User::class);
        /** @var User $prospect */
        $prospect = $this->getReference(UserFixtures::REFERENCE_PROSPECT, User::class);

        // Two sessions the coach area has something to do with: one waiting on
        // an answer, one already agreed. Both are anchored to a fixed hour of
        // the coming week rather than to whenever the fixtures were loaded, so
        // reloading them twice in a day produces the same diary.
        $requested = self::nextWeekdayAt($zone, 2, 17);
        $confirmed = self::nextWeekdayAt($zone, 4, 18);

        $awaiting = new Booking($coach, $member, $requested, $requested->add(new DateInterval('PT1H')));
        $awaiting->setNotes('Left shoulder still catches on overhead press - want to work around it.');
        $manager->persist($awaiting);

        $agreed = new Booking($coach, $prospect, $confirmed, $confirmed->add(new DateInterval('PT1H')));
        $agreed->confirm();
        $manager->persist($agreed);

        // A short thread, with the coach's reply left unread so the badge in
        // the header has something to show on a fresh database.
        $conversation = new Conversation($coach, $member);
        $manager->persist($conversation);

        $opening = new Message(
            $conversation,
            $member,
            'Sveiki! Can we spend the first session on technique rather than loading?',
            new DateTimeImmutable('-2 days'),
        );
        $opening->markRead(new DateTimeImmutable('-2 days'));
        $manager->persist($opening);

        $manager->persist(new Message(
            $conversation,
            $this->getReference(UserFixtures::REFERENCE_COACH, User::class),
            'Of course. Bring flat shoes and we will film a few sets.',
            new DateTimeImmutable('-1 day'),
        ));

        $manager->flush();
    }

    /**
     * The next occurrence of an ISO weekday at a whole Riga hour, as UTC.
     */
    private static function nextWeekdayAt(DateTimeZone $zone, int $weekday, int $hour): DateTimeImmutable
    {
        $local = (new DateTimeImmutable('now', $zone))->setTime($hour, 0);

        while ((int) $local->format('N') !== $weekday || $local <= new DateTimeImmutable('now', $zone)) {
            $local = $local->add(new DateInterval('P1D'));
        }

        return $local->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        return [TrainerFixtures::class, UserFixtures::class];
    }
}
