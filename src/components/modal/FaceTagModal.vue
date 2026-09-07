<template>
  <Modal ref="modal" @close="cleanup" size="large" v-if="show">
    <template #title>
      {{ t('memories', 'People in this photo') }}
    </template>

    <div class="outer">
      <div class="stage" v-if="photo">
        <img :src="previewUrl" class="image" @load="loaded = true" />
        <div
          v-for="face in faces"
          :key="face.id"
          class="box"
          :class="{ selected: selected?.id === face.id, named: !!face.cluster_id, ignored: face.ignored }"
          :style="boxStyle(face)"
          :title="labelOf(face)"
          @click="select(face)"
        >
          <span class="label">{{ labelOf(face) }}</span>
        </div>
      </div>

      <div class="status" v-if="loading">{{ t('memories', 'Loading …') }}</div>
      <div class="status" v-else-if="!faces.length">
        {{ t('memories', 'No faces were detected in this photo (or it has not been scanned yet)') }}
        <NcButton v-if="ignoredCount" @click="unignore" type="tertiary">{{ t('memories', 'Restore ignored faces') }}</NcButton>
      </div>
      <div class="status" v-else-if="!selected">{{ t('memories', 'Click a face to name it') }}</div>

      <div class="editor" v-if="selected">
        <NcTextField
          :value.sync="name"
          :label="t('memories', 'Name')"
          :placeholder="t('memories', 'Existing person or a new name')"
          list="memories-face-names"
          @keydown.enter="assign"
        />
        <datalist id="memories-face-names">
          <option v-for="p in people" :key="p.cluster_id" :value="p.name" />
        </datalist>
        <div class="buttons">
          <NcButton type="primary" :disabled="busy || !name.trim()" @click="assign">
            {{ t('memories', 'Assign') }}
          </NcButton>
          <NcButton v-if="selected.cluster_id" type="secondary" :disabled="busy" @click="detach">
            {{ t('memories', 'Unassign') }}
          </NcButton>
          <NcButton type="tertiary" :disabled="busy" @click="ignore">
            {{ t('memories', 'Not a face') }}
          </NcButton>
        </div>
      </div>
    </div>

    <template #buttons>
      <NcButton @click="close" class="button" type="secondary">
        {{ t('memories', 'Close') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import { API } from '@services/API';
import * as utils from '@services/utils';

import type { IPhoto } from '@typings';

type IFaceBox = {
  id: number;
  x: number;
  y: number;
  width: number;
  height: number;
  cluster_id: number | null;
  title: string | null;
  ignored: boolean;
};

/**
 * Manual tagging: the faces Recognize found in the photo, click one and give it a name
 * (existing person or a new one). Uses the CristianCasapu Recognize fork API.
 */
export default defineComponent({
  name: 'FaceTagModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
  },

  mixins: [ModalMixin],

  emits: [],

  data: () => ({
    photo: null as IPhoto | null,
    faces: [] as IFaceBox[],
    people: [] as { cluster_id: number; name: string }[],
    selected: null as IFaceBox | null,
    name: '',
    loading: false,
    loaded: false,
    busy: false,
    changed: false,
    ignoredCount: 0,
  }),

  mounted() {
    _m.modals.tagFaces = this.open;
  },

  computed: {
    previewUrl(): string {
      return this.photo ? utils.getPreviewUrl({ photo: this.photo, msize: 1024 }) : '';
    },
  },

  methods: {
    async open(photo: IPhoto) {
      this.photo = photo;
      this.faces = [];
      this.selected = null;
      this.name = '';
      this.changed = false;
      this.show = true;
      await this.refresh();
    },

    cleanup() {
      this.show = false;
      if (this.changed) utils.bus.emit('memories:timeline:soft-refresh', null);
    },

    async refresh() {
      if (!this.photo) return;
      this.loading = true;
      try {
        const [faces, people] = await Promise.all([
          axios.get(API.RECOGNIZE_FILE_FACES(this.photo.fileid)),
          axios.get(API.FACE_LIST('recognize')),
        ]);
        const all = faces.data.faces as IFaceBox[];
        this.ignoredCount = all.filter((f) => f.ignored).length;
        this.faces = all.filter((f) => !f.ignored);
        this.people = (people.data as { cluster_id: number; name: string }[])
          .filter((p) => p.name && !/^\d+$/.test(String(p.name)))
          .sort((a, b) => a.name.localeCompare(b.name));
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Could not load the faces (is the CristianCasapu Recognize fork installed?)'));
      } finally {
        this.loading = false;
      }
    },

    select(face: IFaceBox) {
      this.selected = face;
      this.name = face.title || '';
    },

    labelOf(face: IFaceBox): string {
      if (face.cluster_id) return face.title || this.t('memories', 'Unnamed person');
      return '?';
    },

    boxStyle(face: IFaceBox) {
      return {
        left: `${face.x * 100}%`,
        top: `${face.y * 100}%`,
        width: `${face.width * 100}%`,
        height: `${face.height * 100}%`,
      };
    },

    async assign() {
      if (!this.selected || !this.name.trim() || this.busy) return;
      this.busy = true;
      try {
        const known = this.people.find((p) => p.name.toLowerCase() === this.name.trim().toLowerCase());
        const res = await axios.post(API.RECOGNIZE_DETECTION(this.selected.id, 'assign'), {
          cluster_id: known?.cluster_id ?? null,
          title: this.name.trim(),
        });
        this.selected.cluster_id = res.data.cluster_id;
        this.selected.title = res.data.title;
        this.changed = true;
        showSuccess(
          res.data.created
            ? this.t('memories', 'New person "{name}" created', { name: res.data.title })
            : this.t('memories', 'Face assigned to {name}', { name: res.data.title }),
        );
        if (res.data.created) this.people.push({ cluster_id: res.data.cluster_id, name: res.data.title });
      } catch (error: any) {
        console.error(error);
        showError(error?.response?.data?.message || this.t('memories', 'Could not assign the face'));
      } finally {
        this.busy = false;
      }
    },

    async detach() {
      if (!this.selected || this.busy) return;
      this.busy = true;
      try {
        await axios.post(API.RECOGNIZE_DETECTION(this.selected.id, 'detach'));
        this.selected.cluster_id = null;
        this.selected.title = null;
        this.name = '';
        this.changed = true;
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Could not unassign the face'));
      } finally {
        this.busy = false;
      }
    },

    async ignore() {
      if (!this.selected || this.busy) return;
      this.busy = true;
      try {
        await axios.post(API.RECOGNIZE_DETECTION(this.selected.id, 'ignore'));
        this.faces = this.faces.filter((f) => f.id !== this.selected!.id);
        this.ignoredCount++;
        this.selected = null;
        this.changed = true;
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Could not ignore the face'));
      } finally {
        this.busy = false;
      }
    },

    async unignore() {
      if (!this.photo) return;
      try {
        await axios.post(API.RECOGNIZE_FILE_FACES_UNIGNORE(this.photo.fileid));
        await this.refresh();
      } catch (error) {
        console.error(error);
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
  align-items: center;
  gap: 12px;
}

.stage {
  position: relative;
  display: inline-block;
  max-width: 100%;
  line-height: 0;

  .image {
    max-width: 100%;
    max-height: 65vh;
    display: block;
  }

  .box {
    position: absolute;
    border: 2px solid rgba(255, 255, 255, 0.85);
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.5);
    border-radius: 3px;
    cursor: pointer;
    line-height: normal;

    &.named {
      border-color: #2ecc71;
    }
    &.selected {
      border-color: var(--color-primary-element, #0082c9);
      border-width: 3px;
    }

    .label {
      position: absolute;
      left: -2px;
      top: 100%;
      margin-top: 2px;
      font-size: 11px;
      padding: 1px 5px;
      border-radius: 3px;
      background: rgba(0, 0, 0, 0.7);
      color: #fff;
      white-space: nowrap;
      max-width: 160px;
      overflow: hidden;
      text-overflow: ellipsis;
    }
  }
}

.status {
  color: var(--color-text-maxcontrast);
  display: flex;
  gap: 8px;
  align-items: center;
}

.editor {
  width: 100%;
  max-width: 480px;
  display: flex;
  flex-direction: column;
  gap: 8px;

  .buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
}
</style>
