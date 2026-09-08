<?php

declare(strict_types=1);

namespace OCA\Memories\Cron;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Notification\Notifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Once a week, tell each user how many photos they took during this calendar week in
 * previous years and link to the "On this day" view. Users can switch it off in the
 * Memories settings ("weeklyRecap" preference).
 */
final class WeeklyRecapJob extends TimedJob
{
    public const MIN_PHOTOS = 10;
    public const MAX_YEARS = 25;

    public function __construct(
        ITimeFactory $time,
        private IDBConnection $db,
        private IUserManager $userManager,
        private IUserConfig $userConfig,
        private IManager $notifications,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(7 * 24 * 3600);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /** @return int number of photos in the recap (0 = nothing sent) */
    public function notifyUser(string $uid, bool $force = false): int
    {
        if (!$force && 'false' === $this->userConfig->getValueString($uid, Application::APPNAME, 'weeklyRecap', 'true')) {
            return 0;
        }
        $years = $this->photosThisWeekInPastYears($uid);
        $count = array_sum($years);
        if (!$force && $count < self::MIN_PHOTOS) {
            return 0;
        }
        if (0 === $count) {
            return 0;
        }
        // one notification per week and user: drop an earlier one for the same week first
        $previous = $this->notifications->createNotification();
        $previous->setApp(Application::APPNAME)->setUser($uid)->setObject('weekly-recap', date('o-W'));
        $this->notifications->markProcessed($previous);

        $notification = $this->notifications->createNotification();
        $notification->setApp(Application::APPNAME)
            ->setUser($uid)
            ->setObject('weekly-recap', date('o-W'))
            ->setDateTime(new \DateTime())
            ->setSubject(Notifier::SUBJECT_WEEKLY_RECAP, ['years' => $years, 'count' => $count])
        ;
        $this->notifications->notify($notification);

        return $count;
    }

    #[\Override]
    protected function run(mixed $argument): void
    {
        $this->userManager->callForSeenUsers(function ($user): void {
            try {
                $this->notifyUser($user->getUID());
            } catch (\Throwable $e) {
                $this->logger->warning('Weekly recap failed for '.$user->getUID(), ['exception' => $e]);
            }
        });
    }

    /**
     * @return array<int, int> years ago => number of photos taken during this week of that year
     */
    private function photosThisWeekInPastYears(string $uid): array
    {
        $storage = $this->db->getQueryBuilder();
        $storage->select('numeric_id')->from('storages')->where($storage->expr()->eq('id', $storage->createNamedParameter('home::'.$uid)));
        $storageId = $storage->executeQuery()->fetchOne();
        if (false === $storageId) {
            return [];
        }

        $weekStart = (new \DateTimeImmutable('today'))->modify('monday this week');
        $result = [];
        for ($yearsAgo = 1; $yearsAgo <= self::MAX_YEARS; ++$yearsAgo) {
            $start = $weekStart->modify("-{$yearsAgo} years");
            $end = $start->modify('+6 days');
            // dayid = days since the epoch, as stored by Memories
            $dayStart = (int) floor($start->getTimestamp() / 86400);
            $dayEnd = (int) floor($end->getTimestamp() / 86400);

            $query = $this->db->getQueryBuilder();
            $query->select($query->func()->count('m.fileid'))
                ->from('memories', 'm')
                ->innerJoin('m', 'filecache', 'f', $query->expr()->eq('f.fileid', 'm.fileid'))
                ->where(\OCA\Memories\Util::timelineScope($query, $uid, 'f'))
                ->andWhere($query->expr()->gte('m.dayid', $query->createNamedParameter($dayStart, IQueryBuilder::PARAM_INT)))
                ->andWhere($query->expr()->lte('m.dayid', $query->createNamedParameter($dayEnd, IQueryBuilder::PARAM_INT)))
            ;
            $n = (int) $query->executeQuery()->fetchOne();
            if ($n > 0) {
                $result[$yearsAgo] = $n;
            }
        }

        return $result;
    }
}
