<template>
  <component
    :is="link ? 'router-link' : 'div'"
    draggable="false"
    tabindex="1"
    :aria-label="title"
    class="cluster fill-block"
    :class="{ error, 'has-actions': showActionBar }"
    :to="target"
    @click="click"
  >
    <div class="count-bubble" v-if="counters && data.count">
      <NcCounterBubble> {{ data.count }} </NcCounterBubble>
    </div>
    <div class="name">
      <div class="title">{{ title }}</div>
      <div class="subtitle" v-if="subtitle">{{ subtitle }}</div>
    </div>

    <!-- Phone: a row of buttons under the picture -->
    <div class="tile-actions" v-if="showActionBar" @click.stop.prevent @keydown.stop>
      <button type="button" :title="t('memories', 'Share with a public link')" :disabled="busy" @click="isFace ? sharePersonLink() : shareAlbumLink()">
        <LinkIcon :size="22" />
      </button>
      <button v-if="isFace" type="button" :title="t('memories', 'Create an album of this person')" :disabled="busy" @click="createPersonAlbum">
        <AlbumIcon :size="22" />
      </button>
      <button v-if="isAlbum" type="button" :title="t('memories', 'Share album')" @click="shareAlbum">
        <ShareIcon :size="22" />
      </button>
      <button v-if="isAlbum && owned" type="button" :title="t('memories', 'Edit album')" @click="editAlbum">
        <EditIcon :size="22" />
      </button>
      <button v-if="isAlbum" type="button" :title="t('memories', 'Download album')" @click="downloadAlbum">
        <DownloadIcon :size="22" />
      </button>
      <button v-if="isAlbum && owned" type="button" class="danger" :title="t('memories', 'Delete album')" @click="deleteAlbum">
        <DeleteIcon :size="22" />
      </button>
    </div>

    <!-- Desktop: a menu in the corner, shown on hover (top-left: the counter sits top-right) -->
    <div class="tile-menu" v-if="hasMenu && !showActionBar" @click.stop.prevent @keydown.stop>
      <NcActions :inline="0" :aria-label="t('memories', 'Actions')">
        <NcActionButton :aria-label="t('memories', 'Open')" @click="openAlbum" close-after-click>
          {{ t('memories', 'Open') }}
          <template #icon> <OpenIcon :size="20" /> </template>
        </NcActionButton>

        <!-- person -->
        <template v-if="isFace">
          <NcActionButton :aria-label="t('memories', 'Share with a public link')" :disabled="busy" @click="sharePersonLink" close-after-click>
            {{ t('memories', 'Share with a public link') }}
            <template #icon> <LinkIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionButton :aria-label="t('memories', 'Create an album of this person')" :disabled="busy" @click="createPersonAlbum" close-after-click>
            {{ t('memories', 'Create an album of this person') }}
            <template #icon> <AlbumIcon :size="20" /> </template>
          </NcActionButton>
        </template>

        <!-- album -->
        <template v-if="isAlbum">
        <NcActionButton :aria-label="t('memories', 'Share with a public link')" :disabled="busy" @click="shareAlbumLink" close-after-click>
          {{ t('memories', 'Share with a public link') }}
          <template #icon> <LinkIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton :aria-label="t('memories', 'Share album')" @click="shareAlbum" close-after-click>
          {{ t('memories', 'Share album') }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton v-if="owned" :aria-label="t('memories', 'Edit album')" @click="editAlbum" close-after-click>
          {{ t('memories', 'Edit album') }}
          <template #icon> <EditIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton :aria-label="t('memories', 'Download album')" @click="downloadAlbum" close-after-click>
          {{ t('memories', 'Download album') }}
          <template #icon> <DownloadIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton v-if="owned" :aria-label="t('memories', 'Delete album')" @click="deleteAlbum" close-after-click>
          {{ t('memories', 'Delete album') }}
          <template #icon> <DeleteIcon :size="20" /> </template>
        </NcActionButton>
        </template>
      </NcActions>
    </div>

    <div class="previews fill-block" ref="previews" @click="clickPreview">
      <div class="img-outer" :class="{ plus }">
        <XImg
          draggable="false"
          class="fill-block"
          :class="{ error }"
          :key="data.cluster_id"
          :src="previewUrl"
          :svg-tag="plus"
          @error="failed"
        />
        <div v-if="title || subtitle" class="overlay top-left fill-block" />
      </div>
    </div>
  </component>
</template>

<script lang="ts">
import Vue, { defineComponent, type PropType } from 'vue';

import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js';
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';

import axios from '@nextcloud/axios';
import { API } from '@services/API';

import OpenIcon from 'vue-material-design-icons/OpenInNew.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DownloadIcon from 'vue-material-design-icons/Download.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';
import LinkIcon from 'vue-material-design-icons/LinkVariant.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import { showError, showSuccess } from '@nextcloud/dialogs';

import errorsvg from '@assets/error.svg';
import plussvg from '@assets/plus.svg';

import * as nativex from '@native';
import * as utils from '@services/utils';
import * as dav from '@services/dav';

import type { ICluster } from '@typings';

export default defineComponent({
  name: 'Cluster',
  components: {
    NcCounterBubble,
    NcActions,
    NcActionButton,
    OpenIcon,
    ShareIcon,
    EditIcon,
    DownloadIcon,
    DeleteIcon,
    LinkIcon,
    AlbumIcon,
  },

  data: () => ({
    busy: false,
  }),

  props: {
    data: {
      type: Object as PropType<ICluster>,
      required: true,
    },
    link: {
      type: Boolean,
      default: true,
    },
    counters: {
      type: Boolean,
      default: true,
    },
  },

  emits: {
    click: (item: ICluster) => true,
  },

  computed: {
    previewUrl() {
      if (this.error) return errorsvg;
      if (this.plus) return plussvg;
      return dav.getClusterPreview(this.data);
    },

    title() {
      return this.data.display_name || this.data.name;
    },

    subtitle() {
      if (dav.clusterIs.album(this.data)) {
        return dav.getAlbumSubtitle(this.data);
      }

      return String();
    },

    plus() {
      return this.data.cluster_type === 'plus';
    },

    isAlbum(): boolean {
      return dav.clusterIs.album(this.data);
    },

    /** a named person from Recognize (not the "unassigned" pseudo cluster) */
    isFace(): boolean {
      return (
        this.data.cluster_type === 'recognize'
        && !this.plus
        && String((this.data as any).name ?? '') !== 'NULL'
        && (this.data as any).user_id === utils.uid
      );
    },

    hasMenu(): boolean {
      return this.link && !this.plus && (this.isAlbum || this.isFace);
    },

    /** on a phone the actions live in a bar under the card, where a thumb can reach them */
    showActionBar(): boolean {
      return this.hasMenu && utils.isMobile();
    },

    owned(): boolean {
      return this.isAlbum && (this.data as any).user === utils.uid;
    },

    /** Target URL to navigate to */
    target() {
      if (!this.link || this.plus) return {};
      return dav.getClusterLinkTarget(this.data);
    },

    error() {
      return !!this.data.previewError || (dav.clusterIs.album(this.data) && this.data.last_added_photo <= 0);
    },
  },

  methods: {
    failed() {
      Vue.set(this.data, 'previewError', true);
    },

    click() {
      this.$emit('click', this.data);
    },

    clickPreview() {
      nativex.playTouchSound();
    },

    openAlbum() {
      this.$router.push(dav.getClusterLinkTarget(this.data) as any);
    },

    shareAlbum() {
      const a = this.data as any;
      _m.modals.albumShare(a.user, a.name);
    },

    editAlbum() {
      const a = this.data as any;
      _m.modals.albumEdit(a.user, a.name);
    },

    /** Person → album kept up to date → public link, copied to the clipboard */
    async sharePersonLink() {
      if (this.busy) return;
      this.busy = true;
      try {
        const album = await this.ensurePersonAlbum();
        await this.copyLink(await dav.getOrCreatePublicLink(utils.uid as string, album.name));
      } catch (error: any) {
        console.error(error);
        showError(error?.response?.data?.message || this.t('memories', 'Could not share this person'));
      } finally {
        this.busy = false;
      }
    },

    async createPersonAlbum() {
      if (this.busy) return;
      this.busy = true;
      try {
        const album = await this.ensurePersonAlbum();
        showSuccess(this.t('memories', 'Album "{name}" is kept up to date with this person', { name: album.name }));
        this.$router.push({ name: 'albums', params: { user: utils.uid as string, name: album.name } });
      } catch (error: any) {
        console.error(error);
        showError(error?.response?.data?.message || this.t('memories', 'Could not create the album'));
      } finally {
        this.busy = false;
      }
    },

    /** The automatic album of this person, created on first use */
    async ensurePersonAlbum(): Promise<{ album_id: number; name: string }> {
      const clusterId = Number((this.data as any).cluster_id);
      const res = await axios.post(API.PERSON_ALBUM(clusterId), {});
      return res.data;
    },

    async shareAlbumLink() {
      if (this.busy) return;
      this.busy = true;
      try {
        const a = this.data as any;
        await this.copyLink(await dav.getOrCreatePublicLink(a.user, a.name));
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'Could not create the public link'));
      } finally {
        this.busy = false;
      }
    },

    async copyLink(link: string) {
      try {
        if (nativex.has()) {
          await nativex.shareUrl(link);
          return;
        }
        await navigator.clipboard.writeText(link);
        showSuccess(this.t('memories', 'Public link copied to the clipboard'));
      } catch (error) {
        showSuccess(this.t('memories', 'Public link: {link}', { link }));
      }
    },

    async downloadAlbum() {
      const a = this.data as any;
      const res = await axios.post(API.ALBUM_DOWNLOAD(a.user, a.name));
      if (res.status === 200 && res.data.handle) {
        dav.downloadWithHandle(res.data.handle);
      }
    },

    deleteAlbum() {
      const a = this.data as any;
      _m.modals.albumDelete(a.user, a.name);
    },
  },
});
</script>

<style lang="scss" scoped>
.tile-menu {
  position: absolute;
  top: 4px;
  left: 4px; // the counter bubble owns the top-right corner
  z-index: 200; // above the name (100) and the counter (100)
  opacity: 0;
  transition: opacity 0.15s ease-in-out;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.55);

  .cluster:hover &,
  .cluster:focus-within & {
    opacity: 1;
  }

  @media (hover: none) {
    opacity: 1;
  }

  :deep button {
    color: #fff !important;
  }
}

// the button row under the card (phone)
$actionbar: 44px;
.tile-actions {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: $actionbar;
  z-index: 200;
  display: flex;
  justify-content: space-evenly;
  align-items: center;
  background: var(--color-main-background);
  border-top: 1px solid var(--color-border);

  button {
    width: 40px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 8px;
    background: var(--color-background-dark);
    color: var(--color-main-text);
    padding: 0;
    margin: 0;

    &.danger { color: var(--color-error); }
    &:disabled { opacity: 0.5; }
  }
}

.has-actions {
  :deep .previews { height: calc(100% - #{$actionbar}); }
  .name { bottom: $actionbar; }
  .count-bubble { top: 6px; }
}

.cluster,
.name,
img {
  cursor: pointer;
}

.cluster {
  // Get rid of color of the bubble
  .count-bubble :deep .counter-bubble__counter {
    color: unset !important;
  }

  // Move focus outline inwards
  &:focus {
    outline-offset: -1px;
  }
}

$namemargin: 10px;
.name {
  position: absolute;
  bottom: 0;
  z-index: 100;
  width: calc(100% - 2 * #{$namemargin});
  margin: $namemargin;
  pointer-events: none;

  color: white;
  word-wrap: break-word;
  white-space: normal;
  text-align: center;
  font-size: 1em;
  line-height: 1.1em;

  // multiline ellipsis
  > .title {
    display: -webkit-box;
    -webkit-line-clamp: 5;
    -webkit-box-orient: vertical;
    overflow: hidden;

    // 2px padding prevents the bottom of the text from being cut off
    padding-bottom: 2px;
  }

  // name is below the image
  .cluster--circle & {
    margin: 0 $namemargin;
    min-height: 26px; // alignment
    font-weight: 500;
  }

  .cluster--circle &,
  .cluster--album &,
  .cluster.error & {
    color: unset;

    > .title {
      -webkit-line-clamp: 2;
    }
  }

  .cluster--album & {
    text-align: start;
    margin: 0;
    padding: 0 12px;

    min-height: 50px; // align to top of space
    @media (max-width: 768px) {
      min-height: 54px; // mark#2147915
      padding: 0 6px;
    }

    > .title {
      font-weight: 500;
    }

    > .subtitle {
      color: var(--color-text-lighter);
    }
  }

  @media (max-width: 768px) {
    font-size: 0.9em;
  }

  > .subtitle {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 0.87em;
  }
}

.count-bubble {
  z-index: 100;
  position: absolute;
  top: 6px;
  right: 6px;
  pointer-events: none;
}

.previews {
  z-index: 3;
  line-height: 0;
  position: absolute;
  padding: 2px;
  box-sizing: border-box;

  .cluster--album & {
    padding: 12px;

    @media (max-width: 768px) {
      /**
      * This is incredibly hacky: mark#2147915
      * We want to reduce the padding on mobile. By reducing the vertical padding
      * by double the amount, the size compensates and it looks the same.
      */
      padding: 0 6px;
    }
  }

  > .img-outer {
    position: relative;
    background-color: var(--color-background-dark);
    padding: 0;
    margin: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    display: inline-block;
    cursor: pointer;

    .cluster--rounded &,
    .cluster--album & {
      border-radius: 9px; // rounded corners
    }
    .cluster--album &,
    .cluster--circle & {
      height: unset;
      aspect-ratio: 1; // force square
    }
    .cluster--circle & {
      border-radius: 50%; // circle image
    }

    &.plus {
      background-color: var(--color-primary-element-light);
      color: var(--color-primary);

      :deep svg {
        cursor: pointer;
      }
    }

    > img {
      object-fit: cover;
      padding: 0;
      cursor: pointer;
    }

    > .overlay {
      pointer-events: none;
      overflow: hidden;
      background: linear-gradient(0deg, rgba(0, 0, 0, 0.5) 10%, transparent 40%);

      .cluster.error &,
      .cluster--circle &,
      .cluster--album & {
        display: none;
      }
    }
  }
}
</style>
