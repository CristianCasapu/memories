<template>
  <div class="clips-page">
    <div class="top-matter clips-top">
      <NcActions>
        <NcActionButton :aria-label="t('memories', 'Back')" @click="$router.go(-1)">
          {{ t('memories', 'Back') }}
          <template #icon> <BackIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
      <span class="name">{{ t('memories', 'Clips') }}</span>
      <div class="right-actions">
        <NcActions :inline="1">
          <NcActionButton :aria-label="t('memories', 'Refresh')" @click="refresh()" close-after-click>
            {{ t('memories', 'Refresh') }}
            <template #icon> <RefreshIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </div>
    </div>

    <div class="intro">
      <p>
        {{
          t(
            'memories',
            'Short videos made from your photos, with music chosen by their mood. Select photos anywhere and choose "Create a video", or make one from an album or an event. Automatic clips of your events can be switched on in Settings.',
          )
        }}
      </p>
    </div>

    <div class="empty" v-if="loaded && !clips.length">
      {{ t('memories', 'No clips yet.') }}
    </div>

    <div class="grid">
      <div class="clip" v-for="clip in clips" :key="clip.id" :class="clip.status">
        <div class="thumb" @click="play(clip)">
          <img
            v-if="clip.status === 'done' && clip.result_exists"
            :src="thumb(clip)"
            :alt="clip.result_name"
            loading="lazy"
            @error="thumbFallback($event, clip)"
          />
          <div class="placeholder" v-else>
            <VideoIcon :size="40" />
          </div>
          <div class="badge" v-if="clip.status !== 'done'">{{ statusLabel(clip) }}</div>
          <div class="badge missing" v-else-if="!clip.result_exists">{{ t('memories', 'File removed') }}</div>
          <div class="playicon" v-else><PlayIcon :size="44" /></div>
          <div class="bar" v-if="clip.status === 'running'"><span :style="{ width: clip.progress + '%' }"></span></div>
        </div>
        <div class="body">
          <div class="title">
            {{ clip.title || clip.result_name || t('memories', '{n} photos', { n: clip.photos }) }}
          </div>
          <div class="meta">
            <span>{{ when(clip) }}</span>
            <span v-if="clip.mood"> · {{ moodLabel(clip.mood) }}</span>
            <span v-if="clip.location"> · {{ clip.location }}</span>
            <span v-if="clip.kind === 'auto'"> · {{ t('memories', 'automatic') }}</span>
          </div>
          <div class="caption" v-if="clip.caption">{{ clip.caption }}</div>
          <div class="mentions" v-if="clip.mentions && clip.mentions.length">
            <span v-for="m in clip.mentions" :key="m.uid || m.name">@{{ m.name }}</span>
          </div>
          <div class="step" v-if="clip.status === 'running' || clip.status === 'queued'">{{ clip.step }}</div>
          <div class="error" v-if="clip.status === 'failed'">{{ clip.error }}</div>
          <div class="credit" v-if="clip.track && clip.track.credit">
            {{ t('memories', 'music: {credit}', { credit: clip.track.credit }) }}
          </div>
        </div>
        <div class="actions">
          <NcActions :inline="clip.status === 'done' && clip.result_exists ? 2 : 0">
            <NcActionButton
              v-if="clip.status === 'done' && clip.result_exists"
              :aria-label="t('memories', 'Play')"
              @click="play(clip)"
              close-after-click
            >
              {{ t('memories', 'Play') }}
              <template #icon> <PlayIcon :size="20" /> </template>
            </NcActionButton>
            <NcActionButton
              v-if="clip.status === 'done' && clip.result_exists"
              :aria-label="t('memories', 'Share')"
              @click="share(clip)"
              close-after-click
            >
              {{ t('memories', 'Share') }}
              <template #icon> <ShareIcon :size="20" /> </template>
            </NcActionButton>
            <NcActionButton
              v-if="clip.status === 'done' && clip.result_exists"
              :aria-label="t('memories', 'Download')"
              @click="download(clip)"
              close-after-click
            >
              {{ t('memories', 'Download') }}
              <template #icon> <DownloadIcon :size="20" /> </template>
            </NcActionButton>
            <NcActionButton
              v-if="clip.status !== 'running' && clip.status !== 'queued'"
              :aria-label="t('memories', 'Edit and make again')"
              @click="edit(clip)"
              close-after-click
            >
              {{ t('memories', 'Edit and make again') }}
              <template #icon> <EditIcon :size="20" /> </template>
            </NcActionButton>
            <NcActionButton
              v-if="clip.status === 'queued' || clip.status === 'running'"
              :aria-label="t('memories', 'Cancel')"
              @click="act(clip, 'cancel')"
              close-after-click
            >
              {{ t('memories', 'Cancel') }}
              <template #icon> <CancelIcon :size="20" /> </template>
            </NcActionButton>
            <NcActionButton
              v-if="clip.status === 'failed' || clip.status === 'cancelled'"
              :aria-label="t('memories', 'Try again')"
              @click="act(clip, 'retry')"
              close-after-click
            >
              {{ t('memories', 'Try again') }}
              <template #icon> <RefreshIcon :size="20" /> </template>
            </NcActionButton>
            <NcActionButton
              v-if="clip.status !== 'running'"
              :aria-label="t('memories', 'Delete')"
              @click="remove(clip)"
              close-after-click
            >
              {{
                clip.status === 'done' && clip.result_exists
                  ? t('memories', 'Delete the clip and its file')
                  : t('memories', 'Remove from the list')
              }}
              <template #icon> <DeleteIcon :size="20" /> </template>
            </NcActionButton>
          </NcActions>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import * as dav from '@services/dav';
import * as utils from '@services/utils';
import { confirmDestructive } from '@services/utils/dialog';
import { API } from '@services/API';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import RefreshIcon from 'vue-material-design-icons/Refresh.vue';
import VideoIcon from 'vue-material-design-icons/MovieOpenPlay.vue';
import PlayIcon from 'vue-material-design-icons/PlayCircle.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import DownloadIcon from 'vue-material-design-icons/Download.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';
import CancelIcon from 'vue-material-design-icons/Close.vue';

import type { IPhoto } from '@typings';

type IClip = {
  id: number;
  status: 'queued' | 'running' | 'done' | 'failed' | 'cancelled';
  title: string;
  photos: number;
  mood: string;
  progress: number;
  step: string;
  result_fileid: number | null;
  result_name: string;
  result_folder: string;
  result_etag: string;
  result_dayid: number;
  result_w: number;
  result_h: number;
  result_exists: boolean;
  track: { credit?: string } | null;
  error: string | null;
  created: number;
  finished: number;
  caption: string;
  location: string;
  mentions: { uid: string; name: string }[];
  texts: string[];
  kind: string;
  fps: number;
  music: string;
  file_ids: number[];
};

/** The person's clips: what was made, what is being made, and what to do with them. */
export default defineComponent({
  name: 'Clips',
  components: {
    NcActions,
    NcActionButton,
    BackIcon,
    RefreshIcon,
    VideoIcon,
    PlayIcon,
    ShareIcon,
    DownloadIcon,
    EditIcon,
    DeleteIcon,
    CancelIcon,
  },

  data: () => ({
    clips: [] as IClip[],
    loaded: false,
    timer: null as number | null,
    moods: {} as Record<string, string>,
  }),

  async mounted() {
    utils.bus.on('memories:clips:refresh', this.refresh);
    try {
      this.moods = (await axios.get(API.MUSIC_STATUS())).data.moods ?? {};
    } catch (e) {
      /* no music */
    }
    await this.refresh();
  },

  beforeUnmount() {
    utils.bus.off('memories:clips:refresh', this.refresh);
    if (this.timer) window.clearTimeout(this.timer);
  },

  methods: {
    async refresh() {
      try {
        this.clips = (await axios.get<IClip[]>(API.VIDEO_JOBS())).data;
      } catch (e) {
        console.warn(e);
      }
      this.loaded = true;
      if (this.timer) window.clearTimeout(this.timer);
      if (this.clips.some((c) => c.status === 'queued' || c.status === 'running')) {
        this.timer = window.setTimeout(() => this.refresh(), 3000);
      }
    },

    thumb(clip: IClip): string {
      return API.Q(API.IMAGE_PREVIEW(clip.result_fileid!), { c: clip.result_etag, x: 512, y: 512, a: 1 });
    },

    /** No preview for the video (yet): show the first photo of the clip instead */
    thumbFallback(event: Event, clip: IClip) {
      const img = event.target as HTMLImageElement;
      const first = clip.file_ids?.[0];
      if (!first || img.dataset.fallback) return;
      img.dataset.fallback = '1';
      img.src = API.Q(API.IMAGE_PREVIEW(first), { x: 512, y: 512, a: 1 });
    },

    asPhoto(clip: IClip): IPhoto {
      return {
        fileid: clip.result_fileid!,
        etag: clip.result_etag,
        dayid: clip.result_dayid,
        flag: this.c.FLAG_IS_VIDEO,
        basename: clip.result_name,
        mimetype: 'video/mp4',
        w: clip.result_w || 1920,
        h: clip.result_h || 1080,
        key: `${clip.result_fileid}`,
      } as IPhoto;
    },

    play(clip: IClip) {
      if (clip.status !== 'done' || !clip.result_exists) return;
      const done = this.clips.filter((c) => c.status === 'done' && c.result_exists).map((c) => this.asPhoto(c));
      _m.viewer.openStatic(this.asPhoto(clip), done, 512);
    },

    share(clip: IClip) {
      // the Nextcloud sharing tab: people, groups and public links
      _m.sidebar.open(clip.result_fileid!, clip.result_folder.replace(/\/$/, '') + '/' + clip.result_name);
      window.setTimeout(() => _m.sidebar.setTab('sharing'), 300);
    },

    async download(clip: IClip) {
      await dav.downloadFiles([clip.result_fileid!]);
    },

    edit(clip: IClip) {
      _m.modals.createVideo(clip.file_ids, {
        title: clip.title,
        caption: clip.caption,
        location: clip.location,
        mentions: clip.mentions,
        texts: clip.texts,
        music: clip.music,
        fps: clip.fps,
        replace: clip.id,
      });
    },

    async act(clip: IClip, what: 'cancel' | 'retry') {
      try {
        await axios.post(API.VIDEO_JOB(clip.id, what), {});
      } catch (error: any) {
        showError(error?.response?.data?.message || String(error));
      }
      await this.refresh();
    },

    async remove(clip: IClip) {
      const withFile = clip.status === 'done' && clip.result_exists;
      if (
        withFile &&
        !(await confirmDestructive({
          title: this.t('memories', 'Delete clip'),
          message: this.t('memories', 'Delete the clip "{name}" and its video file?', { name: clip.result_name }),
          confirm: this.t('memories', 'Delete'),
          confirmClasses: 'error',
        }))
      )
        return;
      try {
        if (withFile) await axios.post(API.CLIP_REMOVE(clip.id), {});
        else await axios.delete(API.VIDEO_JOB_DELETE(clip.id));
      } catch (error: any) {
        showError(error?.response?.data?.message || String(error));
      }
      await this.refresh();
    },

    statusLabel(clip: IClip): string {
      const labels: Record<string, string> = {
        queued: this.t('memories', 'Waiting'),
        running: this.t('memories', 'Making … {p}%', { p: clip.progress }),
        failed: this.t('memories', 'Failed'),
        cancelled: this.t('memories', 'Cancelled'),
      };
      return labels[clip.status] ?? clip.status;
    },

    moodLabel(mood: string): string {
      return this.moods[mood] ?? mood;
    },

    when(clip: IClip): string {
      return utils.getFromNowStr(new Date((clip.finished || clip.created) * 1000));
    },
  },
});
</script>

<style lang="scss" scoped>
.clips-page {
  padding: 0 8px 20px;
}
.clips-top {
  display: flex;
  align-items: center;
  padding: 2px 4px;
  .name {
    flex: 1;
    font-size: 1.3em;
    font-weight: 500;
    padding: 0 8px;
  }
}
.intro {
  color: var(--color-text-maxcontrast);
  padding: 0 8px 8px;
  max-width: 900px;
}
.empty {
  padding: 30px;
  text-align: center;
  color: var(--color-text-maxcontrast);
}
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 14px;
  padding: 4px;
}
.clip {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  overflow: hidden;
  background: var(--color-main-background);
  display: flex;
  flex-direction: column;
  .thumb {
    position: relative;
    aspect-ratio: 16 / 9;
    background: var(--color-background-dark);
    cursor: pointer;
    img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .placeholder {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--color-text-maxcontrast);
    }
    .playicon {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      opacity: 0;
      transition: opacity 0.15s;
      text-shadow: 0 0 8px rgba(0, 0, 0, 0.6);
    }
    &:hover .playicon {
      opacity: 1;
    }
    .badge {
      position: absolute;
      top: 8px;
      left: 8px;
      padding: 2px 8px;
      border-radius: 8px;
      background: rgba(0, 0, 0, 0.6);
      color: #fff;
      font-size: 0.85em;
      &.missing {
        background: var(--color-error);
      }
    }
    .bar {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      height: 5px;
      background: rgba(0, 0, 0, 0.3);
      span {
        display: block;
        height: 100%;
        background: var(--color-primary-element);
      }
    }
  }
  .body {
    padding: 8px 10px 4px;
    flex: 1;
    .title {
      font-weight: 600;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .meta,
    .credit,
    .step {
      color: var(--color-text-maxcontrast);
      font-size: 0.85em;
    }
    .caption {
      margin-top: 4px;
      font-size: 0.92em;
    }
    .mentions span {
      display: inline-block;
      margin: 4px 6px 0 0;
      padding: 1px 8px;
      border-radius: 10px;
      background: var(--color-background-dark);
      font-size: 0.85em;
    }
    .error {
      color: var(--color-error);
      font-size: 0.9em;
    }
  }
  .actions {
    padding: 0 6px 6px;
  }
  &.failed .thumb .badge {
    background: var(--color-error);
  }
}
</style>
