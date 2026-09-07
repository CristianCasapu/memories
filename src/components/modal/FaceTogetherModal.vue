<template>
  <Modal ref="modal" @close="cleanup" size="large" v-if="show">
    <template #title>
      {{ t('memories', 'Photos of {name} together with …', { name: firstName }) }}
    </template>

    <div class="outer">
      <FaceList @select="clickFace" />
    </div>

    <template #buttons>
      <NcButton @click="close" class="button" type="error">
        {{ t('memories', 'Cancel') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';
import FaceList from './FaceList.vue';

import type { IFace } from '@typings';

/**
 * Pick another person; the timeline then shows only the photos in which every
 * listed person appears (route name "A|B|C", handled by the Recognize backend).
 */
export default defineComponent({
  name: 'FaceTogetherModal',
  components: {
    NcButton,
    Modal,
    FaceList,
  },

  mixins: [ModalMixin],

  emits: [],

  computed: {
    firstName(): string {
      return String(this.$route.params.name || '').split('|')[0];
    },
  },

  methods: {
    open() {
      this.show = true;
    },

    cleanup() {
      this.show = false;
    },

    async clickFace(face: IFace) {
      const current = String(this.$route.params.name || '')
        .split('|')
        .filter(Boolean);
      const other = String(face.name || face.cluster_id);
      if (!other || current.includes(other)) return;

      // Closing pops the modal's history entry, so it has to finish before we navigate;
      // otherwise that pop lands after our push and takes the user back where they were.
      await this.close();
      this.$router.push({
        name: this.$route.name as string,
        params: { user: this.$route.params.user, name: [...current, other].join('|') },
      });
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}
</style>
