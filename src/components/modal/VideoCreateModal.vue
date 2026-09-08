<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ t('memories', 'Create a video from {n} photos', { n: fileIds.length }) }}
    </template>

    <div class="fields">
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
        {{ t('memories', 'The mood cannot be read from the photos (natural-language search is off); "Automatic" uses calm music.') }}
      </p>
      <p class="hint">{{ t('memories', 'About {s} seconds; saved as an MP4 next to the first photo.', { s: Math.round(fileIds.length * secondsPerPhoto) }) }}</p>
    </div>

    <template #buttons>
      <NcButton @click="close" class="button">{{ t('memories', 'Cancel') }}</NcButton>
      <NcButton @click="create" class="button" variant="primary" :disabled="busy">
        {{ busy ? t('memories', 'Creating …') : t('memories', 'Create the video') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import NcButton from '@nextcloud/vue/components/NcButton';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import * as dav from '@services/dav';
import * as utils from '@services/utils';
import { API } from '@services/API';

type IMusicStatus = { enabled: boolean; providers: string[]; moodDetection: boolean; moods: Record<string, string> };

/** Options for a video made from photos: pace and background music (mood picked from the pictures). */
export default defineComponent({
  name: 'VideoCreateModal',
  components: { NcButton, Modal },
  mixins: [ModalMixin],
  emits: [],

  data: () => ({
    fileIds: [] as number[],
    secondsPerPhoto: 0.5,
    music: 'auto',
    busy: false,
    status: { enabled: false, providers: [], moodDetection: false, moods: {} } as IMusicStatus,
  }),

  created() {
    _m.modals.createVideo = this.open;
  },

  methods: {
    async open(fileIds: number[]) {
      this.fileIds = fileIds;
      this.busy = false;
      // a burst (many frames) wants a fast pace, a hand-picked selection a slow one
      this.secondsPerPhoto = fileIds.length >= 8 ? 0.25 : 2;
      this.show = true;
      try {
        this.status = (await axios.get<IMusicStatus>(API.MUSIC_STATUS())).data;
        if (!this.status.enabled) this.music = 'none';
      } catch (e) {
        console.warn('music status', e);
      }
    },

    cleanup() {
      this.show = false;
    },

    async create() {
      if (this.busy) return;
      this.busy = true;
      try {
        const fileid = await dav.createBurstVideo(this.fileIds, 1 / this.secondsPerPhoto, this.music);
        if (fileid) {
          utils.bus.emit('memories:timeline:soft-refresh', null);
          await this.close();
        }
      } finally {
        this.busy = false;
      }
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
</style>
