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

    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
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
            ->setLink($this->urlGenerator->linkToRouteAbsolute('memories.Page.thisday'))
            ->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APPNAME, 'app-dark.svg')))
        ;

        return $notification;
    }
}
