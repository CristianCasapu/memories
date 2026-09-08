<?php

declare(strict_types=1);

namespace OCA\Memories\Notification;

use OCA\Memories\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the weekly "your memories from this week" notification.
 */
final class Notifier implements INotifier
{
    public const SUBJECT_WEEKLY_RECAP = 'weekly-recap';
    public const SUBJECT_VIDEO_READY = 'video-ready';
    public const SUBJECT_VIDEO_FAILED = 'video-failed';
    public const SUBJECT_VIDEO_MENTION = 'video-mention';

    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
        private \OCP\IUserManager $userManager,
    ) {}

    #[\Override]
    public function getID(): string
    {
        return Application::APPNAME;
    }

    #[\Override]
    public function getName(): string
    {
        return $this->l10nFactory->get(Application::APPNAME)->t('Memories');
    }

    #[\Override]
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if (Application::APPNAME === $notification->getApp() && \in_array($notification->getSubject(), [self::SUBJECT_VIDEO_READY, self::SUBJECT_VIDEO_FAILED, self::SUBJECT_VIDEO_MENTION], true)) {
            $l = $this->l10nFactory->get(Application::APPNAME, $languageCode);
            $p = $notification->getSubjectParameters();
            $name = (string) ($p['name'] ?? '');
            if (self::SUBJECT_VIDEO_MENTION === $notification->getSubject()) {
                $by = (string) ($p['by'] ?? '');
                $byName = $this->userManager->get($by)?->getDisplayName() ?? $by;
                $notification->setParsedSubject($l->t('%1$s tagged you in the clip "%2$s"', [$byName, $name]))
                    ->setParsedMessage($l->t('The clip was shared with you.'))
                    ->setLink($this->urlGenerator->linkToRouteAbsolute('memories.Page.clips'))
                ;
            } elseif (self::SUBJECT_VIDEO_READY === $notification->getSubject()) {
                $notification->setParsedSubject($l->t('Your video "%s" is ready', [$name]))
                    ->setParsedMessage($l->t('Saved in %s. Open Memories › Videos to watch it.', [(string) ($p['folder'] ?? '/')]))
                    ->setLink($this->urlGenerator->linkToRouteAbsolute('memories.Page.clips'))
                ;
            } else {
                $notification->setParsedSubject($l->t('Your video could not be made'))
                    ->setParsedMessage((string) ($p['error'] ?? ''))
                    ->setLink($this->urlGenerator->linkToRouteAbsolute('memories.Page.videos'))
                ;
            }
            $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APPNAME, 'app-dark.svg')));

            return $notification;
        }
        if (Application::APPNAME !== $notification->getApp() || self::SUBJECT_WEEKLY_RECAP !== $notification->getSubject()) {
            throw new UnknownNotificationException();
        }
        $l = $this->l10nFactory->get(Application::APPNAME, $languageCode);
        $params = $notification->getSubjectParameters();
        $years = (array) ($params['years'] ?? []);
        $count = (int) ($params['count'] ?? 0);

        $parts = [];
        foreach ($years as $yearsAgo => $photos) {
            $parts[] = $l->n('%n year ago', '%n years ago', (int) $yearsAgo).' ('.$l->n('%n photo', '%n photos', (int) $photos).')';
        }

        $notification->setParsedSubject($l->t('Your memories from this week'))
            ->setParsedMessage($l->t('%1$s photos were taken during this week in past years: %2$s', [(string) $count, implode(', ', $parts)]))
            ->setLink($this->urlGenerator->linkToRouteAbsolute('memories.Page.thisday').'?week=1')
            ->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APPNAME, 'app-dark.svg')))
        ;

        return $notification;
    }
}
