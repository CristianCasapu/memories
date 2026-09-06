<template>
  <div class="face-top-matter">
    <NcActions v-if="name">
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <div class="name">
      <div :class="{ 'rename-hover': isReal }" @click="rename">
        {{ displayName }}
      </div>
    </div>

    <div class="right-actions">
      <NcActions :inline="0">
        <!-- root view (not cluster or unassigned) -->
        <template v-if="!name && routeIsRecognize && !routeIsRecognizeUnassigned">
          <NcActionButton :aria-label="t('memories', 'Unassigned faces')" @click="openUnassigned" close-after-click>
            {{ t('memories', 'Unassigned faces') }}
            <template #icon> <UnassignedIcon :size="20" /> </template>
          </NcActionButton>
        </template>

        <!-- real cluster -->
        <template v-if="isReal">
          <NcActionButton :aria-label="t('memories', 'Rename person')" @click="rename" close-after-click>
            {{ t('memories', 'Rename person') }}
            <template #icon> <EditIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Merge with different person')"
            @click="refs.mergeModal.open()"
            close-after-click
          >
            {{ t('memories', 'Merge with different person') }}
            <template #icon> <MergeIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionButton
            v-if="routeIsRecognize"
            :aria-label="t('memories', 'Find this person in more photos')"
            :disabled="finding"
            @click="findMore()"
            close-after-click
          >
            {{ t('memories', 'Find this person in more photos') }}
            <template #icon> <FindIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionCheckbox
            :aria-label="t('memories', 'Mark person in preview')"
            :checked.sync="config.show_face_rect"
            @change="changeShowFaceRect"
          >
            {{ t('memories', 'Mark person in preview') }}
          </NcActionCheckbox>
          <NcActionButton
            :aria-label="t('memories', 'Remove person')"
            @click="refs.deleteModal.open()"
            close-after-click
          >
            {{ t('memories', 'Remove person') }}
            <template #icon> <DeleteIcon :size="20" /> </template>
          </NcActionButton>
        </template>
      </NcActions>
    </div>

    <FaceEditModal ref="editModal" />
    <FaceDeleteModal ref="deleteModal" />
    <FaceMergeModal ref="mergeModal" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox.js';

import FaceEditModal from '@components/modal/FaceEditModal.vue';
import FaceDeleteModal from '@components/modal/FaceDeleteModal.vue';
import FaceMergeModal from '@components/modal/FaceMergeModal.vue';

import * as utils from '@services/utils';
import { API } from '@services/API';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError, showSuccess } from '@nextcloud/dialogs';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/Close.vue';
import MergeIcon from 'vue-material-design-icons/Merge.vue';
import UnassignedIcon from 'vue-material-design-icons/AccountQuestion.vue';
import FindIcon from 'vue-material-design-icons/AccountSearch.vue';

export default defineComponent({
  name: 'FaceTopMatter',
  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    FaceEditModal,
    FaceDeleteModal,
    FaceMergeModal,
    BackIcon,
    EditIcon,
    DeleteIcon,
    MergeIcon,
    UnassignedIcon,
    FindIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    finding: false,
  }),

  computed: {
    refs() {
      return this.$refs as {
        editModal: InstanceType<typeof FaceEditModal>;
        deleteModal: InstanceType<typeof FaceDeleteModal>;
        mergeModal: InstanceType<typeof FaceMergeModal>;
      };
    },

    name() {
      return this.$route.params.name || '';
    },

    isReal() {
      return this.name && this.name !== this.c.FACE_NULL;
    },

    displayName() {
      if (this.routeIsRecognizeUnassigned) {
        return this.t('memories', 'Unassigned faces');
      } else if (!this.name) {
        return this.t('memories', 'People');
      } else if (utils.isNumber(this.name)) {
        return this.t('memories', 'Unnamed person');
      }
      return this.name;
    },
  },

  methods: {
    back() {
      this.$router.go(-1);
    },

    rename() {
      if (this.isReal) this.refs.editModal.open();
    },

    /**
     * Ask Recognize to pull every sufficiently similar unassigned / unnamed face into this
     * person right now (the fork's /api/faces/{id}/find endpoint), then refresh the timeline.
     */
    async findMore() {
      if (this.finding) return;
      this.finding = true;
      try {
        // the route name is the cluster id for unnamed people; look the id up for named ones
        let clusterId = Number(this.name);
        if (!Number.isInteger(clusterId)) {
          const faces = (await axios.get(API.FACE_LIST('recognize'))).data as { cluster_id: number; name: string }[];
          clusterId = faces.find((f) => f.name === this.name)?.cluster_id ?? NaN;
        }
        if (!Number.isInteger(clusterId)) throw new Error('unknown cluster');
        const res = await axios.post(generateUrl(`/apps/recognize/api/faces/${clusterId}/find`), {});
        const n = res.data.assigned ?? 0;
        showSuccess(
          n
            ? this.t('memories', 'Found this person in {n} more photo(s)', { n })
            : this.t('memories', 'No additional photos of this person were found'),
        );
        if (n) utils.bus.emit('memories:timeline:hard-refresh', null);
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Searching for this person failed (is the CristianCasapu Recognize fork installed?)'));
      } finally {
        this.finding = false;
      }
    },

    openUnassigned() {
      this.$router.push({
        name: this.$route.name as string,
        params: {
          user: utils.uid as string,
          name: this.c.FACE_NULL,
        },
      });
    },

    changeShowFaceRect() {
      this.updateSetting('show_face_rect');
      utils.bus.emit('memories:timeline:hard-refresh', null);
    },
  },
});
</script>
