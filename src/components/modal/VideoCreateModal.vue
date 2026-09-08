<template>
  <Modal ref="modal" @close="cleanup" v-if="show" size="large">
    <template #title>
      <span v-if="pane === 'form'">{{
        preset.replace ? t('memories', 'Edit the clip') : t('memories', 'Create a clip')
      }}</span>
      <span v-else>{{ t('memories', 'Videos in the making') }}</span>
    </template>

    <!-- ============ the clip: what it shows, how it sounds ============ -->
    <div class="clip-form" v-if="pane === 'form'">
      <!-- preview -->
      <div class="preview" :class="{ playing: previewOn }">
        <div class="frame">
          <img
            v-if="previewIds.length"
            :src="previewUrl(previewIds[previewIndex])"
            :key="previewIds[previewIndex]"
            alt=""
          />
          <div class="nothing" v-else>{{ t('memories', 'No photos yet') }}</div>
          <transition name="fade">
            <div class="card" v-if="previewIds.length && showCard">
              <div class="ptitle" v-if="title">{{ title }}</div>
              <div class="psub" v-if="subline">{{ subline }}</div>
              <div class="pwith" v-if="mentions.length">{{ mentions.map((m) => '@' + m.name).join('  ') }}</div>
            </div>
          </transition>
          <transition name="fade">
            <div class="line" v-if="previewIds.length && currentLine">{{ currentLine }}</div>
          </transition>
          <div class="counter" v-if="previewIds.length">{{ previewIndex + 1 }} / {{ previewIds.length }}</div>
        </div>
        <div class="preview-bar">
          <NcButton @click="togglePreview" variant="tertiary">
            <template #icon><PauseIcon v-if="previewOn" :size="20" /><PlayIcon v-else :size="20" /></template>
            {{ previewOn ? t('memories', 'Pause preview') : t('memories', 'Play preview') }}
          </NcButton>
          <span class="track" v-if="track">
            <MusicIcon :size="16" /> {{ track.title }}<span v-if="track.artist"> — {{ track.artist }}</span>
            <a href="#" @click.prevent="pickTrack(true)">{{ t('memories', 'another one') }}</a>
          </span>
          <span class="track muted" v-else-if="music === 'none'">{{ t('memories', 'No music') }}</span>
          <span class="track muted" v-else-if="pickingTrack">{{ t('memories', 'Looking for music …') }}</span>
          <span class="track muted" v-else-if="!status.enabled">{{
            t('memories', 'Music is not set up (Administration › Memories › Music)')
          }}</span>
          <span class="hint">{{
            t('memories', 'About {s} s', { s: Math.round(previewIds.length * secondsPerPhoto) })
          }}</span>
        </div>
        <audio ref="audio" :src="track ? track.url : undefined" loop preload="none"></audio>
      </div>

      <!-- photos: from an album / event, or the selection -->
      <div class="section" v-if="source.albumName || source.event || fileIds.length > 30">
        <label class="field">
          <span>{{ t('memories', 'Photos') }}</span>
          <div class="row">
            <select v-model="pickMode" @change="pickPhotos">
              <option value="best">
                {{ t('memories', 'The best ones (faces large, sharp, well lit; spread over time)') }}
              </option>
              <option value="random">{{ t('memories', 'A random handful') }}</option>
              <option value="all">{{ t('memories', 'All of them (up to 200)') }}</option>
            </select>
            <input
              type="number"
              v-model.number="pickN"
              min="2"
              max="200"
              :disabled="pickMode === 'all'"
              @change="pickPhotos"
            />
          </div>
        </label>
        <p class="hint">
          {{
            t('memories', '{picked} of {total} photos', {
              picked: previewIds.length,
              total: sourceTotal || fileIds.length,
            })
          }}
        </p>
      </div>

      <div class="cols">
        <div class="col">
          <label class="field">
            <span>{{ t('memories', 'Title') }}</span>
            <input
              type="text"
              v-model="title"
              maxlength="200"
              :placeholder="t('memories', 'Shown on the first seconds of the clip')"
            />
          </label>
          <label class="field">
            <span>{{ t('memories', 'Mood / what it is about') }}</span>
            <input
              type="text"
              v-model="caption"
              maxlength="1000"
              :placeholder="t('memories', 'e.g. Best day of the summer 🌞')"
            />
          </label>
          <label class="field">
            <span>{{ t('memories', 'Location') }}</span>
            <input type="text" v-model="location" maxlength="250" :placeholder="t('memories', 'e.g. Vama Veche')" />
          </label>
          <div class="field">
            <span>{{ t('memories', 'People in the clip') }}</span>
            <div class="chips">
              <span class="chip" v-for="(m, i) in mentions" :key="i" :class="{ user: m.uid }">
                @{{ m.name }}
                <button type="button" @click="mentions.splice(i, 1)" :aria-label="t('memories', 'Remove')">×</button>
              </span>
              <input
                type="text"
                v-model="mentionQuery"
                :placeholder="t('memories', 'Type a name; a Nextcloud user is tagged and gets the clip')"
                @input="searchUsers"
                @keydown.enter.prevent="addMention()"
                @keydown.backspace="mentionQuery === '' && mentions.pop()"
              />
            </div>
            <div class="suggest" v-if="suggestions.length">
              <button type="button" v-for="s in suggestions" :key="s.uid" @click="addMention(s)">
                <AccountIcon :size="16" /> {{ s.name }} <small>{{ s.uid }}</small>
              </button>
            </div>
          </div>
        </div>

        <div class="col">
          <label class="field">
            <span>{{ t('memories', 'Text lines shown over the clip (one per line; lyrics, a wish, a joke …)') }}</span>
            <textarea
              v-model="textsRaw"
              rows="4"
              :placeholder="t('memories', 'Spread over the clip, one after another')"
            ></textarea>
          </label>
          <label class="field">
            <span>{{ t('memories', 'Time per photo') }}</span>
            <select v-model="secondsPerPhoto">
              <option :value="0.25">{{ t('memories', '¼ second (burst animation)') }}</option>
              <option :value="0.5">{{ t('memories', '½ second') }}</option>
              <option :value="1">{{ t('memories', '1 second') }}</option>
              <option :value="1.5">{{ t('memories', '1½ seconds') }}</option>
              <option :value="2">{{ t('memories', '2 seconds (slideshow)') }}</option>
              <option :value="3">{{ t('memories', '3 seconds') }}</option>
            </select>
          </label>
          <label class="field">
            <span>{{ t('memories', 'Music') }}</span>
            <select v-model="music" :disabled="!status.enabled" @change="pickTrack(true)">
              <option value="auto">{{ t('memories', 'Automatic — from the mood of the photos') }}</option>
              <option value="none">{{ t('memories', 'No music') }}</option>
              <option v-for="(label, id) in status.moods" :key="id" :value="id">{{ label }}</option>
            </select>
          </label>
          <p class="hint" v-if="detectedMood && music === 'auto'">
            {{ t('memories', 'The photos look like: {mood}', { mood: status.moods[detectedMood] || detectedMood }) }}
          </p>
          <p class="hint">
            {{
              t(
                'memories',
                'Saved as an MP4 next to the first photo and listed under Clips. Made in the background; you are notified when it is ready.',
              )
            }}
          </p>
        </div>
      </div>
    </div>

    <!-- ============ the queue ============ -->
    <div class="jobs" v-else>
      <p class="hint" v-if="!jobs.length">
        {{ t('memories', 'No videos yet. Select photos and choose "Create a video".') }}
      </p>
      <div class="job" v-for="job in jobs" :key="job.id" :class="job.status">
        <div class="head">
          <span class="status">{{ statusLabel(job) }}</span>
          <span class="title">{{
            job.title || job.result_name || t('memories', '{n} photos', { n: job.photos })
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
        </div>
        <div class="actions">
          <NcButton v-if="job.status === 'done'" @click="openClips()" variant="tertiary">{{
            t('memories', 'Open Clips')
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
        <NcButton @click="create" class="button" variant="primary" :disabled="busy || previewIds.length < 2">
          {{ busy ? t('memories', 'Starting …') : t('memories', 'Make the clip') }}
        </NcButton>
      </template>
      <template v-else>
        <NcButton @click="openClips" class="button">{{ t('memories', 'Open Clips') }}</NcButton>
        <NcButton @click="close" class="button" variant="primary">{{ t('memories', 'Close') }}</NcButton>
      </template>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';
import { generateOcsUrl } from '@nextcloud/router';
import NcButton from '@nextcloud/vue/components/NcButton';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import * as utils from '@services/utils';
import { API } from '@services/API';

import PlayIcon from 'vue-material-design-icons/Play.vue';
import PauseIcon from 'vue-material-design-icons/Pause.vue';
import MusicIcon from 'vue-material-design-icons/MusicNote.vue';
import AccountIcon from 'vue-material-design-icons/Account.vue';

type IMusicStatus = { enabled: boolean; providers: string[]; moodDetection: boolean; moods: Record<string, string> };
type ITrack = {
  provider: string;
  url: string;
  title: string;
  artist: string;
  license: string;
  seconds: number;
  credit: string;
};
type IMention = { uid: string; name: string };
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
type IPreset = {
  albumUser?: string;
  albumName?: string;
  event?: number;
  title?: string;
  caption?: string;
  location?: string;
  mentions?: IMention[];
  texts?: string[];
  music?: string;
  fps?: number;
  replace?: number;
};

/**
 * "Create a clip": the photos (picked from a selection, an album or an event), what the clip
 * says (title, mood, location, people, text lines), how it sounds — with a preview that cycles
 * the photos at the chosen pace, shows the text as the clip will, and plays the very track
 * the clip will get. Then the list of the clips in the making.
 */
export default defineComponent({
  name: 'VideoCreateModal',
  components: { NcButton, Modal, PlayIcon, PauseIcon, MusicIcon, AccountIcon },
  mixins: [ModalMixin],
  emits: [],

  data: () => ({
    pane: 'form' as 'form' | 'jobs',
    fileIds: [] as number[],
    previewIds: [] as number[],
    preset: {} as IPreset,
    source: {} as { albumUser?: string; albumName?: string; event?: number },
    sourceTotal: 0,
    pickMode: 'best' as 'best' | 'random' | 'all',
    pickN: 24,
    title: '',
    caption: '',
    location: '',
    mentions: [] as IMention[],
    mentionQuery: '',
    suggestions: [] as IMention[],
    searchTimer: null as number | null,
    textsRaw: '',
    secondsPerPhoto: 1.5,
    music: 'auto',
    track: null as ITrack | null,
    detectedMood: '',
    pickingTrack: false,
    busy: false,
    status: { enabled: false, providers: [], moodDetection: false, moods: {} } as IMusicStatus,
    // preview
    previewOn: false,
    previewIndex: 0,
    previewTimer: null as number | null,
    previewStart: 0,
    previewElapsed: 0,
    // queue
    jobs: [] as IVideoJob[],
    timer: null as number | null,
  }),

  computed: {
    subline(): string {
      return [this.location.trim(), this.caption.trim()].filter(Boolean).join('  ·  ');
    },
    texts(): string[] {
      return this.textsRaw
        .split('\n')
        .map((t) => t.trim())
        .filter(Boolean);
    },
    duration(): number {
      return this.previewIds.length * this.secondsPerPhoto;
    },
    cardSeconds(): number {
      return Math.min(3.5, Math.max(1.5, this.duration * 0.3));
    },
    showCard(): boolean {
      return (!!this.title || !!this.subline || this.mentions.length > 0) && this.previewElapsed < this.cardSeconds;
    },
    currentLine(): string {
      if (!this.texts.length) return '';
      const start = this.title || this.subline ? this.cardSeconds : 0;
      const slice = Math.max(1, (this.duration - start) / this.texts.length);
      const i = Math.floor((this.previewElapsed - start) / slice);
      return i >= 0 && i < this.texts.length
        ? this.texts[i]
        : i >= this.texts.length
          ? this.texts[this.texts.length - 1]
          : '';
    },
  },

  created() {
    _m.modals.createVideo = this.open;
    _m.modals.videoJobs = this.openJobs;
  },

  beforeUnmount() {
    this.stopPolling();
    this.stopPreview();
  },

  methods: {
    async open(fileIds: number[], preset: IPreset = {}) {
      this.pane = 'form';
      this.preset = preset;
      this.fileIds = fileIds;
      this.previewIds = fileIds.slice(0, 200);
      this.source = { albumUser: preset.albumUser, albumName: preset.albumName, event: preset.event };
      this.sourceTotal = 0;
      this.pickMode = 'best';
      this.pickN = 24;
      this.title = preset.title ?? '';
      this.caption = preset.caption ?? '';
      this.location = preset.location ?? '';
      this.mentions = (preset.mentions ?? []).map((m) => ({ uid: m.uid ?? '', name: m.name }));
      this.textsRaw = (preset.texts ?? []).join('\n');
      this.music = preset.music ?? 'auto';
      this.secondsPerPhoto = preset.fps ? Math.round((1 / preset.fps) * 4) / 4 : fileIds.length >= 30 ? 0.5 : 1.5;
      this.track = null;
      this.detectedMood = '';
      this.busy = false;
      this.previewIndex = 0;
      this.previewElapsed = 0;
      this.show = true;
      await this.loadStatus();
      if (this.source.albumName || this.source.event || fileIds.length > 30) {
        await this.pickPhotos();
      }
      if (this.status.enabled && this.music !== 'none') await this.pickTrack(false);
      this.startPreview();
    },

    async openJobs() {
      this.pane = 'jobs';
      this.show = true;
      await this.refresh();
    },

    cleanup() {
      this.show = false;
      this.stopPolling();
      this.stopPreview();
    },

    async loadStatus() {
      try {
        this.status = (await axios.get<IMusicStatus>(API.MUSIC_STATUS())).data;
        if (!this.status.enabled) this.music = 'none';
      } catch (e) {
        console.warn('music status', e);
      }
    },

    /** the photos of the album / event, or the best of a big selection */
    async pickPhotos() {
      try {
        const res = await axios.post(API.CLIPS_PICK(), {
          fileids: this.source.albumName || this.source.event ? [] : this.fileIds,
          albumUser: this.source.albumUser ?? '',
          albumName: this.source.albumName ?? '',
          event: this.source.event ?? 0,
          mode: this.pickMode,
          n: this.pickN,
        });
        this.previewIds = res.data.fileids;
        this.sourceTotal = res.data.total;
        this.previewIndex = 0;
        this.previewElapsed = 0;
      } catch (error: any) {
        showError(error?.response?.data?.message || this.t('memories', 'The photos could not be picked'));
      }
    },

    /** the track the clip will get — heard in the preview */
    async pickTrack(force: boolean) {
      if (this.music === 'none' || !this.status.enabled) {
        this.track = null;
        this.pauseAudio();
        return;
      }
      if (this.track && !force) return;
      this.pickingTrack = true;
      try {
        const res = await axios.get(
          API.Q(API.CLIPS_MUSIC(), { mood: this.music, seconds: Math.ceil(this.duration) || 30 }),
          {
            params: { fileids: this.previewIds.slice(0, 60) },
          },
        );
        this.track = res.data.track;
        this.detectedMood = res.data.mood;
        if (this.previewOn) this.$nextTick(() => this.playAudio());
      } catch (e) {
        console.warn('music', e);
        this.track = null;
      } finally {
        this.pickingTrack = false;
      }
    },

    previewUrl(fileid: number): string {
      return API.Q(API.IMAGE_PREVIEW(fileid), { c: 'clip', x: 1024, y: 1024, a: 1 });
    },

    startPreview() {
      this.stopPreview();
      if (!this.previewIds.length) return;
      this.previewOn = true;
      this.previewStart = Date.now() - this.previewElapsed * 1000;
      this.playAudio();
      const tick = () => {
        this.previewElapsed = (Date.now() - this.previewStart) / 1000;
        if (this.previewElapsed >= this.duration) {
          this.previewStart = Date.now();
          this.previewElapsed = 0;
          const audio = this.$refs.audio as HTMLAudioElement | undefined;
          if (audio) audio.currentTime = 0;
        }
        this.previewIndex = Math.min(
          this.previewIds.length - 1,
          Math.floor(this.previewElapsed / this.secondsPerPhoto),
        );
        this.previewTimer = window.setTimeout(tick, 100);
      };
      tick();
    },

    stopPreview() {
      this.previewOn = false;
      if (this.previewTimer) {
        window.clearTimeout(this.previewTimer);
        this.previewTimer = null;
      }
      this.pauseAudio();
    },

    togglePreview() {
      if (this.previewOn) this.stopPreview();
      else this.startPreview();
    },

    playAudio() {
      const audio = this.$refs.audio as HTMLAudioElement | undefined;
      if (audio && this.track) {
        audio.volume = 0.8;
        audio.play().catch(() => {});
      }
    },

    pauseAudio() {
      const audio = this.$refs.audio as HTMLAudioElement | undefined;
      audio?.pause();
    },

    /** Nextcloud users matching what was typed (the sharees search), for tagging */
    searchUsers() {
      if (this.searchTimer) window.clearTimeout(this.searchTimer);
      const q = this.mentionQuery.trim();
      if (q.length < 2) {
        this.suggestions = [];
        return;
      }
      this.searchTimer = window.setTimeout(async () => {
        try {
          const res = await axios.get(generateOcsUrl('apps/files_sharing/api/v1/sharees'), {
            params: { search: q, itemType: 'file', perPage: 6, lookup: false, format: 'json' },
          });
          const data = res.data?.ocs?.data ?? {};
          const users = [...(data.exact?.users ?? []), ...(data.users ?? [])];
          this.suggestions = users
            .map((u: any) => ({ uid: String(u.value?.shareWith ?? ''), name: String(u.label ?? '') }))
            .filter((u: IMention) => u.uid && !this.mentions.some((m) => m.uid === u.uid))
            .slice(0, 6);
        } catch (e) {
          this.suggestions = [];
        }
      }, 250);
    },

    addMention(user?: IMention) {
      const m = user ?? { uid: '', name: this.mentionQuery.trim() };
      if (!m.name) return;
      if (!this.mentions.some((x) => x.name.toLowerCase() === m.name.toLowerCase())) this.mentions.push(m);
      this.mentionQuery = '';
      this.suggestions = [];
    },

    async create() {
      if (this.busy || this.previewIds.length < 2) return;
      this.busy = true;
      try {
        await axios.post(API.BURST_VIDEO(), {
          fileids: this.previewIds,
          fps: 1 / this.secondsPerPhoto,
          music: this.music,
          name: this.title,
          options: {
            caption: this.caption,
            location: this.location,
            mentions: this.mentions,
            texts: this.texts,
            track: this.track,
            kind: this.source.albumName ? 'album' : this.source.event ? 'event' : 'manual',
            source: this.source.albumName
              ? { album: `${this.source.albumUser}/${this.source.albumName}` }
              : this.source.event
                ? { event: this.source.event }
                : null,
            replace: this.preset.replace ?? 0,
          },
        });
        this.stopPreview();
        this.pane = 'jobs';
        utils.bus.emit('memories:clips:refresh', null);
        await this.refresh();
      } catch (error: any) {
        console.error(error);
        showError(error?.response?.data?.message || this.t('memories', 'The clip could not be started'));
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
        utils.bus.emit('memories:clips:refresh', null);
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

    async openClips() {
      await this.close();
      this.$router.push({ name: 'clips' });
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

    when(job: IVideoJob): string {
      return utils.getFromNowStr(new Date((job.finished || job.created) * 1000));
    },
  },
});
</script>

<style lang="scss" scoped>
.clip-form {
  max-height: 72vh;
  overflow-y: auto;
  padding-right: 4px;
}
.preview {
  margin-bottom: 12px;
  .frame {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    background: #000;
    border-radius: 10px;
    overflow: hidden;
    img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      display: block;
    }
    .nothing {
      color: #999;
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100%;
    }
    .card {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      height: 38%;
      background: rgba(0, 0, 0, 0.45);
      color: #fff;
      text-align: center;
      padding-top: 3%;
      text-shadow: 0 1px 3px rgba(0, 0, 0, 0.8);
      .ptitle {
        font-size: clamp(16px, 3.2vw, 34px);
        font-weight: 700;
      }
      .psub {
        font-size: clamp(12px, 2vw, 21px);
        margin-top: 2%;
      }
      .pwith {
        font-size: clamp(11px, 1.8vw, 19px);
        margin-top: 1%;
        opacity: 0.95;
      }
    }
    .line {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 12%;
      text-align: center;
      color: #fff;
      font-weight: 700;
      font-size: clamp(14px, 2.4vw, 26px);
      text-shadow:
        0 0 6px #000,
        0 0 2px #000;
      padding: 0 6%;
    }
    .counter {
      position: absolute;
      top: 8px;
      right: 10px;
      color: #fff;
      font-size: 0.8em;
      background: rgba(0, 0, 0, 0.5);
      padding: 1px 8px;
      border-radius: 8px;
    }
  }
  .preview-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 6px;
    .track {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 0.9em;
      a {
        color: var(--color-primary-element);
        margin-left: 6px;
      }
    }
    .muted {
      color: var(--color-text-maxcontrast);
    }
    .hint {
      margin-left: auto;
      color: var(--color-text-maxcontrast);
      font-size: 0.85em;
    }
  }
}
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.4s;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
.cols {
  display: flex;
  gap: 16px;
  .col {
    flex: 1;
    min-width: 0;
  }
  @media (max-width: 700px) {
    flex-direction: column;
    gap: 0;
  }
}
.section {
  margin-bottom: 8px;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 10px;
  > span {
    font-size: 0.9em;
    color: var(--color-text-maxcontrast);
  }
  input[type='text'],
  input[type='number'],
  select,
  textarea {
    width: 100%;
    padding: 8px 10px;
    border-radius: var(--border-radius-element, 8px);
    border: 2px solid var(--color-border-dark);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font: inherit;
  }
  .row {
    display: flex;
    gap: 8px;
    input[type='number'] {
      width: 90px;
    }
  }
}
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  padding: 6px 8px;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius-element, 8px);
  input {
    flex: 1;
    min-width: 160px;
    border: none;
    background: transparent;
    color: var(--color-main-text);
    padding: 4px;
    font: inherit;
    outline: none;
  }
  .chip {
    background: var(--color-background-dark);
    border-radius: 12px;
    padding: 2px 6px 2px 10px;
    font-size: 0.9em;
    &.user {
      background: var(--color-primary-element-light);
    }
    button {
      border: none;
      background: transparent;
      cursor: pointer;
      padding: 0 4px;
      font-size: 1.1em;
      line-height: 1;
    }
  }
}
.suggest {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  margin-top: 4px;
  overflow: hidden;
  button {
    display: flex;
    align-items: center;
    gap: 6px;
    text-align: left;
    border: none;
    background: var(--color-main-background);
    padding: 6px 10px;
    cursor: pointer;
    small {
      color: var(--color-text-maxcontrast);
    }
    &:hover {
      background: var(--color-background-hover);
    }
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
