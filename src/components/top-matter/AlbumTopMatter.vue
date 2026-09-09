<template>
  <div class="top-matter">
    <NcActions>
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <div class="name">{{ name }}</div>

    <div class="right-actions">
      <NcActions v-if="isAlbumList" :title="t('memories', 'Sorting order')" :forceMenu="true">
        <template #icon>
          <template v-if="isDateSort">
            <SortDateDIcon v-if="isDescending" :size="20" />
            <SortDateAIcon v-else :size="20" />
          </template>
          <template v-else-if="config.album_list_sort & c.ALBUM_SORT_FLAGS.NAME">
            <SlotAlphabeticalDIcon v-if="isDescending" :size="20" />
            <SlotAlphabeticalAIcon v-else :size="20" />
          </template>
          <template v-else>
            <SortIcon :size="20" />
          </template>
        </template>

        <NcActionRadio
          name="sort"
          :aria-label="t('memories', 'Last updated')"
          :model-value="sortField"
          value="last_update"
          @change="changeSort(c.ALBUM_SORT_FLAGS.LAST_UPDATE)"
          close-after-click
        >
          {{ t('memories', 'Last updated') }}
        </NcActionRadio>

        <NcActionRadio
          name="sort"
          :aria-label="t('memories', 'Creation date')"
          :model-value="sortField"
          value="created"
          @change="changeSort(c.ALBUM_SORT_FLAGS.CREATED)"
          close-after-click
        >
          {{ t('memories', 'Creation date') }}
        </NcActionRadio>

        <NcActionRadio
          name="sort"
          :aria-label="t('memories', 'Album name')"
          :model-value="sortField"
          value="name"
          @change="changeSort(c.ALBUM_SORT_FLAGS.NAME)"
          close-after-click
        >
          {{ t('memories', 'Album name') }}
        </NcActionRadio>

        <NcActionSeparator />

        <NcActionRadio
          name="sort-dir"
          :aria-label="isDateSort ? t('memories', 'Oldest first') : t('memories', 'Ascending')"
          :model-value="sortDir"
          value="asc"
          @change="setDescending(false)"
          close-after-click
        >
          {{ isDateSort ? t('memories', 'Oldest first') : t('memories', 'Ascending') }}
        </NcActionRadio>

        <NcActionRadio
          name="sort-dir"
          :aria-label="isDateSort ? t('memories', 'Newest first') : t('memories', 'Descending')"
          :model-value="sortDir"
          value="desc"
          @change="setDescending(true)"
          close-after-click
        >
          {{ isDateSort ? t('memories', 'Newest first') : t('memories', 'Descending') }}
        </NcActionRadio>
      </NcActions>

      <NcActions :inline="isMobile ? 1 : 3">
        <NcActionButton
          :aria-label="t('memories', 'Create new album')"
          :title="t('memories', 'Create new album')"
          @click="refs().createModal.open(false)"
          close-after-click
          v-if="isAlbumList"
        >
          {{ t('memories', 'Create new album') }}
          <template #icon> <PlusIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Share album')"
          :title="t('memories', 'Share album')"
          @click="openShareModal()"
          close-after-click
          v-if="canEditAlbum"
        >
          {{ t('memories', 'Share album') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Download album')"
          :title="t('memories', 'Download album')"
          @click="downloadAlbum()"
          close-after-click
          v-if="!isAlbumList"
        >
          {{ t('memories', 'Download album') }}
          <template #icon> <DownloadIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Edit album details')"
          :title="t('memories', 'Edit album details')"
          @click="refs().createModal.open(true)"
          close-after-click
          v-if="canEditAlbum"
        >
          {{ t('memories', 'Edit album details') }}
          <template #icon> <EditIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          v-if="!isAlbumList"
          :aria-label="t('memories', 'Make a clip from this album')"
          @click="makeClip()"
          close-after-click
        >
          {{ t('memories', 'Make a clip from this album') }}
          <template #icon> <ClipIcon :size="20" /> </template>
        </NcActionButton>
        <!-- album of a person: the same photo order as on the person itself -->
        <NcActionCheckbox
          v-if="isPersonAlbum"
          :aria-label="t('memories', 'Best photos first')"
          :model-value="sortByProminence"
          @change="toggleProminence"
        >
          {{ t('memories', 'Best photos first (large, sharp, well lit, facing the camera)') }}
        </NcActionCheckbox>
        <NcActionCheckbox
          v-if="isPersonAlbum"
          :aria-label="t('memories', 'Only in the foreground')"
          :model-value="onlySubjects"
          @change="toggleSubjects"
        >
          {{ t('memories', 'Only where they are in the foreground (in focus, not in the background)') }}
        </NcActionCheckbox>
        <NcActionButton
          :aria-label="t('memories', 'Remove album')"
          :title="t('memories', 'Remove album')"
          @click="refs().deleteModal.open()"
          close-after-click
          v-if="!isAlbumList"
        >
          {{ t('memories', 'Remove album') }}
          <template #icon> <DeleteIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>

    <AlbumCreateModal ref="createModal" />
    <AlbumDeleteModal ref="deleteModal" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';
import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox';
import NcActionRadio from '@nextcloud/vue/components/NcActionRadio';
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator';

import axios from '@nextcloud/axios';

import AlbumCreateModal from '@components/modal/AlbumCreateModal.vue';
import ClipIcon from 'vue-material-design-icons/MovieOpenPlay.vue';
import AlbumDeleteModal from '@components/modal/AlbumDeleteModal.vue';

import { generateUrl } from '@nextcloud/router';

import { downloadWithHandle } from '@services/dav';
import { API } from '@services/API';
import * as utils from '@services/utils';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import DownloadIcon from 'vue-material-design-icons/Download.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';
import PlusIcon from 'vue-material-design-icons/Plus.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import SortIcon from 'vue-material-design-icons/SortVariant.vue';
import SlotAlphabeticalAIcon from 'vue-material-design-icons/SortAlphabeticalAscending.vue';
import SlotAlphabeticalDIcon from 'vue-material-design-icons/SortAlphabeticalDescending.vue';
import SortDateAIcon from 'vue-material-design-icons/SortCalendarAscending.vue';
import SortDateDIcon from 'vue-material-design-icons/SortCalendarDescending.vue';

export default defineComponent({
  name: 'AlbumTopMatter',
  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    NcActionRadio,
    NcActionSeparator,

    AlbumCreateModal,
    ClipIcon,
    AlbumDeleteModal,

    BackIcon,
    DownloadIcon,
    EditIcon,
    DeleteIcon,
    PlusIcon,
    ShareIcon,
    SortIcon,
    SlotAlphabeticalAIcon,
    SlotAlphabeticalDIcon,
    SortDateAIcon,
    SortDateDIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    /** null until known; whether this album follows a person and how it is ordered */
    personAlbum: null as null | { person: boolean; prominence: boolean; subjects: boolean },
  }),

  computed: {
    /** This album is kept in sync with a recognized person (see PersonAlbums) */
    isPersonAlbum(): boolean {
      return !this.isAlbumList && !!this.personAlbum?.person;
    },

    /** ?sort=prominence: the best photos of the person first inside every day */
    sortByProminence(): boolean {
      return this.$route.query.sort === 'prominence';
    },

    /** ?subjects=1: only the photos the person was photographed in */
    onlySubjects(): boolean {
      return this.$route.query.subjects === '1';
    },

    isAlbumList(): boolean {
      return !this.$route.params.name?.toString();
    },

    canEditAlbum(): boolean {
      return !this.isAlbumList && this.$route.params.user?.toString() === utils.uid;
    },

    name(): string {
      // Album name is displayed in the dynamic top matter (timeline)
      return this.isAlbumList ? this.t('memories', 'Albums') : String();
    },

    isMobile(): boolean {
      return utils.isMobile();
    },

    isDateSort(): boolean {
      return (
        !!(this.config.album_list_sort & this.c.ALBUM_SORT_FLAGS.CREATED) ||
        !!(this.config.album_list_sort & this.c.ALBUM_SORT_FLAGS.LAST_UPDATE)
      );
    },

    isDescending(): boolean {
      return !!(this.config.album_list_sort & this.c.ALBUM_SORT_FLAGS.DESCENDING);
    },

    sortField(): string {
      if (this.config.album_list_sort & this.c.ALBUM_SORT_FLAGS.CREATED) return 'created';
      if (this.config.album_list_sort & this.c.ALBUM_SORT_FLAGS.NAME) return 'name';
      return 'last_update';
    },

    sortDir(): string {
      return this.isDescending ? 'desc' : 'asc';
    },
  },

  mounted() {
    this.openShareFromQuery();
  },

  watch: {
    '$route.query.share'() {
      this.openShareFromQuery();
    },

    '$route.params.name': {
      immediate: true,
      handler() {
        this.loadPersonAlbum();
      },
    },
  },

  methods: {
    refs() {
      return this.$refs as {
        createModal: InstanceType<typeof AlbumCreateModal>;
        deleteModal: InstanceType<typeof AlbumDeleteModal>;
      };
    },

    /** the album's photos, best ones first, into the clip dialog */
    makeClip() {
      const { user, name } = this.$route.params;
      _m.modals.createVideo([], { albumUser: String(user), albumName: String(name), title: String(name) });
    },

    /** /albums/user/name?share=1 (after "Share as album" / person album): open the share dialog */
    openShareFromQuery() {
      if (!this.$route.query.share || this.isAlbumList) return;
      const { user, name } = this.$route.params;
      this.$router.replace({ ...this.$route, query: {} } as any).catch(() => {});
      setTimeout(() => _m.modals.albumShare(String(user), String(name)), 300);
    },

    /**
     * Find out whether this album follows a person, and open it in the order that
     * was picked on the person (unless the URL already says how to order it).
     */
    async loadPersonAlbum() {
      this.personAlbum = null;
      if (this.isAlbumList) return;

      const user = String(this.$route.params.user ?? '');
      const name = String(this.$route.params.name ?? '');
      if (!user || !name) return;

      try {
        const url = API.Q(generateUrl('/apps/memories/api/person-albums/album'), { user, name });
        const { data } = await axios.get(url);
        this.personAlbum = data;
        if (!data.person) return;

        const query = this.$route.query;
        if (query.sort !== undefined || query.subjects !== undefined) {
          // came from the person (or a link): that order becomes the album's order
          if (this.sortByProminence !== data.prominence || this.onlySubjects !== data.subjects) {
            this.storeOrder(this.sortByProminence, this.onlySubjects);
          }
        } else if (data.prominence || data.subjects) {
          this.$router
            .replace({
              ...this.$route,
              query: {
                ...query,
                ...(data.prominence ? { sort: 'prominence' } : {}),
                ...(data.subjects ? { subjects: '1' } : {}),
              },
            } as any)
            .catch(() => {});
        }
      } catch (error) {
        this.personAlbum = null;
      }
    },

    /** Remember the order on the album, so it opens the same way next time */
    async storeOrder(prominence: boolean, subjects: boolean) {
      if (this.personAlbum) {
        this.personAlbum = { person: true, prominence, subjects };
      }

      try {
        await axios.post(generateUrl('/apps/memories/api/person-albums/album'), {
          user: String(this.$route.params.user ?? ''),
          name: String(this.$route.params.name ?? ''),
          prominence,
          subjects,
        });
      } catch (error) {
        console.error(error);
      }
    },

    toggleProminence() {
      const query = { ...this.$route.query };
      if (this.sortByProminence) {
        delete query.sort;
      } else {
        query.sort = 'prominence';
      }
      this.storeOrder(!this.sortByProminence, this.onlySubjects);
      this.$router.replace({ ...this.$route, query } as any).catch(() => {});
    },

    toggleSubjects() {
      const query = { ...this.$route.query };
      if (this.onlySubjects) {
        delete query.subjects;
      } else {
        query.subjects = '1';
      }
      this.storeOrder(this.sortByProminence, !this.onlySubjects);
      this.$router.replace({ ...this.$route, query } as any).catch(() => {});
    },

    back() {
      this.$router.go(-1);
    },

    openShareModal() {
      _m.modals.albumShare(this.$route.params.user?.toString(), this.$route.params.name?.toString());
    },

    async downloadAlbum() {
      const res = await axios.post(
        API.ALBUM_DOWNLOAD(this.$route.params.user?.toString(), this.$route.params.name?.toString()),
      );
      if (res.status === 200 && res.data.handle) {
        downloadWithHandle(res.data.handle);
      }
    },

    /** Set sort choice */
    changeSort(flag: number) {
      const dir = this.config.album_list_sort & this.c.ALBUM_SORT_FLAGS.DESCENDING;
      this.config.album_list_sort = flag | dir;
      this.updateSetting('album_list_sort');
    },

    /** Set sort direction */
    setDescending(val: boolean) {
      if (val) {
        this.config.album_list_sort |= this.c.ALBUM_SORT_FLAGS.DESCENDING;
      } else {
        this.config.album_list_sort &= ~this.c.ALBUM_SORT_FLAGS.DESCENDING;
      }
      this.updateSetting('album_list_sort');
    },
  },
});
</script>
