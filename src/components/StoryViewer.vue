<template>
  <div
    class="story-viewer"
    v-if="story"
    @pointerdown="down"
    @pointerup="up"
    @pointercancel="up"
    @pointerleave="up"
    @contextmenu.prevent
  >
    <div class="bars">
      <div class="bar" v-for="(photo, i) in photos" :key="photo.fileid">
        <span :style="{ width: i < index ? '100%' : i === index ? progress + '%' : '0%' }"></span>
      </div>
    </div>

    <div class="head">
      <div class="text">
        <div class="title">{{ story.title }}</div>
        <div class="subtitle" v-if="story.subtitle">{{ story.subtitle }}</div>
      </div>
      <div class="buttons">
        <NcActions :inline="1">
          <NcActionButton :aria-label="t('memories', 'Open photo')" @click="openPhoto()" close-after-click>
            {{ t('memories', 'Open photo') }}
            <template #icon> <OpenIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionButton :aria-label="t('memories', 'Delete')" @click="remove()" close-after-click>
            {{ t('memories', 'Delete') }}
            <template #icon> <DeleteIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
        <button class="close" :aria-label="t('memories', 'Close')" @click="close()">
          <CloseIcon :size="24" />
        </button>
      </div>
    </div>

    <div class="stage">
      <img
        v-for="(photo, i) in window"
        :key="photo.fileid"
        :src="src(photo)"
        :class="{ current: i === 0, paused }"
        :alt="photo.basename"
        draggable="false"
        @load="onLoad(photo)"
        @error="onError(photo)"
      />
      <XLoadingIcon class="loader" v-if="!loaded" />
    </div>

    <div class="foot" v-if="current">
      <span>{{ when(current) }}</span>
      <span class="count">{{ index + 1 }} / {{ photos.length }}</span>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import XLoadingIcon from '@components/XLoadingIcon.vue';

import * as utils from '@services/utils';
import { confirmDestructive } from '@services/utils/dialog';
import { API } from '@services/API';

import CloseIcon from 'vue-material-design-icons/Close.vue';
import OpenIcon from 'vue-material-design-icons/OpenInNew.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';

import type { IPhoto, IStory } from '@typings';

/** How long one photo stays on the screen. */
const DURATION = 4000;

/** How long a press has to last before it counts as "hold to pause" and not as a tap. */
const HOLD = 200;

/**
 * One story, full screen: the photos follow one another by themselves, a press holds them,
 * a tap on the left or the right side steps back or forward, and Escape closes.
 */
export default defineComponent({
  name: 'StoryViewer',
  components: {
    NcActions,
    NcActionButton,
    XLoadingIcon,
    CloseIcon,
    OpenIcon,
    DeleteIcon,
  },

  props: {
    story: {
      type: Object as () => IStory | null,
      default: null,
    },
  },

  emits: ['close', 'deleted', 'ended'],

  data: () => ({
    photos: [] as IPhoto[],
    index: 0,
    progress: 0,
    paused: false,
    ready: new Set<number>(),
    timer: null as number | null,
    since: 0,
    elapsed: 0,
    pressed: 0,
    pressX: 0,
  }),

  computed: {
    current(): IPhoto | null {
      return this.photos[this.index] ?? null;
    },

    /** the photo on the screen is in the browser: until then nothing moves */
    loaded(): boolean {
      return !!this.current && this.ready.has(this.current.fileid);
    },

    /** the photo on the screen and the next one, so the browser has it ready */
    window(): IPhoto[] {
      return this.photos.slice(this.index, this.index + 2);
    },
  },

  watch: {
    story: {
      immediate: true,
      handler() {
        this.load();
      },
    },
  },

  mounted() {
    window.addEventListener('keydown', this.key);
  },

  beforeUnmount() {
    window.removeEventListener('keydown', this.key);
    this.stop();
  },

  methods: {
    async load() {
      this.stop();
      this.photos = [];
      this.index = 0;
      this.progress = 0;
      this.ready = new Set();
      if (!this.story) return;

      try {
        const res = await axios.get<IPhoto[]>(API.STORY(this.story.id));
        this.photos = res.data;
        if (!this.photos.length) return this.close();

        // pick up where the person left off, unless the story was watched to the end
        const seen = this.story.seen > 0 ? this.photos.findIndex((p) => p.fileid === this.story!.seen) : -1;
        this.index = seen >= 0 && seen < this.photos.length - 1 ? seen + 1 : 0;
        this.restart();
      } catch (e) {
        showError(this.t('memories', 'Failed to load the story.'));
        this.close();
      }
    },

    onLoad(photo: IPhoto) {
      this.ready.add(photo.fileid);
      // the clock only starts once the photo is on the screen
      if (this.current?.fileid === photo.fileid) this.since = Date.now();
    },

    onError(photo: IPhoto) {
      // a photo that cannot be shown must not stop the story
      this.photos = this.photos.filter((p) => p.fileid !== photo.fileid);
      if (!this.photos.length) return this.close();
      if (this.index >= this.photos.length) this.index = this.photos.length - 1;
      this.restart();
    },

    restart() {
      this.stop();
      this.elapsed = 0;
      this.progress = 0;
      this.since = Date.now();
      this.timer = window.setInterval(this.tick, 50);
    },

    tick() {
      if (this.paused) return;
      if (!this.loaded) {
        // still waiting for the photo: hold the clock where it is
        this.since = Date.now();

        return;
      }
      const done = this.elapsed + (Date.now() - this.since);
      this.progress = Math.min(100, (done / DURATION) * 100);
      if (done >= DURATION) this.next();
    },

    stop() {
      if (this.timer !== null) window.clearInterval(this.timer);
      this.timer = null;
    },

    pause() {
      if (this.paused) return;
      this.elapsed += Date.now() - this.since;
      this.paused = true;
    },

    resume() {
      if (!this.paused) return;
      this.since = Date.now();
      this.paused = false;
    },

    next() {
      this.mark();
      if (this.index >= this.photos.length - 1) {
        this.$emit('ended', this.story);
        return;
      }
      this.index++;
      this.restart();
    },

    previous() {
      if (this.index === 0) {
        this.restart();
        return;
      }
      this.index--;
      this.restart();
    },

    close() {
      this.mark();
      this.stop();
      this.$emit('close');
    },

    /** remember where we stopped (the last photo means "watched") */
    mark() {
      if (!this.story || !this.current) return;
      const fileid = this.index >= this.photos.length - 1 ? -1 : this.current.fileid;
      this.story.seen = fileid;
      axios.post(API.STORY_SEEN(this.story.id), { fileid }).catch(() => {});
    },

    key(e: KeyboardEvent) {
      switch (e.key) {
        case 'Escape':
          return this.close();
        case 'ArrowRight':
          return this.next();
        case 'ArrowLeft':
          return this.previous();
        case ' ':
          e.preventDefault();
          return this.paused ? this.resume() : this.pause();
      }
    },

    down(e: PointerEvent) {
      this.pressed = Date.now();
      this.pressX = e.clientX;
      window.setTimeout(() => {
        if (this.pressed) this.pause();
      }, HOLD);
    },

    up(e: PointerEvent) {
      const held = Date.now() - this.pressed;
      this.pressed = 0;
      this.resume();
      if (held < HOLD && e.type === 'pointerup') {
        // a tap: the side of the screen decides
        const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
        if (e.clientX - rect.left < rect.width / 3) this.previous();
        else this.next();
      }
    },

    src(photo: IPhoto): string {
      return utils.getPreviewUrl({ photo, size: 'screen' });
    },

    when(photo: IPhoto): string {
      return utils.getLongDateStr(utils.dayIdToDate(photo.dayid), true);
    },

    openPhoto() {
      if (!this.current) return;
      this.close();
      this.$router.push({ path: '/', query: { fileid: String(this.current.fileid) } });
    },

    async remove() {
      if (!this.story) return;
      this.pause();
      if (
        !(await confirmDestructive({
          title: this.t('memories', 'Delete story'),
          message: this.t('memories', 'The photos stay where they are; only the story is removed.'),
          confirm: this.t('memories', 'Delete'),
          confirmClasses: 'error',
          cancel: this.t('memories', 'Cancel'),
        }))
      ) {
        this.resume();
        return;
      }

      try {
        await axios.delete(API.STORY(this.story.id));
        this.$emit('deleted', this.story);
      } catch (e) {
        showError(this.t('memories', 'Failed to delete the story.'));
        this.resume();
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.story-viewer {
  position: fixed;
  inset: 0;
  z-index: 3000;
  background: #000;
  color: #fff;
  user-select: none;
  touch-action: none;
  overscroll-behavior: contain;
}

.bars {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  display: flex;
  gap: 3px;
  padding: 8px 10px 0;
  z-index: 3;

  .bar {
    flex: 1 1 0;
    height: 3px;
    border-radius: 3px;
    background: rgba(255, 255, 255, 0.35);
    overflow: hidden;

    > span {
      display: block;
      height: 100%;
      background: #fff;
      transition: width 60ms linear;
    }
  }
}

.head {
  position: absolute;
  top: 18px;
  left: 0;
  right: 0;
  z-index: 3;
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 6px 8px 0 14px;
  background: linear-gradient(to bottom, rgba(0, 0, 0, 0.55), transparent);

  .text {
    flex: 1 1 auto;
    min-width: 0;
  }

  .title {
    font-weight: 600;
    font-size: 1.05em;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .subtitle {
    font-size: 0.85em;
    opacity: 0.85;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .buttons {
    display: flex;
    align-items: center;
    flex: 0 0 auto;

    :deep(.action-item__menutoggle),
    .close {
      color: #fff;
    }
  }

  .close {
    background: none;
    border: none;
    color: #fff;
    cursor: pointer;
    padding: 6px;
    line-height: 0;
  }
}

.stage {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;

  img {
    position: absolute;
    max-width: 100%;
    max-height: 100%;
    width: auto;
    height: auto;
    object-fit: contain;
    opacity: 0;
    pointer-events: none;

    &.current {
      opacity: 1;
      animation: story-zoom 8s ease-out both;

      &.paused {
        animation-play-state: paused;
      }
    }
  }

  .loader {
    z-index: 2;
  }
}

@keyframes story-zoom {
  from {
    transform: scale(1);
  }
  to {
    transform: scale(1.06);
  }
}

.foot {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 3;
  display: flex;
  justify-content: space-between;
  padding: 24px 16px 14px;
  font-size: 0.85em;
  opacity: 0.85;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.55), transparent);
  pointer-events: none;
}
</style>
