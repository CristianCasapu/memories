<template>
  <div class="top-matter generic-top-matter">
    <NcActions>
      <NcActionButton :aria-label="t('memories', 'Back')" @click="$router.go(-1)">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <span class="name">{{ viewname }}</span>

    <div class="right-actions">
      <NcActions :inline="1">
        <NcActionButton :aria-label="shareLabel" @click="share()" close-after-click>
          {{ shareLabel }}
          <template #icon> <ShareIcon :size="20" /> </template>
        </NcActionButton>
        <NcActionButton
          v-if="$route.name === 'videos' && !routeIsPublic"
          :aria-label="t('memories', 'Videos in the making')"
          @click="videoJobs()"
          close-after-click
        >
          {{ t('memories', 'Videos in the making') }}
          <template #icon> <ProgressIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import * as strings from '@services/strings';
import { shareView } from '@services/view-share';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import ProgressIcon from 'vue-material-design-icons/ProgressClock.vue';

/** Back + Share on every page that has no dedicated top matter. */
export default defineComponent({
  name: 'GenericTopMatter',
  components: {
    NcActions,
    NcActionButton,
    BackIcon,
    ShareIcon,
    ProgressIcon,
  },

  computed: {
    viewname(): string {
      if (this.routeIsPublic) return this.initstate.shareTitle || strings.viewName(String(this.$route.name)) || '';
      return strings.viewName(String(this.$route.name)) || '';
    },

    shareLabel(): string {
      return this.routeIsPublic ? this.t('memories', 'Share link') : this.t('memories', 'Share as album');
    },
  },

  methods: {
    videoJobs() {
      _m.modals.videoJobs();
    },

    share() {
      shareView(this.$route, this.routeIsPublic ? '' : this.viewname);
    },
  },
});
</script>

<style lang="scss" scoped>
.generic-top-matter {
  display: flex;
  align-items: center;
}
</style>
