<?php

declare(strict_types=1);

namespace OCA\Memories\Http;

use OCP\AppFramework\Http\Template\IMenuAction;

/**
 * "Download" entry of the public share header menu.
 *
 * Nextcloud 30+ renders SimpleMenuAction entries either as "copy direct link" (id directLink)
 * or as an external-app link with a confirmation dialog; only custom actions that render their
 * own HTML become a plain link, which is what a download button needs.
 */
final class DownloadMenuAction implements IMenuAction
{
    public function __construct(
        private string $label,
        private string $link,
    ) {}

    #[\Override]
    public function getId(): string
    {
        return 'download';
    }

    #[\Override]
    public function getLabel(): string
    {
        return $this->label;
    }

    public function getIcon(): string
    {
        return 'icon-download';
    }

    #[\Override]
    public function getLink(): string
    {
        return $this->link;
    }

    #[\Override]
    public function getPriority(): int
    {
        return 10;
    }

    #[\Override]
    public function render(): string
    {
        $href = htmlspecialchars($this->link, ENT_QUOTES);
        $label = htmlspecialchars($this->label, ENT_QUOTES);

        return '<a class="memories-public-download" href="'.$href.'" download rel="nofollow" role="menuitem"'
            .' style="display:flex;align-items:center;gap:12px;padding:8px 14px;min-height:44px;color:var(--color-main-text);text-decoration:none;white-space:nowrap">'
            .'<span class="icon icon-download" aria-hidden="true" style="width:20px;height:20px;background-size:20px"></span>'
            .'<span>'.$label.'</span></a>';
    }
}
