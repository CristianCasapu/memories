<template>
  <Modal ref="modal" @close="cleanup" v-if="show" size="normal">
    <template #title>
      {{ t('memories', 'Create a story') }}
    </template>

    <div class="story-create">
      <NcTextField
        type="text"
        name="title"
        autofocus="true"
        v-model="title"
        :label="t('memories', 'Title')"
        :label-visible="true"
        :placeholder="t('memories', 'Title of the story')"
        @keydown.enter="save()"
      />

      <NcCheckboxRadioSwitch v-model="best" type="switch">
        {{ t('memories', 'Keep the best photos only') }}
      </NcCheckboxRadioSwitch>

      <p class="hint">
        {{
          n(
            'memories',
            '{n} photo, shown full screen.',
            'The {n} photos follow one another, full screen, the way a story does on a phone.',
            fileIds.length,
            { n: fileIds.length },
          )
        }}
      </p>
    </div>

    <template #buttons>
      <NcButton @click="save" class="button" variant="primary" :disabled="saving">
        {{ t('memories', 'Create') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));
const NcCheckboxRadioSwitch = defineAsyncComponent(() => import('@nextcloud/vue/components/NcCheckboxRadioSwitch'));

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import { API } from '@services/API';

/** Turn the photos the person picked into a story. */
export default defineComponent({
  name: 'StoryCreateModal',
  components: {
    NcButton,
    NcTextField,
    NcCheckboxRadioSwitch,
    Modal,
  },

  mixins: [ModalMixin],

  emits: [],

  data: () => ({
    fileIds: [] as number[],
    title: '',
    best: false,
    saving: false,
  }),

  created() {
    _m.modals.createStory = this.open;
  },

  methods: {
    open(fileIds: number[]) {
      this.fileIds = fileIds;
      this.title = '';
      this.best = fileIds.length > 24;
      this.saving = false;
      this.show = true;
    },

    cleanup() {
      this.show = false;
      this.fileIds = [];
    },

    async save() {
      if (this.saving) return;
      this.saving = true;

      try {
        const res = await axios.post<{ id: number }>(API.STORIES(), {
          fileids: this.fileIds,
          title: this.title.trim() || this.t('memories', 'My story'),
          mode: this.best ? 'best' : 'all',
        });
        this.close();
        showSuccess(this.t('memories', 'Story created.'));
        this.$router.push({ name: 'stories', query: { play: String(res.data.id) } });
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Failed to create the story.'));
        this.saving = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.story-create {
  display: flex;
  flex-direction: column;
  gap: 12px;

  .hint {
    font-size: 0.9em;
    color: var(--color-text-maxcontrast);
  }
}
</style>
