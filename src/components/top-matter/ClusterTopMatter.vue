<template>
  <div class="top-matter">
    <NcActions>
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>
    <span class="name">{{ name || viewname }}</span>

    <div class="right-actions" v-if="$route.params.name && !routeIsEvents && !routeIsSimilar">
      <NcActions :inline="1">
        <NcActionButton :aria-label="t('memories', 'Share as album')" @click="shareThis()" close-after-click>
          {{ t('memories', 'Share as album') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>

    <div class="right-actions" v-if="routeIsEvents && $route.params.name">
      <NcActions :inline="1">
        <NcActionButton :aria-label="t('memories', 'Share as album')" @click="shareThis()" close-after-click>
          {{ t('memories', 'Share as album') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Save as album')"
          :disabled="cleaning"
          @click="saveEventAsAlbum()"
          close-after-click
        >
          {{ t('memories', 'Save as album') }}
          <template #icon> <AlbumIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>
    <div class="right-actions" v-if="routeIsSimilar && $route.params.name">
      <NcActions :inline="1">
        <NcActionButton
          :aria-label="t('memories', 'Create a video from the burst')"
          :disabled="cleaning"
          @click="burstVideo()"
          close-after-click
        >
          {{ t('memories', 'Create a video from the burst') }}
          <template #icon> <VideoIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          :aria-label="t('memories', 'Keep the largest file, delete the others')"
          :disabled="cleaning"
          @click="cleanupGroup()"
          close-after-click
        >
          {{ t('memories', 'Keep the largest file, delete the others') }}
          <template #icon> <DeleteSweepIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError, showSuccess } from '@nextcloud/dialogs';

import * as strings from '@services/strings';
import * as dav from '@services/dav';
import * as utils from '@services/utils';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import DeleteSweepIcon from 'vue-material-design-icons/DeleteSweep.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import VideoIcon from 'vue-material-design-icons/MovieOpenPlay.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import { shareView } from '@services/view-share';

import type { IPhoto } from '@typings';

export default defineComponent({
  name: 'ClusterTopMatter',
  components: {
    NcActions,
    NcActionButton,
    BackIcon,
    DeleteSweepIcon,
    AlbumIcon,
    VideoIcon,
    ShareIcon,
  },

  data: () => ({
    cleaning: false,
  }),

  computed: {
    viewname(): string {
      return strings.viewName(this.$route.name?.toString() ?? '');
    },

    name(): string | null {
      switch (this.$route.name) {
        case _m.routes.Tags.name:
          return this.t('recognize', this.$route.params.name?.toString());
        default:
          return null;
      }
    },
  },

  methods: {
    back() {
      this.$router.go(-1);
    },

    shareThis() {
      shareView(this.$route, this.name || '');
    },

    /** Automatic event → Photos album with the same photos */
    async saveEventAsAlbum() {
      if (this.cleaning) return;
      this.cleaning = true;
      try {
        const res = await axios.post(generateUrl(`/apps/memories/api/events/${this.$route.params.name}/album`), {});
        showSuccess(
          this.t('memories', 'Album "{name}" saved ({n} photos added)', { name: res.data.name, n: res.data.added }),
        );
        this.$router.push({ name: 'albums', params: { user: utils.uid!, name: res.data.name } });
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Could not save the album'));
      } finally {
        this.cleaning = false;
      }
    },

    /**
     * Similar-photos group: stitch the burst into a short video (saved next to the photos).
     */
    async burstVideo() {
      if (this.cleaning) return;
      this.cleaning = true;
      try {
        const res = await axios.get(generateUrl('/apps/recognize/api/similar'));
        const group = (res.data.groups as { id: string; files: { fileid: number }[] }[]).find(
          (g) => g.id === this.$route.params.name,
        );
        if (!group || group.files.length < 2) {
          showError(this.t('memories', 'Group not found (it may have changed); go back and reload'));
          return;
        }
        _m.modals.createVideo(group.files.map((f) => f.fileid));
      } finally {
        this.cleaning = false;
      }
    },

    /**
     * Similar-photos group: delete every file except the largest one
     * (file list with sizes comes from the Recognize fork API).
     */
    async cleanupGroup() {
      if (this.cleaning) return;
      this.cleaning = true;
      try {
        const res = await axios.get(generateUrl('/apps/recognize/api/similar'));
        const group = (
          res.data.groups as { id: string; files: { fileid: number; name: string; size: number }[] }[]
        ).find((g) => g.id === this.$route.params.name);
        if (!group || group.files.length < 2) {
          showError(this.t('memories', 'Group not found (it may have changed); go back and reload'));
          return;
        }
        const [keep, ...extra] = group.files; // sorted by size, largest first
        if (
          !(await utils.confirmDestructive({
            title: this.t('memories', 'Delete duplicates'),
            message: this.t('memories', 'Keep {name} ({size}) and delete {n} other file(s)?', {
              name: keep.name,
              size: utils.humanFileSize(keep.size),
              n: extra.length,
            }),
            confirm: this.t('memories', 'Delete'),
            confirmClasses: 'error',
            cancel: this.t('memories', 'Cancel'),
          }))
        ) {
          return;
        }
        const photos = extra.map((f) => ({ fileid: f.fileid, basename: f.name }) as IPhoto);
        let deleted = 0;
        for await (const ids of dav.deletePhotos(photos, false)) {
          deleted += ids.filter(Boolean).length;
        }
        showSuccess(this.t('memories', 'Deleted {n} file(s)', { n: deleted }));
        this.$router.replace({ name: 'similar' });
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Could not delete the duplicates'));
      } finally {
        this.cleaning = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.top-matter {
  display: flex;
  align-items: center;

  .name {
    flex: 1;
  }

  .right-actions {
    display: flex;
    align-items: center;
  }
}
</style>
