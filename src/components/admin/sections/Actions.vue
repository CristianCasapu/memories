<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    <p>{{ t('memories', 'Maintenance actions of the CristianCasapu fork. Each one runs now and reports the result here; the same actions are available as occ commands.') }}</p>

    <div class="actions">
      <div class="action" v-for="a in actions" :key="a.id">
        <NcButton :disabled="busy === a.id" @click="run(a)">{{ a.label }}</NcButton>
        <span class="desc">{{ a.description }}</span>
        <code v-if="results[a.id]">{{ results[a.id] }}</code>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';

import { translate as t } from '@services/l10n';

import AdminMixin from '../AdminMixin';

type IAction = { id: string; label: string; description: string; url: string };

export default defineComponent({
  name: 'Actions',
  title: t('memories', 'Actions'),
  components: { NcButton },
  mixins: [AdminMixin],

  data: () => ({
    busy: '' as string,
    results: {} as Record<string, string>,
    actions: [
      {
        id: 'events',
        label: t('memories', 'Rebuild events'),
        description: t('memories', 'Recompute the automatic events of every user (occ memories:events-rebuild)'),
        url: '/apps/memories/api/admin/events-rebuild',
      },
      {
        id: 'person-albums',
        label: t('memories', 'Sync person albums'),
        description: t('memories', 'Add newly recognized photos to the automatic albums of people (occ memories:person-albums-sync)'),
        url: '/apps/memories/api/admin/person-albums-sync',
      },
      {
        id: 'recap',
        label: t('memories', 'Send me the weekly recap'),
        description: t('memories', 'Test the "Your memories from this week" notification on your own account (occ memories:weekly-recap)'),
        url: '/apps/memories/api/admin/weekly-recap-test',
      },
      {
        id: 'index',
        label: t('memories', 'Index new photos'),
        description: t('memories', 'Run the indexer once now (occ memories:index); normally done by the background job'),
        url: '/apps/memories/api/admin/index',
      },
    ] as IAction[],
  }),

  methods: {
    async run(a: IAction) {
      this.busy = a.id;
      this.results[a.id] = '…';
      try {
        const res = await axios.post(generateUrl(a.url), {});
        this.results[a.id] = res.data.message ?? JSON.stringify(res.data);
      } catch (error: any) {
        console.error(error);
        const msg = error?.response?.data?.message || String(error);
        this.results[a.id] = msg;
        showError(msg);
      } finally {
        this.busy = '';
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 8px;

  .action {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;

    .desc {
      color: var(--color-text-maxcontrast);
    }
    code {
      background: var(--color-background-dark);
      padding: 2px 6px;
      border-radius: 4px;
    }
  }
}
</style>
