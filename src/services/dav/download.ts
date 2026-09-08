import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';

import { translate as t } from '@services/l10n';
import { API } from '@services/API';
import * as nativex from '@native';

import type { IPhoto } from '@typings';

/**
 * Download files
 */
export async function downloadFiles(fileIds: number[]) {
  if (!fileIds.length) return;

  const res = await axios.post(API.DOWNLOAD_REQUEST(), { files: fileIds });
  if (res.status !== 200 || !res.data.handle) {
    showError(t('memories', 'Failed to download files'));
    return;
  }

  downloadWithHandle(res.data.handle);
}

/** Get URL to download one file (e.g. for video streaming) */
export function getDownloadLink(photo: IPhoto) {
  return API.STREAM_FILE(photo.fileid);
}

/**
 * Download files with a download handle
 * @param handle Download handle
 */
export function downloadWithHandle(handle: string) {
  return downloadFromUrl(API.DOWNLOAD_FILE(handle));
}

/**
 * Download files from a URL.
 * @param url URL to download from
 */
export function downloadFromUrl(url: string) {
  // Hand off to download manager (absolute URL)
  if (nativex.has()) return nativex.downloadFromUrl(url);

  // Fallback to browser download
  const link = document.createElement('a');
  link.href = url;
  link.download = '';
  link.click();
}

/**
 * Stitch photos into a short video (server side, ffmpeg); the file is saved next to the first photo.
 * Shows a success / error toast; resolves to the new file id or null.
 */
export async function createBurstVideo(fileIds: number[], fps = 3, music = 'auto'): Promise<number | null> {
  try {
    const res = await axios.post(API.BURST_VIDEO(), { fileids: fileIds, fps, music });
    showSuccess(
      t('memories', 'The video is being made from {n} photos; you will be notified when it is ready.', {
        n: fileIds.length,
      }),
    );
    return (res.data.job?.id as number) ?? null;
  } catch (error) {
    console.error(error);
    showError(t('memories', 'Could not create the video (is ffmpeg configured?)'));
    return null;
  }
}
