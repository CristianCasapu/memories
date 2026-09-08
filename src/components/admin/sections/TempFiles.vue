<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    <p>
      {{
        t(
          'memories',
          'One base directory for everything temporary this app writes: the working folders of clips, exiftool, and the transcode cache of go-vod. Leave it empty to use the temporary directory of Nextcloud (config.php "tempdirectory"). Put it on a large partition: a transcode cache or a clip of a few hundred photos can take gigabytes.',
        )
      }}
    </p>

    <NcTextField
      :label="t('memories', 'Base directory for temporary files (empty: the Nextcloud temporary directory)')"
      :label-visible="true"
      :model-value="config['memories.tmp.base']"
      @change="update('memories.tmp.base', $event.target.value)"
    />
    <NcTextField
      :label="t('memories', 'Transcode cache of go-vod (empty: go-vod/ under the base directory)')"
      :label-visible="true"
      :model-value="config['memories.vod.tempdir']"
      @change="update('memories.vod.tempdir', $event.target.value)"
    />
    <NcTextField
      :label="t('memories', 'exiftool temporary directory (empty: the base directory)')"
      :label-visible="true"
      :model-value="config['memories.exiftool.tmp']"
      @change="update('memories.exiftool.tmp', $event.target.value)"
    />

    <template v-if="status">
      <NcNoteCard :type="status.tmp_writable ? 'success' : 'error'">
        {{
          status.tmp_writable
            ? t('memories', 'Base directory in use: {dir} ({free} GB free)', {
                dir: status.tmp_base,
                free: status.tmp_free_gb < 0 ? '?' : String(status.tmp_free_gb),
              })
            : t('memories', 'The base directory {dir} does not exist or is not writable by the web server.', {
                dir: status.tmp_base,
              })
        }}
      </NcNoteCard>

      <h3>{{ t('memories', 'Where things go (as seen from the web server)') }}</h3>
      <table class="tmp-table">
        <tbody>
          <tr>
            <td>{{ t('memories', 'Clips (working folders)') }}</td>
            <td>
              <code>{{ status.tmp_base }}/clips/</code>
            </td>
          </tr>
          <tr>
            <td>{{ t('memories', 'Transcode cache (go-vod)') }}</td>
            <td>
              <code>{{ status.tmp_vod }}</code>
            </td>
          </tr>
          <tr>
            <td>{{ t('memories', 'exiftool') }}</td>
            <td>
              <code>{{ status.tmp_exiftool }}</code>
            </td>
          </tr>
          <tr>
            <td>{{ t('memories', 'Nextcloud temporary directory') }}</td>
            <td>
              <code>{{ status.tmp_nc }}</code>
            </td>
          </tr>
          <tr>
            <td>{{ t('memories', 'PHP sys_temp_dir') }}</td>
            <td>
              <code>{{ status.tmp_php }}</code>
            </td>
          </tr>
          <tr>
            <td>{{ t('memories', 'PHP upload_tmp_dir') }}</td>
            <td>
              <code>{{ status.tmp_upload || t('memories', '(not set: the PHP default)') }}</code>
            </td>
          </tr>
          <tr>
            <td>{{ t('memories', 'TMPDIR of child processes (ffmpeg, ImageMagick)') }}</td>
            <td>
              <code>{{ status.tmp_env || t('memories', '(not set: /tmp)') }}</code>
            </td>
          </tr>
        </tbody>
      </table>

      <NcNoteCard type="info">
        {{
          t(
            'memories',
            'The PHP values and TMPDIR come from php.ini / the PHP-FPM pool (sys_temp_dir, upload_tmp_dir, env[TMPDIR]) and the cron environment; they cannot be changed from here. Nextcloud itself uses "tempdirectory" in config.php.',
          )
        }}
      </NcNoteCard>
    </template>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from '@services/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'TempFiles',
  title: t('memories', 'Temporary files'),
  mixins: [AdminMixin],
});
</script>

<style lang="scss" scoped>
.tmp-table {
  border-collapse: collapse;
  margin: 6px 0 10px;
  td {
    padding: 3px 12px 3px 0;
    vertical-align: top;
  }
  code {
    word-break: break-all;
  }
}
</style>
