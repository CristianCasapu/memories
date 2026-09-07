<template>
  <Modal ref="modal" @close="cleanup" size="large" v-if="show">
    <template #title>
      {{ t('memories', 'People in this photo') }}
    </template>

    <div class="outer">
      <div
        class="stage"
        v-if="photo"
        ref="stage"
        :class="{ drawing }"
        @mousedown="startDraw"
        @mousemove="moveDraw"
        @mouseup="endDraw"
        @mouseleave="endDraw"
        @touchstart="startDraw"
        @touchmove.prevent="moveDraw"
        @touchend="endDraw"
      >
        <img :src="previewUrl" class="image" draggable="false" @load="loaded = true" />
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
        <div v-if="draft" class="box draft" :style="boxStyle(draft)">
          <span class="label">{{ t('memories', 'New face') }}</span>
        </div>
      </div>

      <div class="status" v-if="loading">{{ t('memories', 'Loading …') }}</div>
      <div class="status" v-else>
        <template v-if="drawing && !draft">{{ t('memories', 'Drag a box around the face you want to add') }}</template>
        <template v-else-if="draft">{{ t('memories', 'Name this person, then add the face') }}</template>
        <template v-else-if="!faces.length">
          {{ t('memories', 'No faces were detected in this photo (or it has not been scanned yet)') }}
        </template>
        <template v-else-if="!selected">{{ t('memories', 'Click a face to name it') }}</template>

        <NcButton v-if="!drawing && !draft" type="secondary" @click="drawing = true">
          <template #icon> <PlusIcon :size="18" /> </template>
          {{ t('memories', 'Add a face') }}
        </NcButton>
        <NcButton v-if="drawing || draft" type="tertiary" @click="cancelDraw">{{ t('memories', 'Cancel') }}</NcButton>
        <NcButton v-if="ignoredCount && !drawing" @click="unignore" type="tertiary">
          {{ t('memories', 'Restore ignored faces') }}
        </NcButton>
      </div>

      <div class="editor" v-if="draft">
        <NcTextField
          :value.sync="draftName"
          :label="t('memories', 'Name')"
          :placeholder="t('memories', 'Existing person or a new name')"
          list="memories-face-names"
          @keydown.enter="addFace"
        />
        <div class="buttons">
          <NcButton type="primary" :disabled="busy" @click="addFace">
            {{ busy ? t('memories', 'Looking for the face …') : t('memories', 'Add face') }}
          </NcButton>
          <NcButton type="tertiary" :disabled="busy" @click="cancelDraw">{{ t('memories', 'Cancel') }}</NcButton>
        </div>
        <span class="hint">{{ t('memories', 'The face is detected inside the box so it can be recognized in other photos too. This takes a few seconds.') }}</span>
      </div>

      <div class="editor" v-if="selected && !draft">
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
import PlusIcon from 'vue-material-design-icons/Plus.vue';
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
    PlusIcon,
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
    drawing: false,
    draft: null as null | { x: number; y: number; width: number; height: number },
    draftName: '',
    dragStart: null as null | { x: number; y: number },
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
      this.cancelDraw();
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

    /* ---- drawing a new face ---- */

    relativePoint(event: MouseEvent | TouchEvent): { x: number; y: number } | null {
      const img = (this.$refs.stage as HTMLElement)?.querySelector('img');
      if (!img) return null;
      const rect = img.getBoundingClientRect();
      const point = 'touches' in event ? event.touches[0] ?? (event as TouchEvent).changedTouches[0] : (event as MouseEvent);
      if (!point) return null;
      return {
        x: Math.min(1, Math.max(0, (point.clientX - rect.left) / rect.width)),
        y: Math.min(1, Math.max(0, (point.clientY - rect.top) / rect.height)),
      };
    },

    startDraw(event: MouseEvent | TouchEvent) {
      if (!this.drawing) return;
      const p = this.relativePoint(event);
      if (!p) return;
      event.preventDefault();
      this.dragStart = p;
      this.draft = { x: p.x, y: p.y, width: 0, height: 0 };
    },

    moveDraw(event: MouseEvent | TouchEvent) {
      if (!this.drawing || !this.dragStart) return;
      const p = this.relativePoint(event);
      if (!p) return;
      this.draft = {
        x: Math.min(this.dragStart.x, p.x),
        y: Math.min(this.dragStart.y, p.y),
        width: Math.abs(p.x - this.dragStart.x),
        height: Math.abs(p.y - this.dragStart.y),
      };
    },

    endDraw() {
      if (!this.dragStart) return;
      this.dragStart = null;
      if (!this.draft || this.draft.width < 0.01 || this.draft.height < 0.01) {
        this.draft = null;
        return;
      }
      this.drawing = false;
    },

    cancelDraw() {
      this.drawing = false;
      this.draft = null;
      this.dragStart = null;
      this.draftName = '';
    },

    /** Send the drawn box to Recognize: it finds the face inside it and computes its descriptor */
    async addFace() {
      if (!this.photo || !this.draft || this.busy) return;
      this.busy = true;
      try {
        const known = this.people.find((p) => p.name.toLowerCase() === this.draftName.trim().toLowerCase());
        const res = await axios.post(API.RECOGNIZE_FILE_FACES(this.photo.fileid), {
          x: this.draft.x,
          y: this.draft.y,
          width: this.draft.width,
          height: this.draft.height,
          cluster_id: known?.cluster_id ?? null,
          title: this.draftName.trim() || null,
        });
        this.changed = true;
        showSuccess(
          res.data.title
            ? this.t('memories', 'Face added to {name}', { name: res.data.title })
            : this.t('memories', 'Face added (not assigned to anyone yet)'),
        );
        if (res.data.created) this.people.push({ cluster_id: res.data.cluster_id, name: res.data.title });
        this.cancelDraw();
        await this.refresh();
      } catch (error: any) {
        console.error(error);
        showError(error?.response?.data?.message || this.t('memories', 'Could not add the face'));
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
    user-select: none;
  }

  &.drawing {
    cursor: crosshair;
    touch-action: none;
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
    &.draft {
      border: 2px dashed var(--color-primary-element, #0082c9);
      background: rgba(0, 130, 201, 0.15);
      pointer-events: none;
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
  flex-wrap: wrap;
  justify-content: center;
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

  .hint {
    color: var(--color-text-maxcontrast);
    font-size: 0.9em;
  }
}
</style>
