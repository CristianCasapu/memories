<template>
  <div class="stories-page">
    <div class="top-matter stories-top">
      <NcActions>
        <NcActionButton :aria-label="t('memories', 'Back')" @click="$router.go(-1)">
          {{ t('memories', 'Back') }}
          <template #icon> <BackIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
      <span class="name">{{ t('memories', 'Stories') }}</span>
      <div class="right-actions">
        <NcActions :inline="1">
          <NcActionButton :aria-label="t('memories', 'Refresh')" @click="refresh()" close-after-click>
            {{ t('memories', 'Refresh') }}
            <template #icon> <RefreshIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </div>
    </div>

    <div class="intro">
      <p>
        {{
          t(
            'memories',
            'Your photos, one after the other, full screen. Memories puts a story together for this day in earlier years, for the week that passed, for your events and for the people you photograph often. Select photos anywhere and choose "Create a story" to make your own.',
          )
        }}
      </p>
    </div>

    <XLoadingIcon class="fill-block" v-if="!loaded" />

    <div class="empty" v-else-if="!stories.length">
      {{ t('memories', 'No stories yet. Select some photos and choose "Create a story".') }}
    </div>

    <div class="grid" v-else>
      <div
        class="story"
        v-for="story in stories"
        :key="story.id"
        :class="{ seen: watched(story) }"
        @click="play(story)"
      >
        <div class="ring">
          <img :src="cover(story)" :alt="story.title" loading="lazy" @error="onCoverError($event)" />
          <div class="shade"></div>
          <div class="label">
            <div class="title">{{ story.title }}</div>
            <div class="meta">
              <span v-if="story.subtitle">{{ story.subtitle }}</span>
              <span> · {{ n('memories', '%n photo', '%n photos', story.count) }}</span>
            </div>
          </div>
          <div class="kind" v-if="kindLabel(story)">{{ kindLabel(story) }}</div>
        </div>
      </div>
    </div>

    <StoryViewer :story="playing" @close="playing = null" @ended="onEnded" @deleted="onDeleted" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import XLoadingIcon from '@components/XLoadingIcon.vue';
import StoryViewer from '@components/StoryViewer.vue';

import * as utils from '@services/utils';
import { API } from '@services/API';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import RefreshIcon from 'vue-material-design-icons/Refresh.vue';

import type { IStory } from '@typings';

/** The stories of a person: the ones the server proposed and the ones they made. */
export default defineComponent({
  name: 'Stories',
  components: {
    NcActions,
    NcActionButton,
    XLoadingIcon,
    StoryViewer,
    BackIcon,
    RefreshIcon,
  },

  data: () => ({
    stories: [] as IStory[],
    playing: null as IStory | null,
    loaded: false,
  }),

  async mounted() {
    await this.refresh();

    // the modal sends the person straight to the story they just made
    const play = Number(this.$route.query.play);
    if (play) {
      const story = this.stories.find((s) => s.id === play);
      if (story) this.play(story);
    }
  },

  methods: {
    async refresh() {
      try {
        this.stories = (await axios.get<IStory[]>(API.STORIES())).data;
      } catch (e) {
        console.error(e);
        showError(this.t('memories', 'Failed to load stories.'));
      } finally {
        this.loaded = true;
      }
    },

    play(story: IStory) {
      this.playing = story;
    },

    /** the last story is over: on to the next one, as a phone does */
    onEnded(story: IStory) {
      const next = this.stories[this.stories.findIndex((s) => s.id === story.id) + 1];
      this.playing = next ?? null;
    },

    onDeleted(story: IStory) {
      this.stories = this.stories.filter((s) => s.id !== story.id);
      this.playing = null;
    },

    watched(story: IStory): boolean {
      return story.seen === -1;
    },

    cover(story: IStory): string {
      return utils.getPreviewUrl({
        photo: { fileid: story.cover, etag: story.cover_etag, flag: 0, dayid: 0 },
        sqsize: 512,
      });
    },

    onCoverError(event: Event) {
      (event.target as HTMLImageElement).style.visibility = 'hidden';
    },

    kindLabel(story: IStory): string {
      switch (story.kind) {
        case 'thisday':
          return this.t('memories', 'On this day');
        case 'week':
          return this.t('memories', 'Last week');
        case 'event':
          return this.t('memories', 'Event');
        case 'person':
          return this.t('memories', 'Person');
        default:
          return '';
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.stories-page {
  padding: 0 8px 16px;
}

.top-matter {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 0;

  .name {
    font-size: 1.2em;
    font-weight: 600;
  }

  .right-actions {
    margin-left: auto;
  }
}

.intro {
  max-width: 70ch;
  color: var(--color-text-maxcontrast);
  font-size: 0.9em;
  margin: 0 4px 12px;
}

.empty {
  margin: 24px 4px;
  color: var(--color-text-maxcontrast);
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 12px;

  @media (max-width: 500px) {
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 8px;
  }
}

.story {
  cursor: pointer;

  .ring {
    position: relative;
    aspect-ratio: 9 / 16;
    border-radius: 12px;
    overflow: hidden;
    background: var(--color-background-dark);
    box-shadow: 0 0 0 3px var(--color-primary-element);
    transition: transform 100ms ease-out;
  }

  &.seen .ring {
    box-shadow: 0 0 0 2px var(--color-border);
  }

  &:hover .ring {
    transform: translateY(-2px);
  }

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .shade {
    position: absolute;
    inset: auto 0 0 0;
    height: 55%;
    background: linear-gradient(to top, rgba(0, 0, 0, 0.75), transparent);
  }

  .label {
    position: absolute;
    left: 8px;
    right: 8px;
    bottom: 8px;
    color: #fff;

    .title {
      font-weight: 600;
      font-size: 0.95em;
      line-height: 1.2;
      overflow: hidden;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
    }

    .meta {
      font-size: 0.78em;
      opacity: 0.85;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }

  .kind {
    position: absolute;
    top: 6px;
    left: 6px;
    font-size: 0.7em;
    padding: 2px 6px;
    border-radius: 8px;
    background: rgba(0, 0, 0, 0.5);
    color: #fff;
  }
}
</style>
