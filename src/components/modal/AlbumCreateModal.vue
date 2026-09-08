<template>
  <Modal ref="modal" @close="cleanup" size="normal" v-if="show">
    <template #title>
      <template v-if="!album">
        {{ t('memories', 'Create new album') }}
      </template>
      <template v-else>
        {{ t('memories', 'Edit album details') }}
      </template>
    </template>

    <div class="outer">
      <AlbumForm :album="album" :display-back-button="false" :title="t('memories', 'New album')" @done="done" />
    </div>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { showError } from '@nextcloud/dialogs';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';
import AlbumForm from './AlbumForm.vue';

import * as utils from '@services/utils';
import * as dav from '@services/dav';

export default defineComponent({
  name: 'AlbumCreateModal',
  components: {
    Modal,
    AlbumForm,
  },

  mixins: [ModalMixin],

  emits: [],

  data: () => ({
    fromList: false,
    album: null as any,
  }),

  created() {
    _m.modals.albumEdit = (user: string, name: string) => this.open(true, user, name);
  },

  methods: {
    /**
     * Open the modal
     * @param edit If true, the modal will be opened in edit mode
     */
    async open(edit: boolean, user?: string, name?: string) {
      this.fromList = typeof name === 'string';
      if (edit) {
        try {
          this.album = await dav.getAlbum(
            user ?? String(this.$route.params.user),
            name ?? String(this.$route.params.name),
          );
        } catch (e) {
          console.error(e);
          showError(this.t('memories', 'Could not load the selected album'));
          return;
        }
      } else {
        this.album = null;
      }

      this.show = true;
    },

    cleanup() {
      this.show = false;
    },

    async done({ album }: { album: { basename: string; filename: string } }) {
      // close modal first to pop fragments
      await this.close();

      // edited from the album list: stay there and refresh the tiles
      if (this.fromList) {
        utils.bus.emit('memories:clusters:refresh', null);
        return;
      }
      // navigate to album if name changed
      if (!this.album || album.basename !== this.album.basename) {
        const user = album.filename.split('/')[2];
        const name = album.basename;
        const route = { name: 'albums', params: { user, name } };

        if (!this.album) {
          await this.$router.push(route);
        } else {
          await this.$router.replace(route);
        }
      } else {
        // refresh timeline for metadata changes
        utils.bus.emit('memories:timeline:soft-refresh', null);
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}

.info-pad {
  margin-top: 6px;
}
</style>
