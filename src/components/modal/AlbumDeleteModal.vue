<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ owned ? t('memories', 'Remove Album') : t('memories', 'Leave Album') }}
    </template>

    <span>
      {{
        owned
          ? t('memories', 'Are you sure you want to permanently remove album "{name}"?', { name })
          : t('memories', 'Are you sure you want to leave the shared album "{name}"?', { name })
      }}
    </span>

    <template #buttons>
      <NcButton @click="save" class="button" variant="error">
        {{ t('memories', 'Delete') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

import { showError } from '@nextcloud/dialogs';
import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import * as utils from '@services/utils';
import * as dav from '@services/dav';
import client from '@services/dav/client';

export default defineComponent({
  name: 'AlbumDeleteModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
  },

  mixins: [ModalMixin],

  emits: [],

  data: () => ({
    overrideUser: null as string | null,
    overrideName: null as string | null,
  }),

  created() {
    _m.modals.albumDelete = this.open;
  },

  computed: {
    user() {
      return this.overrideUser ?? this.$route.params.user;
    },

    name() {
      return this.overrideName ?? this.$route.params.name;
    },

    owned() {
      return this.user === utils.uid;
    },
  },

  methods: {
    /** From the album page (no arguments) or from an album tile (user + name) */
    open(user?: string, name?: string) {
      this.overrideUser = typeof user === 'string' ? user : null;
      this.overrideName = typeof name === 'string' ? name : null;
      this.show = true;
    },

    cleanup() {
      this.show = false;
      this.overrideUser = null;
      this.overrideName = null;
    },

    async save() {
      try {
        await client.deleteFile(dav.getAlbumPath(this.user, this.name));
        if (this.$route.params.name) {
          this.$router.push({ name: 'albums' });
        } else {
          utils.bus.emit('memories:clusters:refresh', null);
        }
        this.close();
      } catch (error) {
        console.log(error);
        showError(this.t('memories', 'Failed to delete {name}.', { name: this.name }));
      }
    },
  },
});
</script>
