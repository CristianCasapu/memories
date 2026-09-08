<template>
  <div class="mobile-menu" v-if="open" @click.self="close">
    <div class="sheet">
      <div class="head">
        <span class="title">{{ t('memories', 'Menu') }}</span>
        <NcButton variant="tertiary" :aria-label="t('memories', 'Close')" @click="close">
          <template #icon> <CloseIcon :size="20" /> </template>
        </NcButton>
      </div>

      <div class="section-title">{{ t('memories', 'Memories') }}</div>
      <div class="grid">
        <router-link v-for="item in items" :key="item.name" :to="{ name: item.name }" class="entry" @click="close">
          <component :is="item.icon" :size="24" />
          <span>{{ item.title }}</span>
        </router-link>
        <a class="entry" href="#" @click.prevent="settings">
          <CogIcon :size="24" />
          <span>{{ t('memories', 'Settings') }}</span>
        </a>
        <router-link :to="{ name: 'people-review' }" class="entry" @click="close" v-if="hasRecognize">
          <ReviewIcon :size="24" />
          <span>{{ t('memories', 'Review unnamed people') }}</span>
        </router-link>
      </div>

      <div class="section-title" v-if="apps.length">{{ t('memories', 'Nextcloud apps') }}</div>
      <div class="grid apps" v-if="apps.length">
        <a v-for="app in apps" :key="app.id" :href="app.href" class="entry">
          <img :src="app.icon" alt="" />
          <span>{{ app.name }}</span>
        </a>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import { loadState } from '@nextcloud/initial-state';
import NcButton from '@nextcloud/vue/components/NcButton';

import CloseIcon from 'vue-material-design-icons/Close.vue';
import CogIcon from 'vue-material-design-icons/Cog.vue';
import ReviewIcon from 'vue-material-design-icons/AccountCheck.vue';

type NavItem = { name: string; title: string; icon: any };
type CoreApp = { id: string; name: string; href: string; icon: string; order?: number };

/**
 * Mobile: every Memories section (the sidebar of the desktop layout) plus the Nextcloud
 * app menu, which the Nextcloud header does not show on small screens.
 */
export default defineComponent({
  name: 'MobileMenu',
  components: { NcButton, CloseIcon, CogIcon, ReviewIcon },

  props: {
    items: {
      type: Array as PropType<NavItem[]>,
      required: true,
    },
  },

  data: () => ({
    open: false,
    apps: [] as CoreApp[],
  }),

  computed: {
    hasRecognize(): boolean {
      return this.items.some((i) => i.name === 'recognize');
    },
  },

  mounted() {
    try {
      const apps = (loadState('core', 'apps', []) as CoreApp[]) || [];
      this.apps = apps.filter((a) => a.id !== 'memories').sort((a, b) => (a.order ?? 0) - (b.order ?? 0));
    } catch {
      this.apps = [];
    }
  },

  methods: {
    toggle() {
      this.open = !this.open;
    },
    close() {
      this.open = false;
    },
    settings() {
      this.close();
      _m.modals.showSettings();
    },
  },
});
</script>

<style lang="scss" scoped>
.mobile-menu {
  position: fixed;
  inset: 0;
  z-index: 3000;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: flex-end;

  .sheet {
    width: 100%;
    max-height: 85vh;
    overflow-y: auto;
    background: var(--color-main-background);
    border-radius: 16px 16px 0 0;
    padding: 8px 12px calc(16px + env(safe-area-inset-bottom));
  }

  .head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    .title {
      font-size: 1.2em;
      font-weight: 500;
      padding-left: 4px;
    }
  }

  .section-title {
    color: var(--color-text-maxcontrast);
    font-size: 0.85em;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin: 10px 4px 4px;
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
    gap: 6px;

    .entry {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
      padding: 10px 4px;
      border-radius: 10px;
      color: var(--color-main-text);
      text-align: center;
      font-size: 0.85em;
      background: var(--color-background-hover);

      &.router-link-active {
        background: var(--color-primary-element-light);
      }

      img {
        width: 26px;
        height: 26px;
        filter: var(--background-invert-if-dark);
      }
      span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 100%;
      }
    }
  }
}
</style>
