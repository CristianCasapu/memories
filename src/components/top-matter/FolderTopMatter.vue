<template>
  <div class="top-matter">
    <NcActions>
      <NcActionButton :aria-label="t('memories', 'Back')" @click="$router.go(-1)">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>
    <NcBreadcrumbs :key="$route.path">
      <NcBreadcrumb :name="rootFolderName" :to="getRoute([])" :force-icon-text="routeIsPublic">
        <template #icon>
          <ShareIcon v-if="routeIsPublic" :size="20" />
          <HomeIcon v-else :size="20" />
        </template>
      </NcBreadcrumb>
      <NcBreadcrumb v-for="folder in list" :key="folder.idx" :name="folder.text" :to="getRoute(folder.path)" />
    </NcBreadcrumbs>

    <div class="right-actions">
      <!-- Progress bar for upload -->
      <PublicUploadHandler ref="uploadHandler" v-if="allowPublicUpload" />

      <NcActions :inline="3">
        <NcActionButton
          v-if="!routeIsPublic"
          :aria-label="t('memories', 'Share folder')"
          @click="share()"
          close-after-click
        >
          {{ t('memories', 'Share folder') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>

        <NcActionButton
          v-if="!routeIsPublic"
          :aria-label="t('memories', 'Upload files')"
          @click="upload()"
          close-after-click
        >
          {{ t('memories', 'Upload files') }}
          <template #icon> <UploadIcon :size="20" /> </template>
        </NcActionButton>

        <!-- Public upload button -->
        <NcActionButton
          v-if="allowPublicUpload"
          :aria-label="t('memories', 'Upload files')"
          :disabled="uploadHandler()?.processing"
          @click="uploadHandler()?.startUpload()"
        >
          {{ t('memories', 'Upload files') }}
          <template #icon> <UploadIcon :size="20" /> </template>
        </NcActionButton>

        <NcActionButton @click="toggleRecursive" close-after-click>
          {{ recursive ? t('memories', 'Folder view') : t('memories', 'Timeline view') }}
          <template #icon>
            <FoldersIcon v-if="recursive" :size="20" />
            <TimelineIcon v-else :size="20" />
          </template>
        </NcActionButton>

        <NcActionButton
          :aria-label="routeIsPublic ? t('memories', 'Share link') : t('memories', 'Share as album')"
          @click="shareView($route, routeIsPublic ? '' : folderName)"
          close-after-click
        >
          {{ routeIsPublic ? t('memories', 'Share link') : t('memories', 'Share as album') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>

        <NcActionButton
          v-if="!initstate.noDownload"
          :aria-label="t('memories', 'Download all photos')"
          :disabled="downloading"
          @click="downloadFolder()"
          close-after-click
        >
          {{
            recursive ? t('memories', 'Download all photos (with subfolders)') : t('memories', 'Download all photos')
          }}
          <template #icon> <DownloadIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';

import NcBreadcrumbs from '@nextcloud/vue/components/NcBreadcrumbs';
import NcBreadcrumb from '@nextcloud/vue/components/NcBreadcrumb';
import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import PublicUploadHandler from '@components/upload/PublicUploadHandler.vue';

import axios from '@nextcloud/axios';
import { showError, showInfo } from '@nextcloud/dialogs';

import * as utils from '@services/utils';
import { shareView } from '@services/view-share';
import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import * as dav from '@services/dav';
import * as nativex from '@native';
import { API, DaysFilterType } from '@services/API';
import type { IDay, IPhoto } from '@typings';

import HomeIcon from 'vue-material-design-icons/Home.vue';
import DownloadIcon from 'vue-material-design-icons/Download.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import TimelineIcon from 'vue-material-design-icons/ImageMultiple.vue';
import FoldersIcon from 'vue-material-design-icons/FolderMultiple.vue';
import UploadIcon from 'vue-material-design-icons/Upload.vue';

export default defineComponent({
  name: 'FolderTopMatter',

  components: {
    BackIcon,
    NcBreadcrumbs,
    NcBreadcrumb,
    NcActions,
    NcActionButton,
    PublicUploadHandler,
    HomeIcon,
    ShareIcon,
    TimelineIcon,
    FoldersIcon,
    UploadIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    downloading: false,
  }),

  computed: {
    folderName(): string {
      const parts = String(this.$route.params.path || '')
        .split('/')
        .filter(Boolean);
      return parts.length ? parts[parts.length - 1] : this.initstate.shareTitle || this.t('memories', 'Photos');
    },

    list(): {
      text: string;
      path: string[];
      idx: number;
    }[] {
      let path: string[] | string = this.$route.params.path || '';
      if (typeof path === 'string') {
        path = path.split('/');
      }

      return path
        .filter(Boolean) // non-empty
        .map((text, idx, arr) => {
          const path = arr.slice(0, idx + 1);
          return { text, path, idx };
        });
    },

    recursive(): boolean {
      return !!this.$route.query.recursive;
    },

    rootFolderName(): string {
      return this.routeIsPublic ? this.initstate.shareTitle : this.t('memories', 'Home');
    },

    isNative(): boolean {
      return nativex.has();
    },

    allowPublicUpload(): boolean {
      return this.routeIsPublic && this.initstate.allow_upload === true;
    },
  },

  methods: {
    shareView,

    share(): void {
      _m.modals.shareNodeLink(utils.getFolderRoutePath(this.config.folders_path));
    },

    /**
     * Download every photo of the current folder view (recursively when in timeline view)
     * as a single zip, also on public shares. The file list is collected through the
     * same days/day API the timeline uses, so it works for shares without a Files context.
     */
    async downloadFolder(): Promise<void> {
      if (this.downloading) return;
      this.downloading = true;
      try {
        const query: Record<string, string> = {
          [DaysFilterType.FOLDER]: utils.getFolderRoutePath(this.config.folders_path),
        };
        if (this.recursive) {
          query[DaysFilterType.RECURSIVE] = '1';
        }

        const days = (await axios.get<IDay[]>(API.Q(API.DAYS(), query))).data;
        const fileIds = new Set<number>();
        const dayIds = days.map((d) => d.dayid);
        for (let i = 0; i < dayIds.length; i += 50) {
          const chunk = dayIds.slice(i, i + 50);
          const photos = (await axios.get<IPhoto[]>(API.Q(API.DAY(chunk.join(',')), query))).data;
          for (const photo of photos) {
            if (photo.fileid) fileIds.add(photo.fileid);
          }
        }

        if (!fileIds.size) {
          showInfo(this.t('memories', 'No photos to download'));
          return;
        }
        if (fileIds.size >= 100 && !(await utils.dialogs.downloadItems(fileIds.size))) {
          return;
        }
        await dav.downloadFiles(Array.from(fileIds));
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Failed to download files'));
      } finally {
        this.downloading = false;
      }
    },

    upload(): void {
      _m.modals.upload();
    },

    toggleRecursive(): void {
      this.$router.replace({
        query: {
          ...this.$router.currentRoute.value.query,
          recursive: this.recursive ? undefined : String(1),
        },
      });
    },

    getRoute(path: string[]): object {
      return {
        name: this.$route.name,
        params: { ...this.$route.params, path },
        query: this.$route.query,
      };
    },

    uploadHandler(): InstanceType<typeof PublicUploadHandler> | null {
      return (this.$refs.uploadHandler as InstanceType<typeof PublicUploadHandler>) || null;
    },
  },
});
</script>

<style lang="scss" scoped>
.top-matter {
  .breadcrumb {
    min-width: 0;
    height: unset;
    .share-name {
      margin-left: 0.75em;
    }
  }

  .right-actions {
    display: flex;
    align-items: center;
    gap: 10px; // Add spacing between actions and progress bar
  }
}
</style>
