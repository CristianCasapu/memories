<template>
  <Modal ref="modal" @close="cleanup" v-if="show" size="normal">
    <template #title>
      <span v-if="pane === 'form'">{{ t('memories', 'Create a video from {n} photos', { n: fileIds.length }) }}</span>
      <span v-else>{{ t('memories', 'Videos in the making') }}</span>
    </template>

    <!-- options -->
    <div class="fields" v-if="pane === 'form'">
      <label class="field">
        <span>{{ t('memories', 'Time per photo') }}</span>
        <select v-model="secondsPerPhoto">
          <option :value="0.25">{{ t('memories', '¼ second (burst animation)') }}</option>
          <option :value="0.5">{{ t('memories', '½ second') }}</option>
          <option :value="1">{{ t('memories', '1 second') }}</option>
          <option :value="2">{{ t('memories', '2 seconds (slideshow)') }}</option>
          <option :value="3">{{ t('memories', '3 seconds') }}</option>
        </select>
      </label>

      <label class="field">
        <span>{{ t('memories', 'Music') }}</span>
        <select v-model="music" :disabled="!status.enabled">
          <option value="auto">{{ t('memories', 'Automatic — from the mood of the photos') }}</option>
          <option value="none">{{ t('memories', 'No music') }}</option>
          <option v-for="(label, id) in status.moods" :key="id" :value="id">{{ label }}</option>
        </select>
      </label>
      <p class="hint" v-if="!status.enabled">
        {{ t('memories', 'Background music is not set up (Administration › Memories › Music).') }}
      </p>
      <p class="hint" v-else-if="!status.moodDetection">
        {{
          t(
            'memories',
            'The mood cannot be read from the photos (natural-language search is off); "Automatic" uses calm music.',
          )
        }}
      </p>
      <p class="hint">
        {{
          t(
            'memories',
            'About {s} seconds; saved as an MP4 next to the first photo. It is made in the background: you can keep using Memories and you are notified when it is ready.',
            {
              s: Math.round(fileIds.length * secondsPerPhoto),
            },
          )
        }}
      </p>
    </div>

    <!-- the queue -->
    <div class="jobs" v-else>
      <p class="hint" v-if="!jobs.length">
        {{ t('memories', 'No videos yet. Select photos and choose "Create a video".') }}
      </p>
      <div class="job" v-for="job in jobs" :key="job.id" :class="job.status">
        <div class="head">
          <span class="status">{{ statusLabel(job) }}</span>
          <span class="title">{{
            job.result_name || job.title || t('memories', '{n} photos', { n: job.photos })
          }}</span>
          <span class="when">{{ when(job) }}</span>
        </div>
        <div class="bar" v-if="job.status === 'running' || job.status === 'queued'">
          <span :style="{ width: job.progress + '%' }"></span>
        </div>
        <div class="step" v-if="job.status === 'running' || job.status === 'queued'">{{ job.step }}</div>
        <div class="error" v-if="job.status === 'failed'">{{ job.error }}</div>
        <div class="result" v-if="job.status === 'done'">
          {{ t('memories', 'Saved in {folder}', { folder: job.result_folder || '/' }) }}
          <span v-if="job.track && job.track.credit">
            — {{ t('memories', 'music: {credit}', { credit: job.track.credit }) }}</span
          >
          <span v-else-if="job.mood"> — {{ t('memories', 'mood: {mood}', { mood: moodLabel(job.mood) }) }}</span>
        </div>
        <div class="actions">
          <NcButton v-if="job.status === 'done'" @click="openVideos()" variant="tertiary">{{
            t('memories', 'Open')
          }}</NcButton>
          <NcButton
            v-if="job.status === 'queued' || job.status === 'running'"
            @click="act(job, 'cancel')"
            variant="tertiary"
            >{{ t('memories', 'Cancel') }}</NcButton
          >
          <NcButton
            v-if="job.status === 'failed' || job.status === 'cancelled'"
            @click="act(job, 'retry')"
            variant="tertiary"
            >{{ t('memories', 'Try again') }}</NcButton
          >
          <NcButton v-if="job.status !== 'running'" @click="act(job, 'delete')" variant="tertiary">{{
            t('memories', 'Remove from the list')
          }}</NcButton>
        </div>
      </div>
    </div>

    <template #buttons>
      <template v-if="pane === 'form'">
        <NcButton @click="close" class="button">{{ t('memories', 'Cancel') }}</NcButton>
        <NcButton @click="create" class="button" variant="primary" :disabled="busy">
          {{ busy ? t('memories', 'Starting …') : t('memories', 'Create the video') }}
        </NcButton>
      </template>
      <template v-else>
        <NcButton @click="close" class="button" variant="primary">{{ t('memories', 'Close') }}</NcButton>
      </template>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';
import NcButton from '@nextcloud/vue/components/NcButton';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import * as utils from '@services/utils';
import { API } from '@services/API';

type IMusicStatus = { enabled: boolean; providers: string[]; moodDetection: boolean; moods: Record<string, string> };
type IVideoJob = {
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
  track: { credit?: string } | null;
  error: string | null;
  created: number;
  finished: number;
};

/**
 * "Create a video": pace and music, then the list of the person's videos in the making
 * (progress, result, errors; cancel / retry / remove).
 */
export default defineComponent({
  name: 'VideoCreateModal',
  components: { NcButton, Modal },
  mixins: [ModalMixin],
  emits: [],

  data: () => ({
    pane: 'form' as 'form' | 'jobs',
    fileIds: [] as number[],
    secondsPerPhoto: 0.5,
    music: 'auto',
    busy: false,
    status: { enabled: false, providers: [], moodDetection: false, moods: {} } as IMusicStatus,
    jobs: [] as IVideoJob[],
    timer: null as number | null,
  }),

  created() {
    _m.modals.createVideo = this.open;
    _m.modals.videoJobs = this.openJobs;
  },

  beforeUnmount() {
    this.stopPolling();
  },

  methods: {
    async open(fileIds: number[]) {
      this.pane = 'form';
      this.fileIds = fileIds;
      this.busy = false;
      // a burst (many frames) wants a fast pace, a hand-picked selection a slow one
      this.secondsPerPhoto = fileIds.length >= 8 ? 0.25 : 2;
      this.show = true;
      await this.loadStatus();
    },

    async openJobs() {
      this.pane = 'jobs';
      this.show = true;
      await this.refresh();
    },

    cleanup() {
      this.show = false;
      this.stopPolling();
    },

    async loadStatus() {
      try {
        this.status = (await axios.get<IMusicStatus>(API.MUSIC_STATUS())).data;
        if (!this.status.enabled) this.music = 'none';
      } catch (e) {
        console.warn('music status', e);
      }
    },

    async create() {
      if (this.busy) return;
      this.busy = true;
      try {
        await axios.post(API.BURST_VIDEO(), {
          fileids: this.fileIds,
          fps: 1 / this.secondsPerPhoto,
          music: this.music,
        });
        this.pane = 'jobs';
        await this.refresh();
      } catch (error: any) {
        console.error(error);
        showError(error?.response?.data?.message || this.t('memories', 'The video could not be started'));
      } finally {
        this.busy = false;
      }
    },

    async refresh() {
      try {
        this.jobs = (await axios.get<IVideoJob[]>(API.VIDEO_JOBS())).data;
      } catch (e) {
        console.warn('video jobs', e);
      }
      const active = this.jobs.some((j) => j.status === 'queued' || j.status === 'running');
      this.stopPolling();
      if (active && this.show) {
        this.timer = window.setTimeout(() => this.refresh(), 2500);
      }
      if (!active && this.jobs.some((j) => j.status === 'done')) {
        utils.bus.emit('memories:timeline:soft-refresh', null);
      }
    },

    stopPolling() {
      if (this.timer) {
        window.clearTimeout(this.timer);
        this.timer = null;
      }
    },

    async act(job: IVideoJob, what: 'cancel' | 'retry' | 'delete') {
      try {
        if (what === 'delete') {
          await axios.delete(API.VIDEO_JOB_DELETE(job.id));
        } else {
          await axios.post(API.VIDEO_JOB(job.id, what), {});
        }
      } catch (error: any) {
        showError(error?.response?.data?.message || String(error));
      }
      await this.refresh();
    },

    async openVideos() {
      await this.close();
      this.$router.push({ name: 'videos' });
    },

    statusLabel(job: IVideoJob): string {
      const labels: Record<string, string> = {
        queued: this.t('memories', 'Waiting'),
        running: this.t('memories', 'Making … {p}%', { p: job.progress }),
        done: this.t('memories', 'Ready'),
        failed: this.t('memories', 'Failed'),
        cancelled: this.t('memories', 'Cancelled'),
      };
      return labels[job.status] ?? job.status;
    },

    moodLabel(mood: string): string {
      return this.status.moods?.[mood] ?? mood;
    },

    when(job: IVideoJob): string {
      const ts = (job.finished || job.created) * 1000;
      return utils.getFromNowStr(new Date(ts));
    },
  },
});
</script>

<style lang="scss" scoped>
.fields {
  padding: 4px 0;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 12px;
  select {
    width: 100%;
    padding: 8px 10px;
    border-radius: var(--border-radius-element, 8px);
    border: 2px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
  }
}
.hint {
  color: var(--color-text-maxcontrast);
  font-size: 0.9em;
  margin: 4px 0 8px;
}
.jobs {
  max-height: 60vh;
  overflow-y: auto;
}
.job {
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 10px 12px;
  margin-bottom: 10px;
  .head {
    display: flex;
    gap: 10px;
    align-items: baseline;
    flex-wrap: wrap;
  }
  .status {
    font-weight: 600;
  }
  .title {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .when {
    color: var(--color-text-maxcontrast);
    font-size: 0.85em;
  }
  .bar {
    height: 6px;
    border-radius: 3px;
    background: var(--color-background-dark);
    overflow: hidden;
    margin: 8px 0 4px;
    span {
      display: block;
      height: 100%;
      background: var(--color-primary-element);
      transition: width 0.4s ease;
    }
  }
  .step,
  .result {
    font-size: 0.9em;
    color: var(--color-text-maxcontrast);
  }
  .error {
    font-size: 0.9em;
    color: var(--color-error);
  }
  &.done .status {
    color: var(--color-success);
  }
  &.failed .status {
    color: var(--color-error);
  }
  .actions {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    margin-top: 4px;
  }
}
</style>
