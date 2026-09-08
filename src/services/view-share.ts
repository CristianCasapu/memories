import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';

import { translate as t } from '@services/l10n';
import { API } from '@services/API';
import * as utils from '@services/utils';

import type { IDay, IPhoto } from '@typings';

/**
 * Every photo of the current view (same query the timeline uses), as light IPhoto objects.
 */
export async function getViewPhotos(): Promise<IPhoto[]> {
  const query = (_m.timeline?.getQuery?.() ?? {}) as Record<string, string>;
  const days = (await axios.get<IDay[]>(API.Q(API.DAYS(), query))).data;
  const dayIds = days.map((d) => d.dayid);
  const seen = new Set<number>();
  const photos: IPhoto[] = [];
  for (let i = 0; i < dayIds.length; i += 50) {
    const chunk = dayIds.slice(i, i + 50);
    const list = (await axios.get<IPhoto[]>(API.Q(API.DAY(chunk.join(',')), query))).data;
    for (const photo of list) {
      if (photo.fileid && !seen.has(photo.fileid)) {
        seen.add(photo.fileid);
        photos.push(photo);
      }
    }
  }
  return photos;
}

/** Share the link of the page (public shares, visitors): native share sheet or clipboard. */
export async function shareCurrentLink() {
  const url = window.location.href;
  try {
    if (navigator.share) {
      await navigator.share({ url, title: document.title });
      return;
    }
  } catch (error) {
    // user cancelled the share sheet
    return;
  }
  try {
    await navigator.clipboard.writeText(url);
    showSuccess(t('memories', 'Link copied to clipboard'));
  } catch {
    showError(t('memories', 'Could not copy the link: {url}', { url }));
  }
}

/** Numeric Recognize cluster id for the person in the route (name or id). */
export async function resolvePersonClusterId(name: string): Promise<number | null> {
  if (/^\d+$/.test(name)) return Number(name);
  const faces = (await axios.get(API.FACE_LIST('recognize'))).data as { cluster_id: number; name: string }[];
  return faces.find((f) => f.name === name)?.cluster_id ?? null;
}

/**
 * Share what the user is looking at:
 *  - visitors (public share): the link of the page
 *  - an album: the album share dialog
 *  - a named person: the person's album (created automatically, named after the person, kept up to date), then the share dialog
 *  - anything else (folder, tag, place, search, event, similar group, favorites, timeline …): a new album with the photos of the view
 */
export async function shareView(route: any, defaultName: string = '') {
  const routeName = String(route?.name ?? '');
  if (routeName.endsWith('-share')) {
    return shareCurrentLink();
  }

  if (routeName === _m.routes.Albums.name && route.params.name) {
    _m.modals.albumShare(route.params.user, route.params.name);
    return;
  }

  if (
    routeName === _m.routes.Recognize.name &&
    route.params.name &&
    route.params.name !== 'NULL' &&
    !String(route.params.name).includes('|')
  ) {
    const name = String(route.params.name);
    if (!/^\d+$/.test(name)) {
      try {
        const clusterId = await resolvePersonClusterId(name);
        if (clusterId === null) throw new Error('unknown person');
        const res = await axios.post(API.PERSON_ALBUM(clusterId), {});
        _m.router.push({
          name: _m.routes.Albums.name,
          params: { user: utils.uid as string, name: res.data.name },
          query: { share: '1' },
        });
        return;
      } catch (error) {
        console.error(error);
        showError(t('memories', 'Could not create the album of this person'));
        return;
      }
    }
  }

  _m.modals.shareAsAlbum(getViewPhotos, defaultName);
}
