<template>
  <div class="admin-section cleanup-section">
    <h2>{{ $options.title }}</h2>

    <p>
      {{
        t(
          'memories',
          'Removes the scratch files Nextcloud and its helpers leave behind (temporary directory, system /tmp leftovers of Nextcloud, PHP, ImageMagick, exiftool and go-vod, the video transcode cache), and optionally runs the trash bin and file version expiration. Runs every {h} hours in the background; you can also run it here or with "occ memories:cleanup".',
          { h: cleanup?.job_interval_hours ?? 6 },
        )
      }}
    </p>

    <div v-if="!cleanup" class="muted">{{ loading ? t('memories', 'Loading …') : t('memories', 'Could not load the cleanup status') }}</div>

    <template v-else>
      <NcCheckboxRadioSwitch :checked.sync="cleanup.config.enabled" @update:checked="save()" type="switch">
        {{ t('memories', 'Automatic cleanup (background job)') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch :checked.sync="cleanup.config.systemTmp" @update:checked="save()" type="switch">
        {{ t('memories', 'Also clean Nextcloud-related files in the system temporary directory') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch :checked.sync="cleanup.config.expireTrash" @update:checked="save()" type="switch">
        {{ t('memories', 'Run the trash bin expiration (Nextcloud retention rules)') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch :checked.sync="cleanup.config.expireVersions" @update:checked="save()" type="switch">
        {{ t('memories', 'Run the file versions expiration (Nextcloud retention rules)') }}
      </NcCheckboxRadioSwitch>

      <div class="fields">
        <NcTextField
          :value.sync="cleanup.config.tmpMaxAgeHours"
          type="number"
          :label="t('memories', 'Delete temporary files older than (hours)')"
          @change="save()"
        />
        <NcTextField
          :value.sync="cleanup.config.vodMaxAgeDays"
          type="number"
          :label="t('memories', 'Delete transcode cache older than (days)')"
          @change="save()"
        />
      </div>

      <div class="buttons">
        <NcButton type="primary" :disabled="busy" @click="run(false)">{{ t('memories', 'Run cleanup now') }}</NcButton>
        <NcButton type="secondary" :disabled="busy" @click="run(true)">{{ t('memories', 'Dry run (report only)') }}</NcButton>
        <NcButton type="tertiary" :disabled="busy" @click="refresh()">{{ t('memories', 'Refresh') }}</NcButton>
        <span class="muted" v-if="busy">{{ t('memories', 'Working …') }}</span>
      </div>

      <div class="result" v-if="lastRun">
        <strong>{{ lastRun.dry_run ? t('memories', 'Dry run') : t('memories', 'Last run') }}</strong>
        · {{ formatTime(lastRun.time) }} ·
        {{ n('memories', '%n file', '%n files', lastRun.files) }} · {{ human(lastRun.bytes) }} · {{ lastRun.duration }} s
        <span v-if="lastRun.errors?.length" class="error"> · {{ n('memories', '%n error', '%n errors', lastRun.errors.length) }}</span>
        <ul v-if="lastRun.errors?.length" class="errors">
          <li v-for="(e, i) in lastRun.errors" :key="i">{{ e }}</li>
        </ul>
      </div>

      <h3>{{ t('memories', 'Targets') }}</h3>
      <div class="table-wrap">
        <table class="targets">
          <thead>
            <tr>
              <th>{{ t('memories', 'What') }}</th>
              <th>{{ t('memories', 'Path') }}</th>
              <th>{{ t('memories', 'Rule') }}</th>
              <th>{{ t('memories', 'Now') }}</th>
              <th>{{ t('memories', 'Removable') }}</th>
              <th>{{ t('memories', 'Free on disk') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="tg in cleanup.targets" :key="tg.id" :class="{ disabled: !tg.enabled }">
              <td>{{ tg.label }}<span v-if="!tg.enabled" class="muted"> ({{ t('memories', 'off') }})</span></td>
              <td><code v-if="tg.path">{{ tg.path }}</code><span v-else class="muted">—</span></td>
              <td class="muted">{{ tg.rule }}</td>
              <td>
                <template v-if="tg.path && tg.exists">{{ n('memories', '%n file', '%n files', tg.count) }}, {{ human(tg.size) }}</template>
                <span v-else-if="tg.path" class="muted">{{ t('memories', 'missing') }}</span>
              </td>
              <td>
                <template v-if="tg.removable_files !== undefined">{{ n('memories', '%n file', '%n files', tg.removable_files) }}, {{ human(tg.removable_bytes) }}</template>
              </td>
              <td><template v-if="tg.free !== null && tg.free !== undefined">{{ human(tg.free) }}</template></td>
            </tr>
          </tbody>
        </table>
      </div>

      <h3>{{ t('memories', 'History') }}</h3>
      <div class="muted" v-if="!cleanup.history.length">{{ t('memories', 'The cleanup has not run yet.') }}</div>
      <div class="table-wrap" v-else>
        <table class="history">
          <thead>
            <tr>
              <th>{{ t('memories', 'When') }}</th>
              <th>{{ t('memories', 'Files') }}</th>
              <th>{{ t('memories', 'Freed') }}</th>
              <th>{{ t('memories', 'Duration') }}</th>
              <th>{{ t('memories', 'Errors') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(h, i) in cleanup.history" :key="i">
              <td>{{ formatTime(h.time) }}</td>
              <td>{{ h.files }}</td>
              <td>{{ human(h.bytes) }}</td>
              <td>{{ h.duration }} s</td>
              <td :class="{ error: h.errors > 0 }">{{ h.errors }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { showError, showSuccess } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');

import { translate as t } from '@services/l10n';
import * as utils from '@services/utils';

import AdminMixin from '../AdminMixin';

type ICleanupStatus = {
  config: Record<string, any>;
  targets: any[];
  last: any;
  history: any[];
  job_interval_hours: number;
};

export default defineComponent({
  name: 'Cleanup',
  title: t('memories', 'Cleanup'),
  components: { NcButton, NcCheckboxRadioSwitch, NcTextField },
  mixins: [AdminMixin],

  data: () => ({
    cleanup: null as ICleanupStatus | null,
    lastRun: null as any,
    loading: false,
    busy: false,
  }),

  mounted() {
    this.refresh();
  },

  methods: {
    async refresh() {
      this.loading = true;
      try {
        const res = await axios.get(generateUrl('/apps/memories/api/admin/cleanup'));
        this.cleanup = res.data;
        this.lastRun = res.data.last;
      } catch (error) {
        console.error(error);
        showError(t('memories', 'Could not load the cleanup status'));
      } finally {
        this.loading = false;
      }
    },

    async save() {
      if (!this.cleanup) return;
      try {
        const res = await axios.put(generateUrl('/apps/memories/api/admin/cleanup/config'), { config: this.cleanup.config });
        this.cleanup.config = res.data;
        showSuccess(t('memories', 'Cleanup settings saved'));
        // rules / previews depend on the settings
        const st = await axios.get(generateUrl('/apps/memories/api/admin/cleanup'));
        this.cleanup.targets = st.data.targets;
      } catch (error) {
        console.error(error);
        showError(t('memories', 'Could not save the cleanup settings'));
      }
    },

    async run(dryRun: boolean) {
      this.busy = true;
      try {
        const res = await axios.post(generateUrl('/apps/memories/api/admin/cleanup/run'), { dry_run: dryRun });
        this.lastRun = res.data;
        showSuccess(
          dryRun
            ? t('memories', 'Dry run: {n} files ({size}) would be removed', { n: res.data.files, size: this.human(res.data.bytes) })
            : t('memories', 'Removed {n} files ({size})', { n: res.data.files, size: this.human(res.data.bytes) }),
        );
        if (!dryRun) {
          const st = await axios.get(generateUrl('/apps/memories/api/admin/cleanup'));
          this.cleanup = st.data;
          this.lastRun = res.data;
        }
      } catch (error: any) {
        console.error(error);
        showError(error?.response?.data?.message || t('memories', 'Cleanup failed'));
      } finally {
        this.busy = false;
      }
    },

    human(bytes: number): string {
      return utils.humanFileSize(bytes || 0);
    },

    formatTime(ts: number): string {
      return ts ? new Date(ts * 1000).toLocaleString() : '';
    },
  },
});
</script>

<style lang="scss" scoped>
.cleanup-section {
  .muted {
    color: var(--color-text-maxcontrast);
  }
  .error {
    color: var(--color-error);
  }
  .fields {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin: 8px 0;
    > * {
      max-width: 320px;
    }
  }
  .buttons {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    margin: 10px 0;
  }
  .result {
    margin: 8px 0;
    .errors {
      margin: 4px 0 0 18px;
      list-style: disc;
    }
  }
  h3 {
    margin-top: 16px;
  }
  .table-wrap {
    overflow-x: auto;
  }
  table {
    border-collapse: collapse;
    width: 100%;
    th,
    td {
      text-align: left;
      padding: 4px 8px;
      border-bottom: 1px solid var(--color-border);
      vertical-align: top;
    }
    tr.disabled td {
      opacity: 0.6;
    }
    code {
      font-size: 0.9em;
    }
  }
}
</style>
