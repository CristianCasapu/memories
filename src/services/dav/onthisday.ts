import axios from '@nextcloud/axios';

import * as utils from '@services/utils';
import staticConfig from '@services/static-config';
import { API } from '@services/API';

import type { IDay, IPhoto } from '@typings';

/**
 * Get original onThisDay response.
 */
export async function getOnThisDayRaw(week = false) {
  const dayIds: number[] = [];
  const now = new Date();
  const nowUTC = new Date(now.getTime() - now.getTimezoneOffset() * 60000);

  // Offsets (in days) around today to look at in each past year.
  // "week": the whole calendar week (Monday to Sunday), as the weekly recap counts it.
  let offsets: number[] = [];
  if (week) {
    const dow = (now.getDay() + 6) % 7; // 0 = Monday
    for (let j = -dow; j <= 6 - dow; j++) offsets.push(j);
  } else {
    const dayRange = staticConfig.getSync('onthisday_day_range');
    for (let j = -dayRange; j <= dayRange; j++) offsets.push(j);
  }

  // Populate dayIds
  for (let i = 1; i <= 120; i++) {
    for (const j of offsets) {
      const d = new Date(nowUTC);
      d.setFullYear(d.getFullYear() - i);
      d.setDate(d.getDate() + j);
      const dayId = Math.floor(d.getTime() / 1000 / 86400);
      dayIds.push(dayId);
    }
  }

  const res = await axios.post<IPhoto[]>(API.DAYS(), { dayIds });

  res.data.forEach(utils.convertFlags);
  return res.data;
}

/**
 * Get the onThisDay data
 * Query for last 120 years; should be enough
 */
export async function getOnThisDayData(week = false): Promise<IDay[]> {
  // Query for photos
  let data = await getOnThisDayRaw(week);

  // Group photos by day
  const ans: IDay[] = [];
  let prevDayId = Number.MIN_SAFE_INTEGER;
  for (const photo of data) {
    if (!photo.dayid) continue;

    // This works because the response is sorted by date taken
    if (photo.dayid !== prevDayId) {
      ans.push({
        dayid: photo.dayid,
        count: 0,
        detail: [],
      });
      prevDayId = photo.dayid;
    }

    // Add to last day
    const day = ans[ans.length - 1];
    day.detail!.push(photo);
    day.count++;
  }

  return ans;
}
