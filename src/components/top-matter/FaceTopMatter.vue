<template>
  <div class="face-top-matter">
    <NcActions>
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
      <NcActions :inline="1">
        <NcActionButton
          v-if="name"
          :aria-label="isReal ? t('memories', 'Share (album of this person)') : t('memories', 'Share as album')"
          @click="shareThis()"
          close-after-click
        >
          {{ isReal ? t('memories', 'Share (album of this person)') : t('memories', 'Share as album') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>
        <!-- root view (not cluster or unassigned) -->
        <template v-if="!name && routeIsRecognize && !routeIsRecognizeUnassigned">
          <NcActionButton
            :aria-label="t('memories', 'Review unnamed people')"
            @click="$router.push({ name: 'people-review' })"
            close-after-click
          >
            {{ t('memories', 'Review unnamed people') }}
            <template #icon> <ReviewIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionButton :aria-label="t('memories', 'Unassigned faces')" @click="openUnassigned" close-after-click>
            {{ t('memories', 'Unassigned faces') }}
            <template #icon> <UnassignedIcon :size="20" /> </template>
          </NcActionButton>
        </template>

        <!-- real cluster -->
        <template v-if="isTogether">
          <NcActionButton
            :aria-label="t('memories', 'Add another person')"
            @click="refs().togetherModal.open()"
            close-after-click
          >
            {{ t('memories', 'Add another person') }}
            <template #icon> <TogetherIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Show only {name}', { name: firstName })"
            @click="showOnlyFirst"
            close-after-click
          >
            {{ t('memories', 'Show only {name}', { name: firstName }) }}
            <template #icon> <BackIcon :size="20" /> </template>
          </NcActionButton>
        </template>
        <!-- any person (named or not): best photos of the person first -->
        <NcActionCheckbox
          v-if="name && routeIsRecognize && !routeIsRecognizeUnassigned"
          :aria-label="t('memories', 'Best photos first')"
          :model-value="sortByProminence"
          @change="toggleProminence"
        >
          {{ t('memories', 'Best photos first (large, sharp, well lit, facing the camera)') }}
        </NcActionCheckbox>
        <!-- any person: only the photos taken of them, not the ones they walked into -->
        <NcActionCheckbox
          v-if="name && routeIsRecognize && !routeIsRecognizeUnassigned"
          :aria-label="t('memories', 'Only in the foreground')"
          :model-value="onlySubjects"
          @change="toggleSubjects"
        >
          {{ t('memories', 'Only where they are in the foreground (in focus, not in the background)') }}
        </NcActionCheckbox>
        <template v-if="isReal">
          <NcActionButton
            v-if="routeIsRecognize"
            :aria-label="t('memories', 'Together with …')"
            @click="refs().togetherModal.open()"
            close-after-click
          >
            {{ t('memories', 'Together with …') }}
            <template #icon> <TogetherIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionButton :aria-label="t('memories', 'Rename person')" @click="rename" close-after-click>
            {{ t('memories', 'Rename person') }}
            <template #icon> <EditIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Merge with different person')"
            @click="refs().mergeModal.open()"
            close-after-click
          >
            {{ t('memories', 'Merge with different person') }}
            <template #icon> <MergeIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionButton
            v-if="routeIsRecognize && personAlbum"
            :aria-label="t('memories', 'Open the album of this person')"
            @click="openPersonAlbum()"
            close-after-click
          >
            {{ t('memories', 'Album of this person: {name}', { name: personAlbum.name }) }}
            <template #icon> <AlbumIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionButton
            v-else-if="routeIsRecognize && personAlbumAvailable"
            :aria-label="t('memories', 'Create an album of this person')"
            :disabled="finding"
            @click="createPersonAlbum()"
            close-after-click
          >
            {{ t('memories', 'Create an album of this person (kept up to date, shareable)') }}
            <template #icon> <AlbumIcon :size="20" /> </template>
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
            :model-value="config.show_face_rect"
            @change="changeShowFaceRect"
          >
            {{ t('memories', 'Mark person in preview') }}
          </NcActionCheckbox>
          <NcActionButton
            :aria-label="t('memories', 'Remove person')"
            @click="refs().deleteModal.open()"
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
    <FaceTogetherModal ref="togetherModal" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox';

import FaceEditModal from '@components/modal/FaceEditModal.vue';
import FaceDeleteModal from '@components/modal/FaceDeleteModal.vue';
import FaceMergeModal from '@components/modal/FaceMergeModal.vue';
import FaceTogetherModal from '@components/modal/FaceTogetherModal.vue';

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
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import TogetherIcon from 'vue-material-design-icons/AccountMultiple.vue';
import ReviewIcon from 'vue-material-design-icons/AccountCheck.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import { shareView } from '@services/view-share';

export default defineComponent({
  name: 'FaceTopMatter',
  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    FaceEditModal,
    FaceDeleteModal,
    FaceMergeModal,
    FaceTogetherModal,
    BackIcon,
    EditIcon,
    DeleteIcon,
    MergeIcon,
    UnassignedIcon,
    FindIcon,
    AlbumIcon,
    TogetherIcon,
    ReviewIcon,
    ShareIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    progress: null as null | {
      running: boolean;
      scanned: number;
      total: number;
      percent: number | null;
      etaSeconds: number | null;
      waitingForClustering: number;
      faces: number;
      clusters: number;
    },
    progressTimer: null as null | number,
    finding: false,
    personAlbum: null as { album_id: number; name: string } | null,
    personAlbumAvailable: false,
  }),

  watch: {
    '$route.params.name': {
      immediate: true,
      handler() {
        this.loadPersonAlbum();
      },
    },
  },

  mounted() {
    // admins see the live face-scan progress in the People header
    if (utils.isAdmin && this.routeIsRecognize) {
      this.loadProgress();
      this.progressTimer = window.setInterval(() => this.loadProgress(), 15000);
    }
  },

  beforeUnmount() {
    if (this.progressTimer) window.clearInterval(this.progressTimer);
  },

  computed: {
    name() {
      return this.$route.params.name?.toString() || '';
    },

    user() {
      return this.$route.params.user || '';
    },

    /** "A|B": photos in which all of these people appear */
    isTogether(): boolean {
      return String(this.name).includes('|');
    },

    firstName(): string {
      return String(this.name).split('|')[0];
    },

    /** ?sort=prominence: the photos of this person ordered by how prominent the face is */
    sortByProminence(): boolean {
      return this.$route.query.sort === 'prominence';
    },

    /** ?subjects=1: only the photos this person was photographed in */
    onlySubjects(): boolean {
      return this.$route.query.subjects === '1';
    },

    isReal() {
      return this.name && this.name !== this.c.FACE_NULL && !this.isTogether;
    },

    /** "scanning 4,571 / 6,000 (76 %)" while a face scan runs, or the clustering backlog */
    progressText(): string {
      const p = this.progress;
      if (!p) return '';
      if (p.running) {
        const eta =
          p.etaSeconds !== null
            ? ', ' + this.t('memories', '~{min} min left', { min: Math.ceil(p.etaSeconds / 60) })
            : '';
        return this.t('memories', 'scanning {scanned} / {total} photos ({percent} %){eta}', {
          scanned: p.scanned,
          total: p.total,
          percent: p.percent ?? 0,
          eta,
        });
      }
      if (p.waitingForClustering > 0) {
        return this.t('memories', '{n} faces waiting for clustering', { n: p.waitingForClustering });
      }
      return '';
    },

    displayName() {
      if (this.routeIsRecognizeUnassigned) {
        return this.t('memories', 'Unassigned faces');
      } else if (!this.name) {
        return this.progressText
          ? this.t('memories', 'People') + ' · ' + this.progressText
          : this.t('memories', 'People');
      } else if (this.isTogether) {
        return String(this.name)
          .split('|')
          .map((n) => (utils.isNumber(n) ? this.t('memories', 'Unnamed person') : n))
          .join(' + ');
      } else if (utils.isNumber(this.name)) {
        return this.t('memories', 'Unnamed person');
      }
      return this.name;
    },
  },

  methods: {
    toggleProminence() {
      const query = { ...this.$route.query };
      if (this.sortByProminence) {
        delete query.sort;
      } else {
        query.sort = 'prominence';
      }
      this.$router.replace({ ...this.$route, query } as any).catch(() => {});
    },

    toggleSubjects() {
      const query = { ...this.$route.query };
      if (this.onlySubjects) {
        delete query.subjects;
      } else {
        query.subjects = '1';
      }
      this.$router.replace({ ...this.$route, query } as any).catch(() => {});
    },

    refs() {
      return this.$refs as {
        editModal: InstanceType<typeof FaceEditModal>;
        deleteModal: InstanceType<typeof FaceDeleteModal>;
        mergeModal: InstanceType<typeof FaceMergeModal>;
        togetherModal: { open: () => void };
      };
    },

    back() {
      this.$router.go(-1);
    },

    rename() {
      if (this.isReal) this.refs().editModal.open();
    },

    shareThis() {
      shareView(this.$route, this.isTogether ? this.displayName : '');
    },

    showOnlyFirst() {
      this.$router.push({ name: this.$route.name as string, params: { user: this.user, name: this.firstName } });
    },

    /** Cluster id of the current person (the route name is the id for unnamed people) */
    async resolveClusterId(): Promise<number> {
      let clusterId = Number(this.name);
      if (!Number.isInteger(clusterId)) {
        const faces = (await axios.get(API.FACE_LIST('recognize'))).data as { cluster_id: number; name: string }[];
        clusterId = faces.find((f) => f.name === this.name)?.cluster_id ?? NaN;
      }
      if (!Number.isInteger(clusterId)) throw new Error('unknown cluster');
      return clusterId;
    },

    async loadProgress() {
      try {
        const res = await axios.get(generateUrl('/apps/recognize/api/faces/progress'));
        this.progress = res.data;
      } catch (error) {
        this.progress = null;
      }
    },

    async loadPersonAlbum() {
      this.personAlbum = null;
      this.personAlbumAvailable = false;
      if (!this.routeIsRecognize || !this.isReal || this.user !== utils.uid) return;
      try {
        const clusterId = await this.resolveClusterId();
        const res = await axios.get(generateUrl(`/apps/memories/api/person-albums/${clusterId}`));
        this.personAlbumAvailable = !!res.data.available;
        this.personAlbum = res.data.album;
      } catch (error) {
        console.error(error);
      }
    },

    /** Create the auto-updated album of this person and open it */
    async createPersonAlbum() {
      if (this.finding) return;
      this.finding = true;
      try {
        const clusterId = await this.resolveClusterId();
        const res = await axios.post(generateUrl(`/apps/memories/api/person-albums/${clusterId}`), {
          // the album opens the same way this person is being looked at
          prominence: this.sortByProminence,
          subjects: this.onlySubjects,
        });
        this.personAlbum = res.data;
        showSuccess(
          this.t(
            'memories',
            'Album "{name}" created with {n} photos; new photos of this person are added automatically',
            { name: res.data.name, n: res.data.added },
          ),
        );
        this.openPersonAlbum();
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Could not create the album'));
      } finally {
        this.finding = false;
      }
    },

    /** Open the album of this person, ordered the way the person is ordered right now */
    openPersonAlbum() {
      if (!this.personAlbum) return;
      const query: Record<string, string> = {};
      if (this.sortByProminence) query.sort = 'prominence';
      if (this.onlySubjects) query.subjects = '1';
      this.$router.push({ name: 'albums', params: { user: utils.uid!, name: this.personAlbum.name }, query });
    },

    /**
     * Ask Recognize to pull every sufficiently similar unassigned / unnamed face into this
     * person right now (the fork's /api/faces/{id}/find endpoint), then refresh the timeline.
     */
    async findMore() {
      if (this.finding) return;
      this.finding = true;
      try {
        const clusterId = await this.resolveClusterId();
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
        showError(
          this.t('memories', 'Searching for this person failed (is the CristianCasapu Recognize fork installed?)'),
        );
      } finally {
        this.finding = false;
      }
    },

    openUnassigned() {
      this.$router.push({
        name: this.$route.name?.toString(),
        params: {
          user: utils.uid as string,
          name: this.c.FACE_NULL,
        },
      });
    },

    changeShowFaceRect() {
      this.config.show_face_rect = !this.config.show_face_rect;
      this.updateSetting('show_face_rect');
      utils.bus.emit('memories:timeline:hard-refresh', null);
    },
  },
});
</script>
