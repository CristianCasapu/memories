<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ t('memories', 'Share as album') }}
    </template>

    <div class="outer">
      <div class="hint" v-if="loading">{{ t('memories', 'Collecting photos …') }}</div>
      <div class="hint" v-else>
        {{ n('memories', '%n photo will be added to the album', '%n photos will be added to the album', photos.length) }}
      </div>

      <NcTextField
        :value.sync="name"
        :label="t('memories', 'Name of the album')"
        :placeholder="t('memories', 'Name of the album')"
        autofocus
        @keydown.enter="submit"
      />

      <NcProgressBar v-if="processingTotal > 0" :value="Math.round((processing * 100) / processingTotal)" />
    </div>

    <template #buttons>
      <NcButton @click="close" class="button" type="secondary">
        {{ t('memories', 'Cancel') }}
      </NcButton>
      <NcButton @click="submit" class="button" type="primary" :disabled="busy || loading || !name.trim() || !photos.length">
        {{ t('memories', 'Create album and share') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { showError, showSuccess } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');
const NcProgressBar = () => import('@nextcloud/vue/dist/Components/NcProgressBar.js');

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import * as dav from '@services/dav';
import * as utils from '@services/utils';

import type { IPhoto } from '@typings';

type PhotoSource = IPhoto[] | (() => Promise<IPhoto[]>);

/**
 * Turn the current view or a selection into a Photos album and open its share dialog.
 */
export default defineComponent({
  name: 'ShareAsAlbumModal',
  components: {
    NcButton,
    NcTextField,
    NcProgressBar,
    Modal,
  },

  mixins: [ModalMixin],

  emits: [],

  data: () => ({
    photos: [] as IPhoto[],
    name: '',
    loading: false,
    busy: false,
    processing: 0,
    processingTotal: 0,
  }),

  created() {
    _m.modals.shareAsAlbum = this.open;
  },

  methods: {
    async open(source: PhotoSource, defaultName: string = '') {
      this.name = defaultName;
      this.photos = [];
      this.processing = 0;
      this.processingTotal = 0;
      this.show = true;
      this.loading = true;
      try {
        this.photos = typeof source === 'function' ? await source() : source;
        if (!this.photos.length) {
          showError(this.t('memories', 'There are no photos to share'));
          this.close();
        }
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Could not collect the photos of this view'));
        this.close();
      } finally {
        this.loading = false;
      }
    },

    cleanup() {
      this.show = false;
      this.photos = [];
    },

    async submit() {
      const name = this.name.trim().replace(/[\\/]/g, '-');
      if (!name || this.busy || !this.photos.length) return;
      this.busy = true;
      try {
        try {
          await dav.createAlbum(name, { rethrow: true });
        } catch (error: any) {
          // 405: the album already exists → the photos are added to it
          if (error?.response?.status !== 405) throw error;
        }
        this.processingTotal = this.photos.length;
        this.processing = 0;
        let added = 0;
        for await (const ids of dav.addToAlbum(utils.uid as string, name, this.photos)) {
          this.processing += ids.length;
          added += ids.filter(Boolean).length;
        }
        showSuccess(this.t('memories', 'Album "{name}" ready with {n} photos', { name, n: added }));
        this.show = false;
        this.$router.push({
          name: _m.routes.Albums.name,
          params: { user: utils.uid as string, name },
          query: { share: '1' },
        });
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Could not create the album'));
      } finally {
        this.busy = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 10px;
  display: flex;
  flex-direction: column;
  gap: 10px;

  .hint {
    color: var(--color-text-maxcontrast);
  }
}
</style>
